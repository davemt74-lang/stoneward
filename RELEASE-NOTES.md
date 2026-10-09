# Stonefellow v1.3.16 — Fan Community Launch Control

- Added an Admin on/off toggle for the public Fan Community.
- Fan Community defaults **OFF** until explicitly enabled in Admin → Settings.
- When off, public community navigation, feed, posting, chat quick action and community-specific Agent routing are closed.
- Direct community API requests are rejected while the feature is off.
- Existing community data is preserved for later launch and remains visible to Admin.
- Community-post-driven proactive Agent outreach is suppressed while the public community is off.
- Fan CRM remains fully active.
- Newsletter signup/unsubscribe and consent remain fully active.
- Account, purchase, listening, playlist and Agent CRM signals remain fully active.
- Newsletter now has an independent public view so it does not depend on the community feature.
- No database migration is required.
- Application advances to **1.3.16**.
- Database schema remains **1.3.13**.

# Stonefellow v1.3.15 — Section 17: Fan CRM, Community & Agent Engagement

- Added a unified fan CRM around the existing account, listening, commerce, library, community and Agent systems.
- Added durable fan contacts and CRM event history.
- Added CRM identity merge between newsletter contacts and later account users.
- Added guest-purchase CRM contacts without silently adding them to marketing.
- Added public newsletter signup connected directly to CRM.
- Added explicit marketing consent timestamps and tokenized unsubscribe flow.
- Kept newsletter consent fan-controlled and read-only in Admin.
- Added signed-in Stonefellow fan community feed and posting.
- Added community anti-spam bounds, rate limits, owner deletion and Admin moderation.
- Added governed proactive in-app Agent engagement after meaningful fan activity.
- Added 20-hour proactive-Agent cooldown and per-event dedupe.
- Added fan control to disable proactive Agent interaction separately from newsletter email.
- Added proactive fan engagements to the existing Admin Agent Brain ledger.
- Added dedicated Admin Fans + CRM workspace and cross-system fan profiles.
- Added administrator-approved in-app Agent outreach with CRM/Brain audit.
- Added chat-footer + quick-action menu for Create Record, Create Playlist, Tour Dates, Merch Store, Fan Community and Newsletter.
- Added current-product Store view backed by server data.
- Application advances to 1.3.15.
- Database schema advances to 1.3.13.
