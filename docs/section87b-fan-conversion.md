# Stonefellow 87B — Fan Journey & Purchase Conversion

## 87B1 · Verified entitlement read model
Version 1.3.95; PR #180. Extends the existing five 87A public discovery paths with verified **owned vs unowned** song status from paid account-library orders. No invented prices, discounts or vinyl license balances. Existing `sf_quote`, inventory, Stripe/cash simulation, My Library, vinyl license and builder gates remain the only sources of financial/rights truth. Guests get public songs without identity leakage. The endpoint is GET-only and bound to the authenticated session, never to user IDs supplied in requests.

## 87B2 · Integrated conversion controls
Version 1.3.96. Adds **Save song / Saved for later**, **Review digital purchase**, **Already owned · My Library**, and **Explore custom vinyl** directly to the existing guided Music canvas. Saving uses the existing CSRF-protected favorites API and is *not* a purchase. Digital purchase enters the existing cart, which recalculates an authoritative quote before checkout; no payments happen from discovery. Owned songs go to My Library, not another purchase CTA. Custom vinyl enters the original vinyl builder, whose license balance, eligibility and pricing checks still run independently. Published catalog visibility and privacy preferences remain inherited from 87A1–87A3.

No parallel checkout, order database, payment integration, LLM tool, or CRMs. No marketing email or unsolicited outreach. The UI escapes catalog-provided labels and reasons and does not claim that a song is purchased based on a mere favorite.

## Follow-up
87B3 will connect these explicit commercial decisions to the existing Customer Agent Brain to explain purchases and vinyl print licensing safely, plus non-intrusive aggregate conversion metrics. The agent must never fabricate offers, bypass payment or spend fan print licenses without checkout.

## Acceptance
Separate gates per section: targeted PHP/Node tests, previous 87A, 85A, 84B3 and 78 regressions, full release, real MySQL checkout/inventory certification, exact-revision green PR and merged-main checks. Complete full-site deployment ZIP and SHA-256 from the final merged commit.

## 87B3 · Customer Agent Brain purchase guidance
Version 1.3.97. Extends the **existing** customer-facing Agent Chat commerce route. For a specific published Stonefellow song, the Agent checks the signed-in fan's *paid order-backed* My Library status and offers the next safe action:
- Already purchased → My Library purchases, never a repeat digital-purchase pitch.
- Not yet purchased → open the real Song page, where the user chooses to add to cart. No false claim that the song was added.
- Vinyl-ready song → original record Builder, where license balances, rights, and prices are validated separately; no invented balance or automatic manufacturing.
- Unreleased or unknown song → generic cart navigation, with no unpublished title, audio path, or master disclosed.
- Guest → no personal ownership assertions.

The customer Agent's commerce context receives only an allowlisted status projection. It never accesses another buyer's orders, uploads music, changes inventory, grants license rights, charges a card, starts a campaign, or sends unsanctioned CRM outreach. A fan can ask about owning a particular song directly from the 87B2 card through the existing Agent composer. Existing typed route/confirmation boundaries are retained.

Tests call the **real** Agent purchase policy with different fan IDs and guest scenarios, confirm current published visibility, ensure no private order/media leaks and preserve vinyl-license/commerce regressions. The same full release and MySQL process applies before PR merge. This section finishes the initial 87B fan journey; production field testing should still include real songs, logged-in payments and license counts.
