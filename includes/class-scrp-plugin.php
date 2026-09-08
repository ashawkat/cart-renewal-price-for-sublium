<?php
/**
 * Main plugin class — price sync only.
 *
 * Fail-open: never throw, never touch plan meta / groups / assignment.
 * A thrown filter during Sublium recurring-cart totals leaves recurring carts
 * uncached, and Sublium then creates no subscription.
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
		// Recurring cart unit price (feeds checkout renewal + subscription totals).
		add_filter( 'sublium_wcs_subscription_price', array( $this, 'sync_recurring_unit_price' ), 20, 4 );

		// Checkout renewal display fallback.
		add_filter( 'sublium_wcs_woocommerce_cart_item_total', array( $this, 'filter_recurring_total_display' ), 20, 2 );

		// When Sublium builds subscription line items from the recurring cart.
		add_filter( 'sublium_wcs_create_subscription_item_data', array( $this, 'sync_subscription_item_data' ), 20, 4 );
	}

	/**
	 * During recurring totals, use main cart line_subtotal / qty.
	 *
	 * @param float      $price            Calculated unit price.
	 * @param WC_Product $product          Product.
	 * @param mixed      $plan             Plan object.
	 * @param string     $calculation_type Sublium calculation type.
	 * @return float
	 */
	public function sync_recurring_unit_price( $price, $product, $plan = null, $calculation_type = 'none' ) {
		try {
			if ( 'recurring_total' !== $calculation_type ) {
				return $price;
			}

			if ( ! $this->is_subscribe_and_save_plan( $plan ) ) {
				return $price;
			}

			if ( ! $product instanceof WC_Product || ! $this->get_main_cart() ) {
				return $price;
			}

			$original  = (float) $price;
			$cart_unit = $this->get_main_cart_unit_price_for_product( $product );

			if ( ! $this->should_replace_price( $original, $cart_unit ) ) {
				return $price;
			}

			return (float) $cart_unit;
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			return $price;
		}
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
	 * Align subscription item totals with main cart line when creating the subscription.
	 *
	 * Only price fields are changed. Plan, quantity, product, variation, and meta stay intact.
	 *
	 * @param array      $item_data Subscription item data.
	 * @param WC_Product $product   Product.
	 * @param array      $values    Cart item values.
	 * @param mixed      $item      Order item context.
	 * @return array
	 */
	public function sync_subscription_item_data( $item_data, $product, $values, $item ) {
		unset( $item );

		try {
			if ( ! is_array( $item_data ) || ! is_array( $values ) ) {
				return $item_data;
			}

			if ( $this->should_skip_cart_item( $values ) ) {
				return $item_data;
			}

			if ( ! $this->cart_item_is_subscribe_and_save( $values ) ) {
				return $item_data;
			}

			$qty = isset( $values['quantity'] ) ? (float) $values['quantity'] : 1;
			if ( $qty <= 0 ) {
				return $item_data;
			}

			$unit = null;
			if ( $product instanceof WC_Product ) {
				$unit = $this->get_main_cart_unit_price_for_product( $product );
			}

			if ( null === $unit || $unit <= 0 ) {
				return $item_data;
			}

			$line_total = $unit * $qty;
			$current    = isset( $item_data['total'] ) ? (float) $item_data['total'] : 0.0;

			if ( ! $this->should_replace_price( $current, $line_total ) ) {
				return $item_data;
			}

			if ( isset( $item_data['total'] ) ) {
				$item_data['total'] = $line_total;
			}

			if ( isset( $item_data['subtotal'] ) ) {
				$item_data['subtotal'] = $line_total;
			}

			if ( isset( $item_data['price'] ) ) {
				$item_data['price'] = $unit;
			}

			if ( isset( $item_data['item_price'] ) ) {
				$item_data['item_price'] = $line_total;
			}

			return $item_data;
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			return $item_data;
		}
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
	 * Whether a cart item must be ignored for price sync.
	 *
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
	 * Exact product/variation match — no parent-id fallback.
	 *
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

		return ( $product_id === $item_product_id && 0 === $item_variation_id );
	}

	/**
	 * Replace only when Sublium has a positive price and cart unit is lower.
	 *
	 * @param float      $current   Sublium / current price.
	 * @param float|null $candidate Cart unit or line total.
	 * @return bool
	 */
	private function should_replace_price( $current, $candidate ) {
		if ( null === $candidate ) {
			return false;
		}

		$current   = (float) $current;
		$candidate = (float) $candidate;

		if ( $candidate <= 0 || $current <= 0 ) {
			return false;
		}

		return $candidate < $current;
	}

	/**
	 * @param mixed $plan Plan object.
	 * @return bool
	 */
	private function is_subscribe_and_save_plan( $plan ) {
		if ( ! is_object( $plan ) || ! method_exists( $plan, 'get_type' ) ) {
			return false;
		}

		return self::PLAN_TYPE_SUBSCRIBE_AND_SAVE === (int) $plan->get_type();
	}

	/**
	 * @param array $cart_item Cart item.
	 * @return bool
	 */
	private function cart_item_is_subscribe_and_save( $cart_item ) {
		if ( empty( $cart_item['sublium_wcs_plan'] ) ) {
			return false;
		}

		if ( ! class_exists( '\Sublium_WCS\Includes\Main\Plans' ) ) {
			return false;
		}

		$product = ( isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product ) ? $cart_item['data'] : null;
		$plan    = \Sublium_WCS\Includes\Main\Plans::get_plan_by_id( $cart_item['sublium_wcs_plan'], $product );

		return $this->is_subscribe_and_save_plan( $plan );
	}

	/**
	 * Main WooCommerce cart (not a Sublium recurring clone).
	 *
	 * @return WC_Cart|null
	 */
	private function get_main_cart() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return null;
		}

		$cart = WC()->cart;

		// Recurring clones carry sublium_wcs_plan on the cart object itself.
		if ( is_object( $cart ) && isset( $cart->sublium_wcs_plan ) && ! empty( $cart->sublium_wcs_plan ) ) {
			return null;
		}

		return $cart;
	}

	/**
	 * Cart contents without triggering a session reload / totals recalc.
	 *
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
	 * Explicit giveaway markers only.
	 *
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
