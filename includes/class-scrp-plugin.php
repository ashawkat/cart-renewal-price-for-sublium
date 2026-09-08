<?php
/**
 * Main plugin class — price sync only.
 *
 * Creation-safe: do not mutate Sublium recurring carts, plan meta, or item
 * payloads during checkout. Those paths can leave recurring carts uncached
 * and Sublium then creates no subscription.
 *
 * Checkout HTML may still show the cart line. Stored renewal totals are
 * copied from the parent order after the subscription already exists.
 *
 * @package Cart_Renewal_Price_For_Sublium
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SCRP_Plugin
 */
class SCRP_Plugin {

	/**
	 * Subscribe & Save plan type in Sublium.
	 *
	 * @var int
	 */
	const PLAN_TYPE_SUBSCRIBE_AND_SAVE = 1;

	/**
	 * @var SCRP_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Subscription IDs already synced this request.
	 *
	 * @var array<int, bool>
	 */
	private $synced_subscription_ids = array();

	/**
	 * @return SCRP_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Display only — does not feed subscription create().
		add_filter( 'sublium_wcs_woocommerce_cart_item_total', array( $this, 'filter_recurring_total_display' ), 20, 2 );

		// After the subscription exists. Fail-open: never throw out to Sublium.
		add_action( 'sublium_wcs_subscription_created', array( $this, 'on_subscription_created' ), 25, 2 );
		add_filter( 'sublium_wcs_subscription_created', array( $this, 'filter_subscription_created' ), 25, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order_processed' ), 60, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_order_processed' ), 60, 1 );
	}

	/**
	 * Rewrite renewal price HTML from main-cart plan lines when possible.
	 *
	 * @param string  $price_html     Formatted HTML.
	 * @param WC_Cart $recurring_cart Recurring cart.
	 * @return string
	 */
	public function filter_recurring_total_display( $price_html, $recurring_cart ) {
		try {
			if ( ! $recurring_cart || ! is_object( $recurring_cart ) || ! method_exists( $recurring_cart, 'get_cart' ) ) {
				return $price_html;
			}

			$total = 0.0;
			$found = false;

			foreach ( $recurring_cart->get_cart() as $cart_item ) {
				if ( empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
					continue;
				}

				if ( $this->should_skip_cart_item( $cart_item ) ) {
					continue;
				}

				$unit = $this->get_main_cart_unit_price_for_product( $cart_item['data'] );

				if ( null === $unit || $unit <= 0 ) {
					if ( isset( $cart_item['line_subtotal'] ) ) {
						$total += (float) $cart_item['line_subtotal'];
						$found  = true;
					}
					continue;
				}

				$qty    = isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 1;
				$total += $unit * max( $qty, 0 );
				$found  = true;
			}

			if ( ! $found || $total <= 0 ) {
				return $price_html;
			}

			return wc_price( $total );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			return $price_html;
		}
	}

	/**
	 * @param mixed         $subscription Subscription.
	 * @param WC_Order|null $order        Parent order.
	 * @return void
	 */
	public function on_subscription_created( $subscription, $order = null ) {
		try {
			$this->sync_subscription_from_parent_order( $subscription, $order );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			return;
		}
	}

	/**
	 * Must always return the subscription so later filters still receive it.
	 *
	 * @param mixed         $subscription Subscription.
	 * @param WC_Order|null $order        Parent order.
	 * @return mixed
	 */
	public function filter_subscription_created( $subscription, $order = null ) {
		try {
			$this->sync_subscription_from_parent_order( $subscription, $order );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			return $subscription;
		}

		return $subscription;
	}

