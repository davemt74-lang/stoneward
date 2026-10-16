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
