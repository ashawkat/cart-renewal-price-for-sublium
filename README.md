# Cart Renewal Price for Sublium

**Cart Renewal Price for Sublium** is an independent WordPress plugin by [Betatech](https://betatech.co/). It keeps Sublium Subscribe & Save **renewal / subscription totals** in line with the **price the customer actually paid in the cart**, instead of the raw product variation price.

This is a third-party companion plugin. It is not affiliated with, endorsed by, or an official product of Sublium or FunnelKit.

| | |
|---|---|
| Display name | Cart Renewal Price for Sublium |
| Directory slug | `cart-renewal-price-for-sublium` |
| Text domain | `cart-renewal-price-for-sublium` |
| GitHub | [ashawkat/cart-renewal-price-for-sublium](https://github.com/ashawkat/cart-renewal-price-for-sublium) |

The WordPress plugin header lives in `readme.txt`. This file is the GitHub overview.

---

## Why it exists

Stores that sell **Subscribe & Save** often show a custom cart price (volume, FunnelKit checkout, or other cart-level pricing). Example:

| | Amount |
|---|---|
| Cart / parent order | **$199.98** (what the shopper paid) |
| Sublium subscription / renewal | **$299.97** (`$99.99 × 3`, the variation regular price) |

Sublium rebuilds recurring carts from the product/variation price and drops most non-Sublium coupons. The parent order stays correct; the subscription record does not. Related Orders then shows two different totals for the same purchase.

Putting this logic inside **Auto Apply Cart Coupon** was unsafe: combining gift/plan handling with renewal-price changes could stop Sublium from creating the subscription at all. This plugin exists so that job stays in one place, **after** the subscription has been created, without touching plan assignment.

---

## What it does

- Feeds the **cart line unit** into Sublium recurring totals via `sublium_wcs_subscription_product_price` and `sublium_wcs_skip_plan_discount` (official hooks)
- Copies the **parent order line subtotal** onto the matching Sublium subscription after checkout (backup)
- Updates checkout **Renewal Price** HTML from the cart line when possible
- Leaves Subscribe & Save **plan assignment** alone
- Skips giveaways / free gifts (WebToffee, FunnelKit cart gifts)
- Declares **WooCommerce HPOS** compatibility

### What it does not do

- Does not strip or unset `sublium_wcs_plan`
- Does not change Sublium subscription groups
- Does not exclude products from plan assignment
- Does not manage coupons or first-month gifts (use [Auto Apply Cart Coupon](https://github.com/ashawkat/auto-apply-cart-coupon) for that)
- Does not skip Sublium plan discounts globally (only while preserving a cart line on recurring totals)

---

## Requirements

- WordPress 6.0+
- PHP 7.4+
- WooCommerce 7.0+
- [Sublium Subscriptions for WooCommerce](https://sublium.com/) (free and/or Pro)

FunnelKit checkout / cart is optional but is a common reason the cart line differs from the variation price.

---

## Install

1. Copy the `cart-renewal-price-for-sublium` folder into `wp-content/plugins/`.
2. Activate **Cart Renewal Price for Sublium**.
3. Keep **Auto Apply Cart Coupon** separate if you use first-month-only gifts.

No settings screen. If WooCommerce and Sublium are active, it runs.

---

## How it works

1. While Sublium rebuilds the **recurring cart** (`calculation_type = recurring_total`), this plugin returns the matching WooCommerce cart line unit (`line_subtotal / qty`) on `sublium_wcs_subscription_product_price`.
2. It then returns `true` on `sublium_wcs_skip_plan_discount` **only for that product**, so Sublium stores that unit as-is (no second plan discount, no billing-cycle division).
3. After the subscription exists, it can still copy parent-order line subtotals if they are lower (backup).
4. Checkout “Renewal Price” HTML may be rewritten from the cart line for display.

Filters fail open: any error leaves Sublium’s original price / subscription untouched. Plan meta is never stripped.

---

## FAQ

**Is this an official Sublium plugin?**  
No. It is built by Betatech for stores that already run Sublium.

**Will it stop subscriptions from being created?**  
It is designed not to. Official price hooks fail open (original Sublium price on error). Plan meta and subscription groups are never changed.

**Does it work with HPOS?**  
Yes. Compatibility with WooCommerce custom order tables is declared on `before_woocommerce_init`.

**What about free gifts?**  
Explicit giveaway lines are skipped. First-month-only gifts belong in Auto Apply Cart Coupon.

**Do I need to configure anything?**  
No. Activate it on a site that already has WooCommerce and Sublium.

---

## Changelog

### 1.0.3
- Use Sublium’s `sublium_wcs_subscription_product_price` and `sublium_wcs_skip_plan_discount` so stored renewal lines keep the cart unit price

### 1.0.2
- Declared WooCommerce High-Performance Order Storage (HPOS) compatibility

### 1.0.1
- Copy parent order line totals onto the Sublium subscription after it is created
- Do not mutate Sublium recurring carts during checkout

### 1.0.0
- Initial release

---

## License

GPL-3.0-or-later.

---

## Author

**Betatech** — [https://betatech.co/](https://betatech.co/)