	/**
	 * Backup: order is saved, subscriptions should exist.
	 *
	 * @param int|WC_Order $order Order.
	 * @return void
	 */
	public function on_order_processed( $order ) {
		try {
			$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
			if ( ! $order ) {
				return;
			}

			foreach ( $this->get_subscription_ids_for_order( $order ) as $subscription_id ) {
				$this->sync_subscription_from_parent_order( $subscription_id, $order );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			return;
		}
	}

	/**
	 * Copy parent order line_subtotal onto matching subscription product rows.
	 *
	 * @param mixed         $subscription Subscription object or ID.
	 * @param WC_Order|null $order        Parent order.
	 * @return void
	 */
	private function sync_subscription_from_parent_order( $subscription, $order = null ) {
		try {
			$subscription = $this->normalize_subscription( $subscription );
			if ( ! $subscription ) {
				return;
			}

			$subscription_id = method_exists( $subscription, 'get_id' ) ? (int) $subscription->get_id() : 0;
			if ( $subscription_id && isset( $this->synced_subscription_ids[ $subscription_id ] ) ) {
				return;
			}

			if ( method_exists( $subscription, 'get_plan_type' ) ) {
				$plan_type = (int) $subscription->get_plan_type();
				if ( $plan_type && self::PLAN_TYPE_SUBSCRIBE_AND_SAVE !== $plan_type ) {
					return;
				}
			}

			if ( ! $order instanceof WC_Order ) {
				$parent_id = 0;
				if ( method_exists( $subscription, 'get_parent_order_id' ) ) {
					$parent_id = (int) $subscription->get_parent_order_id();
				}
				$order = $parent_id ? wc_get_order( $parent_id ) : null;
			}

			if ( ! $order instanceof WC_Order ) {
				return;
			}

			$items = array();
			if ( method_exists( $subscription, 'get_subscription_items' ) ) {
				$items = $subscription->get_subscription_items();
			}

			if ( empty( $items ) || ! is_array( $items ) ) {
				return;
			}

			$changed = false;

			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || empty( $item['id'] ) ) {
					continue;
				}

				$item_type = isset( $item['item_type'] ) ? (int) $item['item_type'] : 1;
				if ( 1 !== $item_type ) {
					continue;
				}

				$item_data  = $this->decode_item_data( isset( $item['item_data'] ) ? $item['item_data'] : array() );
				$order_item = $this->find_matching_order_item( $order, $item, $item_data );

				if ( ! $order_item ) {
					continue;
				}

				$line = (float) $order_item->get_subtotal();
				if ( $line <= 0 ) {
					continue;
				}

				$current = isset( $item_data['total'] ) ? (float) $item_data['total'] : 0.0;
				if ( $current > 0 && $line >= $current ) {
					continue;
				}

				$item_data['total']    = $line;
				$item_data['subtotal'] = $line;

				if ( ! method_exists( $subscription, 'update_item' ) ) {
					continue;
				}

				$subscription->update_item(
					$item['id'],
					array(
						'item_data'   => wp_json_encode( $item_data ),
						'base_totals' => $line,
					),
					array( '%s', '%f' )
				);
				$changed = true;
			}

			if ( ! $changed ) {
				return;
			}

			if ( class_exists( '\Sublium_WCS\Includes\Controller\Subscriptions\Subscriptionmodifier' ) ) {
				$modifier = new \Sublium_WCS\Includes\Controller\Subscriptions\Subscriptionmodifier( $subscription );
				if ( method_exists( $modifier, 'recalculate_totals' ) ) {
					$modifier->recalculate_totals();
				}
			} elseif ( method_exists( $subscription, 'update_totals' ) && method_exists( $subscription, 'save' ) ) {
				$new_total = 0.0;
				foreach ( $subscription->get_subscription_items() as $row ) {
					$type = isset( $row['item_type'] ) ? (int) $row['item_type'] : 0;
					if ( 1 !== $type && 2 !== $type && 4 !== $type ) {
						continue;
					}
					$data       = $this->decode_item_data( isset( $row['item_data'] ) ? $row['item_data'] : array() );
					$new_total += isset( $data['total'] ) ? (float) $data['total'] : ( isset( $data['amount'] ) ? (float) $data['amount'] : 0.0 );
				}
				if ( $new_total > 0 ) {
					$subscription->update_totals( $new_total );
					$subscription->save();
				}
			}

			if ( $subscription_id ) {
				$this->synced_subscription_ids[ $subscription_id ] = true;
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			return;
		}
	}

