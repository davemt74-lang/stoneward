# Account token meter

`GET api/token-meter.php` returns a private, noncached snapshot for the authenticated account. It accepts no account selector and rejects other HTTP methods. The core queries authoritative storage directly; it never calls account enrollment, entitlement refresh, package renewal, token settlement or billing schema initialization.

The snapshot contains:

- `user_id` for client account-transition checks.
- `unlimited`; administrators have null available/percentage values rather than a fabricated allowance.
- `available_tokens`, the canonical stored spendable balance. Active reservations have already been deducted and are not subtracted twice.
- `held_tokens`, the sum of still-held reservations, shown separately.
- `allowance_tokens`, `allowance_basis` and `allowance_label`, based on the actual positive trial/plan allocation credited in the current subscription period. Later package-setting edits do not change that historical allocation. Stripe credits must match the exact subscription period identity or the recorded legacy invoice identity.
- `percentage_remaining`, available tokens divided by the current credited allowance, and `bar_percentage`, the visual value bounded to 0–100. Extra grants or carryover can make the numeric percentage exceed 100%; the exact count remains visible. This percentage describes available tokens relative to the allowance, not attribution of every remaining token to this cycle.
- `as_of`, the snapshot time.

Grant-only accounts, absent current allocation receipts, expired/future periods and zero allowances retain the exact available count with a null percentage. Missing balance storage returns an unavailable response, not a false zero. An existing balance table with no account row is the existing canonical zero-token state. Optional uninitialized reservation storage means there are no stored reservations.

Reads use a fresh consistent transaction. MySQL explicitly requests REPEATABLE READ for that transaction so concurrent settlement cannot mix an old balance with newly released holds. An outer transaction is rejected without changing it. Database role/status is authoritative; caller role hints cannot create unlimited access. No provider calls are needed.

Validation: `tests/token-meter.php` has 26 core checks, `tests/token-meter-http.py` has 16 actual authenticated PHP HTTP checks, and `tests/token-meter-mysql.py` has five native MariaDB checks. The HTTP suite compares billing rows before/after GET and verifies an expired free plan is not renewed. The MariaDB suite uses SELECT-only database privileges and a deterministic settlement between balance/hold reads to verify snapshot consistency. These are local/native database tests, not live HostGator or physical-device certification.


The public user dropdown displays the exact available count, allowance percentage and held amount. Unlimited accounts show Unlimited; accounts with no meaningful credited allowance show the exact count and Percentage unavailable. Numeric percentages above 100 remain visible while the bar stays bounded. The menu refreshes on opening, foreground focus and confirmed JSON/stream usage settlement; it does not poll while closed or request guest balances. Responses are checked against the current account and stale responses cannot replace a new account's values. Model selection remains independently usable.

Additional validation: eight real app transport contracts, mobile/desktop module lifecycle/account-race tests, and 16 actual PHP/UI integration checks. Independent review found no material blocker for this section's scoped automated acceptance (10/10). The full local v1.3 release regression suite and all 516 native Chromium Listening Room assertions at 375, 900 and 1440 pixels also pass. GitHub CI and merge/deployment are separate delivery gates; live hosting and physical-device coverage remain unclaimed.
