=== Cart Renewal Price for Sublium ===
Contributors: betatech
Tags: sublium, woocommerce, subscription, renewal, subscribe and save
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.4
WC requires at least: 7.0
WC tested up to: 9.6
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Keeps Sublium Subscribe & Save renewal totals in line with the cart / parent order price instead of the raw variation price.

== Description ==

**Cart Renewal Price for Sublium** is a third-party companion plugin by Betatech. It is not affiliated with, endorsed by, or an official product of Sublium or FunnelKit.

Stores that sell Subscribe & Save often show a custom cart price (volume pricing, FunnelKit checkout, or other cart-level discounts). The parent order is correct (for example $199.98), but Sublium rebuilds the subscription from the variation regular price (for example $299.97 = $99.99 × 3). Related Orders then lists two different totals for the same purchase.

This plugin exists so that gap can be fixed **without** touching Sublium plan assignment, and **without** putting the logic inside Auto Apply Cart Coupon (combining those jobs previously risked blocking subscription creation).

= What it does =

* During Sublium recurring totals, feeds the cart line unit via `sublium_wcs_subscription_product_price` and keeps it with `sublium_wcs_skip_plan_discount` (only for that product)
* After the subscription exists, copies the parent order line subtotal if it is still lower (backup)
* Updates checkout Renewal Price HTML from the cart line when possible
* Skips explicit giveaways / free gifts
* Declares WooCommerce HPOS compatibility

= What it does not do =

* Does not strip or change Sublium plans
* Does not alter subscription groups
* Does not skip plan discounts globally
* Does not manage coupons or first-month gifts (use Auto Apply Cart Coupon for that)

Requires **WooCommerce** and **Sublium**.

== Installation ==

1. Upload the `cart-renewal-price-for-sublium` folder to `/wp-content/plugins/`
2. Activate **Cart Renewal Price for Sublium**
3. Keep **Auto Apply Cart Coupon** separate if you use first-month-only gifts

No settings screen. The plugin runs when WooCommerce and Sublium are active.

== Frequently Asked Questions ==

= Is this an official Sublium plugin? =

No. It is built by Betatech for stores that already run Sublium.

= Will it stop subscriptions from being created? =

It is designed not to. Official price hooks fail open. Plan meta and subscription groups are never changed.

= Does it support HPOS? =

Yes. Compatibility with WooCommerce custom order tables is declared on `before_woocommerce_init`.

== Changelog ==

= 1.0.4 =
* Only skip Sublium plan discounts while rebuilding recurring totals, so checkout line items still show the plan price

= 1.0.3 =
* Use Sublium’s subscription product price and skip-plan-discount hooks so stored renewal lines keep the cart unit price

= 1.0.2 =
* Declared WooCommerce High-Performance Order Storage (HPOS) compatibility

= 1.0.1 =
* Copy parent order line totals onto the Sublium subscription after it is created
* Do not mutate Sublium recurring carts during checkout

= 1.0.0 =
* Initial release
