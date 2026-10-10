# Stonefellow v1.3.20 — Section 21: Merch & Direct-to-Fan Commerce

Stonefellow remains a **single-artist direct-to-fan platform**. Section 21 turns the existing Store/order stack into a real merch commerce system while preserving the custom vinyl/cassette builder.

## Merch products

Admin now has **Merch + Products** for database-backed products with:

- title, description and category
- draft / active / archived lifecycle
- base price and compare-at price
- physical / non-physical products
- manual, self or POD fulfillment classification
- finite or unlimited inventory
- low-stock threshold
- per-order quantity limits
- tags and featured status
- reusable Media Library imagery

Legacy custom vinyl and cassette builder products continue to work alongside the new catalog.

## Variants and SKUs

Products can have purchasable variants such as:

- size
- color
- edition
- format
- bundle option

Each variant can have its own:

- SKU
- price
- compare-at price
- finite / unlimited / inherited inventory
- quantity
- low-stock threshold
- active / archived status

When active variants exist, the customer must choose a valid available variant.

## Inventory governance

Finite inventory is enforced server-side.

At checkout Stonefellow:

1. re-validates live availability,
2. atomically reserves finite stock,
3. writes an audited inventory event,
4. writes the canonical merch order-line record.

If order creation fails, reserved stock is released.

Cancellation or an eligible refund releases only stock still marked **reserved**. The release is idempotent: the same order cannot restore stock twice.

When fulfillment becomes **shipped** or **delivered**, the inventory reservation becomes **sold** and is no longer automatically restocked by a later status change.

Manual Admin stock adjustments are recorded in the same inventory ledger.

## Store and checkout

The public Stonefellow Store now supports:

- merch cards
- Media Library product imagery
- compare-at pricing
- variant selection
- quantities
- max-per-order rules
- low-stock messaging
- sold-out state
- merch cart lines
- merch-aware checkout review
- existing campaign discounts
- existing digital-track purchases
- existing custom vinyl/cassette builds

Merch is added as a new cart line type; the existing checkout architecture is retained.

## Campaign integration

Campaign discount nodes can optionally specify product IDs.

A product-scoped discount applies only to matching merch line items. It does not accidentally discount unrelated music, custom physical builds or other merchandise.

Campaign entitlement claim/redemption and attribution remain unchanged.

## CRM and fan intelligence

Every merch checkout produces canonical merch line items linked back to the fan when possible.

Fan CRM profiles expose:

- product
- variant
- SKU
- quantity
- spend
- order ID
- inventory state

The CRM timeline also receives a merchandise purchase event.

This allows later segmentation and lifecycle automation to operate on actual buying behavior rather than generic order totals.

## Agent integration

The public Stonefellow Agent receives active merch catalog context including product names, pricing, variants and current availability.

The Agent may:

- explain products
- discuss sizes/options
- report availability
- surface matching campaigns/offers
- open the Store

The Agent may **not** reserve stock, complete checkout or charge a customer. Purchase remains a user-confirmed Store action.

Admin Agent Brain records:

- product saves / activation
- product archive
- inventory adjustments

Making a product public/active requires explicit Admin confirmation.

## Media Library integration

Every database merch product uses the Section 20 universal Media Library.

Supported product roles include:

- Primary product image
- Product gallery
- Video
- Documents

Media completeness includes dynamic merch products as well as the original custom-media products.

## Database

Migration **2026-10-10-018** adds:

- `store_products`
- `store_variants`
- `store_inventory_events`
- `store_order_items`

Application: **1.3.20**  
Database schema target: **1.3.17**
