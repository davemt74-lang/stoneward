# Section 84A — Kunaki XML / HTTP Vinyl POD Bridge

## Official service interfaces verified
- Kunaki XML service: https://kunaki.com/xmlservice.htm — shipping options, create manufacturing/fulfillment order, and retrieve status/tracking for an existing Kunaki product ID.
- Kunaki HTTP service: https://kunaki.com/httpservice.htm — same three operations using HTTP query parameters, optionally an XML response. Use the XML interface as the preferred Stonefellow implementation because the HTTP example carries password credentials in query strings and may expose them in logs.
- Official Kunaki vinyl information: https://kunaki.com/product-vinyl.html — vinyl production supports XML/HTTP/CSV/manual fulfillment. Prices must come from current vinyl-specific pricing, not the generic CD/DVD example in the XML service document.

**Critical limitations:** Neither API publishes NEW vinyl products; published titles are created through Kunaki's publishing website and cannot be edited later. A unique custom audio/artwork combination requires its own published Kunaki product identity. Kunaki's FAQ says its XML/HTTP system has no genuine test mode even though the historical XML schema documents `Mode=TEST`. Treat every outbound order creation as potentially real.

## Section 84A implementation
- Added `api/kunaki-bridge.php`: validated 10-character product IDs, quote and order *preview* XML builders, safe normalized response parser (XXE/DTD disabled), shipping prices in cents, status/tracking enums, required address fields and product/quantity limits.
- Added a **published product-ID mapping** to the existing `admin/api/vinyl-production.php` and the existing Fulfillment canvas. Admin approval and the exact Section 81 vinyl design fingerprint are required; the production record lock and revision are reused. The same product ID cannot be linked to a different design across production orders.
- All mappings are marked `safe_to_submit=false` and `provider_product_publication_verified=false`. The editor makes clear that linking an ID is not proof of external publication.
- No production submission, credentials, Kunaki network access, background browser automation, customer notification, financial movement or order-status mutation is implemented here.
- Added automated local fixtures to release CI to test XML escaping, malicious XML rejection, mapping approval and tampering, privacy, status parsing and no outward transmission.

## Next development stages (not built in 84A)
1. **84B — Governed Kunaki title publishing:** prepare title-specific WAV/MP3 and JPEG packages, apply artwork/duration specs, and let an owner-controlled browser specialist upload through Kunaki's official publishing UI. Never assume a page was published without seeing a real Kunaki Product ID, owner confirmation, and evidence of the exact design.
2. **84C — XML shipping quotes and product verification:** authenticated Admin settings, protected credentials, HTTPS POST allowlist, current quote, exact published product match and operator financial preview.
3. **84D — Production submission and recovery:** one explicit order approval, immutable proof and shipping snapshot, durable external-unknown state on ambiguous responses; never auto-retry a possibly accepted vendor order.
4. **84E — Provider order status to fulfillment:** bounded status polling, tracking updates, event dedupe and customer notification integration; never fabricate shipping metrics or delivery confirmation.
5. **84F — End-to-end certification:** fixture tests, integration tests with a real Kunaki publisher account (no order creation without explicit approval), real MySQL test and exact PR/main checks.

**Financial controls:** Kunaki's XML/HTTP orders normally remain pending until funded; a prepaid balance may cause prompt processing and shipment. Kunaki does not promise returns or cancellation after payment. There is no safe fake vendor order; operator approval is mandatory.

This section is a *secure foundation*, not a completed live Kunaki integration.
