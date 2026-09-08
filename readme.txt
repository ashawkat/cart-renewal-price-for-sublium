=== Cart Renewal Price for Sublium ===
Contributors: betatech
Tags: sublium, woocommerce, subscription, renewal, subscribe and save
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.2
WC requires at least: 7.0
WC tested up to: 9.6
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Use the cart Subscribe & Save line price for Sublium renewal / subscription totals instead of the raw variation price.

== Description ==

Sublium often rebuilds recurring totals from the product variation price. If your cart shows a custom Subscribe & Save amount (for example $199.98) but the subscription stores the full variation total (for example $299.97), this plugin syncs the recurring unit price from the main cart line.

This is a third-party plugin by Betatech. It is not affiliated with Sublium.

**What it does**

* Syncs Sublium recurring calculation to cart `line_subtotal / qty`
* Updates checkout renewal display when possible
* Aligns subscription item totals at creation time

**What it does not do**

* Does not strip or change Sublium plans
* Does not alter subscription groups
* Does not manage coupons or free gifts (use Auto Apply Cart Coupon for that)

Requires **WooCommerce** and **Sublium**.

== Installation ==

1. Upload the `cart-renewal-price-for-sublium` folder to `/wp-content/plugins/`
2. Activate **Cart Renewal Price for Sublium**
3. Keep **Auto Apply Cart Coupon** separate if you use first-month gifts

== Changelog ==

= 1.0.2 =
* Declared WooCommerce High-Performance Order Storage (HPOS) compatibility

= 1.0.1 =
* Fix: copy parent order line totals onto the Sublium subscription after it is created
* Safety: do not mutate Sublium recurring carts during checkout (that path can block subscription creation)

= 1.0.0 =
* Initial release — price-only Sublium cart → renewal sync
