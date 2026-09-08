# Sublium Cart Renewal Price — build context

## Why this plugin exists

Keep **Auto Apply Cart Coupon** (`auto-apply-cart-coupon`) stable. Do **not** put renewal-price logic back into that plugin — earlier combined plan-stripping + price sync broke Sublium subscription creation.

This plugin’s **only job**: make Sublium Subscribe & Save **renewal / subscription totals** use the **cart line price** (e.g. `$199.98`), not the raw variation price (e.g. `$299.97` = `$99.99 × 3`).

## Observed bug

- Parent order total: **$199.98** (custom / cart Subscribe & Save price)
- Sublium subscription total: **$299.97** (variation regular × qty)
- Example: Related Orders showed Parent `#10931` $199.98 vs Subscription `#1286` $299.97

## Root cause (Sublium)

1. Checkout “Renewal Price” comes from recurring cart `get_total()`  
   Template: `sublium-subscriptions-for-woocommerce/templates/checkout/recurring-totals.php`  
   Filter: `sublium_wcs_woocommerce_cart_item_total`
2. Recurring carts are rebuilt from product/variation price in `includes/main/cart.php` (`calculation_type = recurring_total` → `get_recurring_cart_price()`).
3. Subscriptions are created from that recurring cart (`line_total` + `get_total()`) in `includes/main/checkout.php`.
4. Non-Sublium coupons are stripped from the recurring cart; FunnelKit/volume/custom cart pricing often does not carry over.

## Safe implementation plan (price only)

### DO
1. Hook `sublium_wcs_subscription_price` when 4th arg `calculation_type === 'recurring_total'`.
2. Replace unit price with main cart `line_subtotal / quantity` for the matching plan item.
3. Optional safety net: `sublium_wcs_create_subscription_item_data` to copy price/total from matching parent order/cart line.
4. Optional display: `sublium_wcs_woocommerce_cart_item_total` as fallback for checkout UI.

### Guards (critical)
- Never strip or unset `sublium_wcs_plan` / plan meta.
- Never filter `sublium_wcs_subscription_groups`.
- Never use `sublium_wcs_exclude_product_from_plan_assignment`.
- Skip explicit giveaways (`free_product === wt_give_away_product`, `_fkcart_free_gift`).
- Only sync when cart unit price **> 0**.
- Prefer sync only when cart unit price is **lower than** Sublium’s calculated price (don’t inflate).
- Require matching cart item already has `sublium_wcs_plan`.

### DO NOT
- Touch Auto Apply Cart Coupon’s Sublium class (v1.2.6+ is post-creation gift cleanup only).
- Mix gift/plan stripping into this plugin.

## Related stack

- Sublium: `sublium-subscriptions-for-woocommerce` (+ pro)
- FunnelKit checkout / cart often present
- WebToffee giveaways may be in cart; ignore them for price sync
- Auto Apply Cart Coupon handles first-month gifts separately

## Test plan

1. Add 3-month Subscribe & Save product (cart shows ~$199.98 for that line).
2. Checkout: subscription **must still be created**.
3. Subscription admin total / renewal ≈ **$199.98**, not **$299.97**.
4. Checkout “Renewal Price” matches when possible.
5. Orders without custom cart pricing behave normally (no change or same as Sublium).

## Suggested slug / name

- Folder: `sublium-cart-renewal-price`
- Plugin name: **Sublium Cart Renewal Price**
- Text domain: `sublium-cart-renewal-price`
