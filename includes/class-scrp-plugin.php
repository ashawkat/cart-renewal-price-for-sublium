<?php
/**
 * Main plugin class — price sync only.
 *
 * @package Sublium_Cart_Renewal_Price
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SCRP_Plugin
 */
class SCRP_Plugin {

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
		unset( $plan );

		if ( 'recurring_total' !== $calculation_type ) {
			return $price;
		}

		if ( ! $product instanceof WC_Product || ! WC()->cart ) {
			return $price;
		}

		$cart_unit = $this->get_main_cart_unit_price_for_product( $product );

		if ( null === $cart_unit || $cart_unit <= 0 ) {
			return $price;
		}

		// Only replace when cart price is the discounted/custom amount.
		if ( (float) $price > 0 && $cart_unit >= (float) $price ) {
			return $price;
		}

		return (float) $cart_unit;
	}

	/**
	 * Rewrite renewal price HTML from main-cart plan lines when possible.
	 *
	 * @param string  $price_html     Formatted HTML.
	 * @param WC_Cart $recurring_cart Recurring cart.
	 * @return string
	 */
	public function filter_recurring_total_display( $price_html, $recurring_cart ) {
		if ( ! $recurring_cart || ! is_object( $recurring_cart ) || ! method_exists( $recurring_cart, 'get_cart' ) ) {
			return $price_html;
		}

		$total = 0.0;
		$found = false;

		foreach ( $recurring_cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
				continue;
			}

			if ( $this->is_giveaway_cart_item( $cart_item ) ) {
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
			$total += $unit * $qty;
			$found  = true;
		}

		if ( ! $found || $total <= 0 ) {
			return $price_html;
		}

		return wc_price( $total );
	}

	/**
	 * Align subscription item totals with main cart line when creating the subscription.
	 *
	 * @param array      $item_data Subscription item data.
	 * @param WC_Product $product   Product.
	 * @param array      $values    Cart item values.
	 * @param mixed      $item      Order item context.
	 * @return array
	 */
	public function sync_subscription_item_data( $item_data, $product, $values, $item ) {
		unset( $item );

		if ( ! is_array( $item_data ) || ! is_array( $values ) ) {
			return $item_data;
		}

		if ( $this->is_giveaway_cart_item( $values ) ) {
			return $item_data;
		}

		if ( empty( $values['sublium_wcs_plan'] ) ) {
			return $item_data;
		}

		$qty = isset( $values['quantity'] ) ? (float) $values['quantity'] : 1;
		if ( $qty <= 0 ) {
			return $item_data;
		}

		$unit = null;

		if ( isset( $values['line_subtotal'] ) ) {
			$unit = (float) $values['line_subtotal'] / $qty;
		} elseif ( $product instanceof WC_Product ) {
			$unit = $this->get_main_cart_unit_price_for_product( $product );
		}

		if ( null === $unit || $unit <= 0 ) {
			return $item_data;
		}

		$line_total = $unit * $qty;

		if ( isset( $item_data['total'] ) ) {
			$current = (float) $item_data['total'];
			if ( $current > 0 && $line_total >= $current ) {
				return $item_data;
			}
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
	}

	/**
	 * Main cart per-unit line_subtotal for a plan product.
	 *
	 * @param WC_Product $product Product.
	 * @return float|null
	 */
	private function get_main_cart_unit_price_for_product( $product ) {
		if ( ! WC()->cart || ! $product instanceof WC_Product ) {
			return null;
		}

		$match_ids = array_filter(
			array(
				(int) $product->get_id(),
				(int) $product->get_parent_id(),
			)
		);

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['sublium_wcs_plan'] ) ) {
				continue;
			}

			if ( $this->is_giveaway_cart_item( $cart_item ) ) {
				continue;
			}

			$item_ids = array_filter(
				array(
					isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0,
					isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0,
					( isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product ) ? (int) $cart_item['data']->get_id() : 0,
				)
			);

			if ( empty( array_intersect( $match_ids, $item_ids ) ) ) {
				continue;
			}

			$qty = isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 0;
			if ( $qty <= 0 || ! isset( $cart_item['line_subtotal'] ) ) {
				continue;
			}

			return (float) $cart_item['line_subtotal'] / $qty;
		}

		return null;
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
