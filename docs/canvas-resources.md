# Selected sources, discussion and feedback

Type `/canvas`, `/merch` or `/tickets` anywhere in the public chat composer to open the matching source catalog. Search and select multiple records, then add the selection to the draft. This does not start a project. `/canvas` lists only the signed-in account’s saved canvases. Merchandise and ticket offers come from the current published artist catalog.

The composer inserts `{canvas:ID}`, `{merch:ID}` or `{ticket:ID}`. Each type can be selected independently alongside `/images` and `/music`. Clearing merchandise or tickets inserts `{merch-clear}` or `{tickets-clear}`, so an `/edit` can explicitly clear an inherited type. An omitted type keeps its previous selection. Selected IDs are validated by the server, preserved in the project approval snapshot and checked again before a new build. Reopening a saved canvas reads current public availability; withdrawn merchandise and offers disappear from its live datasets.

Type `/playlists` to choose your saved playlists or published artist playlists. In **Admin → Music → Artist Playlists**, review the complete ordered list and publish its exact revision. A user's ordinary public sharing does not publish to the artist catalog.

The picker attaches `{playlist:own-ID@VERSION}` or `{playlist:artist-ID@VERSION}`. Select a changed playlist again to approve its new revision. `{playlists-clear}` clears inherited playlists, and `{music-clear}` clears directly selected songs. Each can be cleared independently. Selecting sources uses no AI tokens and starts no playback.

A private canvas can use the owner's saved playlists. To submit it to the Public Gallery, revise it to remove any own-playlist sources. Artist playlist inputs stay usable while the approved revision remains published. See [the audio contract](canvas-music-data.md) for ordered membership and playback.

Enter `/stories` in the canvas composer and press Enter or Send to choose published stories. Select individual stories or **All Stories**. Add comma-separated tags in the Story Editor, then enter a shortcut such as `/stories-behind-the-song` to choose stories with that tag. The tagged picker can attach the entire matching catalog as one scope. All and tag scopes follow current publication and tag membership on reopen.

The composer stores `{story:ID}`, `{stories-all}`, `{stories-tag:TAG}`, or `{stories-clear}` and preserves the selection across edits. Canvas code can declare the `published_stories` binding for live narrative and related public catalog data. A bounded planning preview identifies when more rows exist. Archiving removes a story from live data and revokes delivery of media published only through that story.

Enter `/lyrics` to select songs with published lyrics, even with an empty player. The picker identifies songs with approved timing and attaches `{lyrics:TRACK_ID}` references. A revision inherits its selections until they are replaced or cleared with `{lyrics-clear}`. Adding a selection starts neither playback nor a transcription and uses no AI tokens.

The `published_lyrics` binding exposes untimed Songbook text separately from approved timeline cues for the current public audio revision. Cue ranges are milliseconds and retain their source and approximation flags. Track identity and the current lyric revision accompany both sources. Code can bind fields as text or read declared rows from the host-injected `sfCanvasLyrics` JSON element, then use the existing audio SDK to follow playback if the prompt requests it. Lyrics remain available as creative context even when the canvas never displays them. Large deliveries preserve whole rows within a 384 KiB lyric-row budget and report `payload_truncated`. Bind a narrower track, kind or position when a canvas needs a portion beyond that budget.

Saved and shared canvases resolve the current publication at load. Withdrawn words and cues from replaced audio stop appearing in the dataset. Picker and planning metadata include availability and source identity, while code generation can use bounded public excerpts for inspiration. Draft lyrics, private annotations and unpublished timeline segments stay private. The **Published song lyrics** admin permission controls canvas delivery.

## Account data with /me

Enter `/me` in chat while signed in. Open a source's **Available fields** to inspect its exact code keys, types, and window. Select any combination and attach it to the draft. The picker reads schema metadata only. Attaching a source uses no Active Tokens, starts no build, and grants no data access.