	/**
	 * @param mixed $item_data Item data JSON or array.
	 * @return array
	 */
	private function decode_item_data( $item_data ) {
		if ( is_array( $item_data ) ) {
			return $item_data;
		}

		if ( is_string( $item_data ) && '' !== $item_data ) {
			$decoded = json_decode( $item_data, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return array();
	}

	/**
	 * @param WC_Order $order     Parent order.
	 * @param array    $sub_item  Subscription item row.
	 * @param array    $item_data Decoded item_data.
	 * @return WC_Order_Item_Product|null
	 */
	private function find_matching_order_item( $order, $sub_item, $item_data ) {
		$product_id   = (int) ( $item_data['product_id'] ?? $sub_item['product_id'] ?? 0 );
		$variation_id = (int) ( $item_data['variation_id'] ?? $sub_item['variation_id'] ?? 0 );

		foreach ( $order->get_items( 'line_item' ) as $order_item ) {
			if ( ! $order_item instanceof WC_Order_Item_Product ) {
				continue;
			}

			if ( $this->is_giveaway_order_item( $order_item ) ) {
				continue;
			}

			$oid = (int) $order_item->get_product_id();
			$vid = (int) $order_item->get_variation_id();

			if ( $variation_id > 0 && $vid === $variation_id ) {
				return $order_item;
			}

			if ( $product_id > 0 && $oid === $product_id ) {
				return $order_item;
			}
		}

		return null;
	}

	/**
	 * @param WC_Order_Item_Product $item Order item.
	 * @return bool
	 */
	private function is_giveaway_order_item( $item ) {
		if ( 'wt_give_away_product' === (string) $item->get_meta( 'free_product', true ) ) {
			return true;
		}

		foreach ( array( '_fkcart_free_gift', '_tikva_free_gift' ) as $key ) {
			$value = $item->get_meta( $key, true );
			if ( ! empty( $value ) && 'no' !== $value && '0' !== (string) $value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param mixed $subscription Subscription.
	 * @return object|null
	 */
	private function normalize_subscription( $subscription ) {
		if ( is_object( $subscription ) ) {
			return $subscription;
		}

		$id = absint( $subscription );
		if ( ! $id ) {
			return null;
		}

		if ( function_exists( 'sublium_get_subscription' ) ) {
			$object = sublium_get_subscription( $id );
			if ( $object ) {
				return $object;
			}
		}

		if ( class_exists( '\Sublium_WCS\Includes\Controller\Subscriptions\Subscription' ) ) {
			try {
				return new \Sublium_WCS\Includes\Controller\Subscriptions\Subscription( $id );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				return null;
			}
		}

		return null;
	}

	/**
	 * @param WC_Order $order Order.
	 * @return array<int>
	 */
	private function get_subscription_ids_for_order( $order ) {
		$ids = array();

		$parent_meta = $order->get_meta( '_sublium_wcs_parent_order', true );
		if ( ! empty( $parent_meta ) ) {
			$decoded = is_array( $parent_meta ) ? $parent_meta : json_decode( $parent_meta, true );
			if ( is_array( $decoded ) ) {
				$ids = array_merge( $ids, $decoded );
			}
		}

		foreach ( $order->get_meta( '_sublium_wcs_subscription_id', false ) as $meta ) {
			if ( is_object( $meta ) && isset( $meta->value ) ) {
				$ids[] = $meta->value;
			} elseif ( is_numeric( $meta ) ) {
				$ids[] = $meta;
			}
		}

		$single = $order->get_meta( '_sublium_wcs_subscription_id', true );
		if ( $single ) {
			$ids[] = $single;
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Main cart per-unit line_subtotal for a plan product.
	 *
	 * @param WC_Product $product Product.
	 * @return float|null
	 */
	private function get_main_cart_unit_price_for_product( $product ) {
		$cart = $this->get_main_cart();
		if ( ! $cart || ! $product instanceof WC_Product ) {
			return null;
		}

		$contents = $this->get_cart_contents( $cart );
		if ( empty( $contents ) ) {
			return null;
		}

		foreach ( $contents as $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}

			if ( empty( $cart_item['sublium_wcs_plan'] ) ) {
				continue;
			}

			if ( $this->should_skip_cart_item( $cart_item ) ) {
				continue;
			}

			if ( ! $this->product_matches_cart_item( $product, $cart_item ) ) {
				continue;
			}

			$qty = isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 0;
			if ( $qty <= 0 || ! isset( $cart_item['line_subtotal'] ) ) {
				continue;
			}

			$unit = (float) $cart_item['line_subtotal'] / $qty;
			if ( $unit <= 0 ) {
				continue;
			}

			return $unit;
		}

		return null;
	}

	/**
	 * @param array $cart_item Cart item.
	 * @return bool
	 */
	private function should_skip_cart_item( $cart_item ) {
		if ( ! is_array( $cart_item ) ) {
			return true;
		}

		if ( $this->is_giveaway_cart_item( $cart_item ) ) {
			return true;
		}

		if ( ! empty( $cart_item['sublium_upgrade_data'] ) || ! empty( $cart_item['sublium_upgrade'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @param WC_Product $product   Product being priced.
	 * @param array      $cart_item Main cart item.
	 * @return bool
	 */
	private function product_matches_cart_item( $product, $cart_item ) {
		$product_id        = (int) $product->get_id();
		$item_product_id   = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
		$item_variation_id = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
		$item_data_id      = ( isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product )
			? (int) $cart_item['data']->get_id()
			: 0;

		if ( $product->is_type( 'variation' ) ) {
			return ( $product_id === $item_variation_id || $product_id === $item_data_id );
		}

		if ( $product_id === $item_data_id ) {
			return true;
		}

		return ( $product_id === $item_product_id );
	}

	/**
	 * @return WC_Cart|null
	 */
	private function get_main_cart() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return null;
		}

		return WC()->cart;
	}

	/**
	 * @param WC_Cart $cart Cart.
	 * @return array
	 */
	private function get_cart_contents( $cart ) {
		if ( isset( $cart->cart_contents ) && is_array( $cart->cart_contents ) ) {
			return $cart->cart_contents;
		}

		if ( method_exists( $cart, 'get_cart_contents' ) ) {
			$contents = $cart->get_cart_contents();
			return is_array( $contents ) ? $contents : array();
		}

		return array();
	}

	/**
	 * @param array $cart_item Cart item.
	 * @return bool
	 */
	private function is_giveaway_cart_item( $cart_item ) {
		if ( ! is_array( $cart_item ) ) {
			return false;
		}

		if ( isset( $cart_item['free_product'] ) && 'wt_give_away_product' === $cart_item['free_product'] ) {
			return true;
		}

		if ( ! empty( $cart_item['_fkcart_free_gift'] ) || ! empty( $cart_item['_tikva_free_gift'] ) ) {
			return true;
		}

		return false;
	}
}
