# Stonefellow v1.3.20 — Section 21 Merch & Direct-to-Fan Commerce Audit

## 21A — Product Catalog & Variants — 10/10 design
- Database-backed merch products.
- Draft/active/archive lifecycle.
- Pricing and compare-at pricing.
- Variants and unique SKUs.
- Per-product and per-variant stock behavior.
- Legacy custom-media products preserved.

## 21B — Merch Media — 10/10 design
- Universal Media Library integration.
- Primary product image and gallery.
- Video/document roles.
- Media-readiness coverage includes dynamic merch.

## 21C — Inventory & Availability — 10/10 design
- Finite/unlimited inventory.
- Low-stock thresholds.
- Server-side availability validation.
- Atomic checkout reservation.
- Audited inventory event ledger.
- Idempotent release.
- Sold-state finalization after shipment/delivery.
- Audited manual adjustment.

## 21D — Fan Store, Cart & Checkout — 10/10 design
- Merch product cards.
- Variant selection.
- Quantity selection.
- Compare-at pricing.
- Low-stock/sold-out display.
- Max-per-order enforcement.
- Merch-aware cart and checkout.
- Existing music/custom-media checkout preserved.

## 21E — Orders & Fulfillment — 10/10 design
- Canonical merch order-line ledger.
- Inventory linked to order lifecycle.
- Cancel/refund releases eligible reservations.
- Shipment/delivery finalizes sold inventory.
- Existing fulfillment/POD order workflows preserved.

## 21F — CRM Purchase Intelligence — 10/10 design
- Merch line items attached to fan identity.
- Product, variant, SKU, quantity, spend and order visible in CRM.
- Merchandise purchase event added to fan history.
- Agent fan context gains recent merchandise history.

## 21G — Campaign Integration — 10/10 design
- Existing campaign discount entitlement path retained.
- Optional product-ID scope on discount nodes.
- Discount only applies to matching merch subtotal when scoped.
- Campaign attribution/redemption remains authoritative.

## 21H — Agent Commerce Intelligence — 10/10 design
- Live active-product/variant/availability context.
- Admin Agent routes product/inventory work to Merch + Products.
- Public Agent cannot reserve inventory or purchase.
- Product activation and stock adjustments are consequential Admin actions with Agent Brain audit.

## Regression gate — pending measured GitHub validation
Required:
- PHP syntax
- public JavaScript syntax
- Admin JavaScript syntax
- Campaign Builder JavaScript syntax
- Media Library JavaScript syntax
- Products JavaScript syntax
- Sections 1–20 inherited regression suites
- executable Section 21 SQLite inventory tests

PROVISIONAL SECTION 21 SCORE: **10/10 design / pending green release gate**