A canvas receives the current viewer's records only for sources it declares, the administrator enables, and the viewer authorizes in **What this canvas uses**. The attachment stores source IDs as an upper bound. Edits inherit the selection, and `{me-clear}` removes the entire personal-data scope from a revision. The Coding and Testing agents work with schemas and empty private bindings. Published canvases load each viewer's own data after consent.

| Canvas source | Window or row limit | Fields |
| --- | --- | --- |
| `personal_listening_stats` | 30 days, up to 30 public songs | `track_id`, `title`, `release`, `listens`, `completes`, `listened_seconds`, `measured_events`, `legacy_events` |
| `my_listening_summary` | One row for the past 30 days of public-song activity | `window_days`, `starts`, `completes`, `sessions`, `tracks`, `listened_seconds`, `measured_events`, `legacy_events` |
| `my_listening_history` | Most recent 120 eligible events on public songs | `track_id`, `title`, `release`, `event_type`, `position_seconds`, `duration_seconds`, `occurred_at` |
| `my_purchases` | Up to 120 recent line items from orders linked to the viewer's account | `purchase_id`, `order_id`, `item_label`, `item_type`, `product_id`, `variant_id`, `track_id`, `release_id`, `quantity`, `item_total_cents`, `order_total_cents`, `currency`, `ordered_at`, `order_status`, `payment_status`, `fulfillment_status`, `vinyl_status` |
| `my_tickets` | Up to 60 recent Stonefellow reservations | `reservation_id`, `offer_id`, `show_id`, `offer_title`, `offer_type`, `show_title`, `show_date`, `venue`, `location`, `quantity`, `status`, `reserved_at` |
| `my_preferences` | One row for current settings | `locale`, `timezone`, `country_code`, `listening_history_enabled`, `personalization_enabled` |

History events include start, resume, pause, complete and skip. Measured listening seconds come from reported native playback, and legacy event counts indicate coverage without measured time. Listening sources honor the account's listening-history setting. Purchase order totals repeat across lines in one order, so group by `order_id` before summing totals.

## Live sources with /data

Type `/data` and add up to four named sources. Each record binds an alias to a registered product and a selected public place, scope, exact target point, or feed. A revision inherits its bindings until replaced or cleared. The catalog describes parameters, typed fields and permissions. Opening it makes no upstream data request.

Read a binding from authored code with `await StonefellowData.get(alias)`. The shared envelope contains `status` (`fresh`, `stale` or `unavailable`), `source`, `fetched_at`, `expires_at`, and typed `rows`. Where available, weather responses also include the location time zone. Optional fields can be null. The registry publishes each product's row schema, including observation timestamps and forecast period boundaries. A canvas can use several aliases together with music, account data or any other authorized capability. Layout, conditions and actions come from the prompt.

| Product | Data |
| --- | --- |
| `weather.current` | Current station observation, rounded whole Fahrenheit temperature, Celsius temperature, observation time, conditions, humidity, wind and precipitation where reported |
| `weather.hourly` | Up to 48 hourly forecast periods, with valid times, temperature, conditions and available precipitation and wind fields |
| `weather.daily` | Up to 14 day and night forecast periods |
| `weather.alerts` | Active official alerts for the selected point, with severity, applicable times and available guidance |
| `geography.place` | One public city or venue, including stable IDs, name, region, coordinates and coordinate provenance |
| `geography.shows` | Up to 48 upcoming, geocoded shows from the published catalog, nearest first by straight-line distance from the selected city or venue |
| `map.boundaries` | Simplified Census state and territory boundaries for a selected place or the U.S. overview |
| `location.proximity` | One-time, viewer-consented proximity result against a selected public point and radius |
| `earthquakes.recent` | Up to 120 recent USGS events, with a choice of worldwide M2.5+ or M4.5+ feeds covering the past 24 hours or seven days |

The weather adapter uses the National Weather Service. Public place coordinates come from the bundled U.S. Census Gazetteer. Venue coordinates use the city's representative point, so distances and forecasts describe the area around the venue. Upcoming shows follow the public calendar's UTC date boundary. Each `date` is the published date or local date and time, with no inferred time zone. [NWS API documentation](https://www.weather.gov/documentation/services-web-API) · [U.S. Census Gazetteer Files](https://www.census.gov/geographies/reference-files/time-series/geo/gazetteer-files.html)

A weather alias with **Any upcoming published venue** selected accepts `await StonefellowData.get(alias, {location_id: show.location_id})`. A canvas can choose a show at runtime from `geography.shows` and pass its returned location ID to the weather source. The host verifies the selected scope and checks that the show is still upcoming, public and geocoded. Fixed locations use `get(alias)`. Admins control geography and weather separately in the canvas permission matrix.

Read `map.boundaries` with `StonefellowData.get(alias)`. Group rows by `region`, `part` and `ring`, sort by `chunk`, and concatenate the parsed `coordinates` arrays. Each point is `[longitude, latitude]`. Ring 0 is the polygon exterior and subsequent rings are holes. The overview generalizes smaller components. Attribute the Census source and draw the geography in the authored layout. The geometry is bundled, so the canvas can use it without external tile requests.

To attach `location.proximity`, enter the public target latitude and longitude and a radius from 10 to 10,000 meters. This target can be shared with the canvas. From a viewer interaction, call `await StonefellowData.checkProximity(alias)`. The host asks the viewer to check their device location once and returns one status, `inside`, `outside`, `uncertain`, `denied` or `unavailable`. The site compares the browser's accuracy estimate with the boundary and keeps the viewer coordinates in the host page. Treat the result as input to the experience. Server-side access rules need independent authorization. Admins can disable map data and device proximity separately.

Read `earthquakes.recent` with `StonefellowData.get(alias)`. The selected feed determines the time window and USGS magnitude category. Rows contain an event ID, location label, longitude, latitude, depth in kilometers, magnitude and its scale, occurrence and revision timestamps in UTC, review status, and a USGS event-page link. Use `event_id` to reconcile updates. The host keeps the newest 120 events by occurrence time and may return a stale snapshot from an earlier feed window if the provider is temporarily unavailable. Admins can disable USGS independently of geography, map boundaries, and weather. [USGS GeoJSON summary feeds](https://earthquake.usgs.gov/earthquakes/feed/v1.0/geojson.php)

The host resolves the alias against the current saved owner canvas or approved gallery. It rechecks the provider policy and location on each read. Detached verification uses an unavailable state so it can test the generated code without depending on live external data. Prefetch before a playback gesture. For a rule based on a current observation, check the returned status, expiry and requested field again when taking the action.

These resources are inventory for the coding agent. The agent authors the experience described by the user. Selected canvases provide their actual prompts, code and authorized image metadata. Selected commerce rows supply live `published_merch` and `published_ticket_offers` bindings with all selected variants. No catalog widget is added by the picker. Existing source, code and privacy context budgets still apply; the inventories and selected runtime rows do not use a three-item sample.

The ordinary chat agent can route a design conversation to the canvas discussion service without a special command phrase. It passes the original message and the browser’s current selections. The service can answer a question, discuss a direction, or propose a project using the account’s history and explicit preferences. A proposed project uses the existing approval setting. Discussion never grants build approval. Planning and discussion use per-user Active Tokens with idempotent request IDs and the existing reservation system.

Project cards include **Like**, **Dislike** and an optional reason. The footer’s **Feedback** action opens the same controls for the current saved canvas. Feedback can be edited or cleared. **Cancel** ends a project without recording a dislike. Private feedback informs subsequent ideas and discussion; account history clearing and closure remove it, and account exports include it.

The canvas resource endpoint supports `kind=canvas|merch|tickets|playlists|stories|lyrics|me`. A guest can read published artist playlists, stories and lyric availability. Signed-in users also receive their own playlists. New project interfaces include POST `api/canvas-projects.php` actions `discuss` and `feedback`. Saved `artist_inputs` is an additive history field with independently optional artist resource scopes. Existing music, images, playback, project approval and legacy API contracts remain in place.
