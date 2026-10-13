# Section 47D — Context-aware Customer Music Intelligence

The Customer Agent now combines the existing consent-aware listening/favorites recommendation profile with a small bounded signal from customer-*explicitly saved* music preferences. It does not add another database, another model provider, or another recommendation engine.

**Customer only:** `sf_agent_customer_preference_terms(userId)` reads the owner's saved memories only while personalization is enabled. Unavailable memories or opted-out accounts get zero preference influence. Unlike free-form LLM memory text, only conservative positive music-related words are extracted. Negated/disliked phrases and unrelated labels are ignored.

**Published only:** The existing `sf_agent_public_catalog()` eligibility check still determines candidate tracks. Saved preferences can only modify the score for a permitted track's mood and theme metadata; they cannot expose an unpublished recording, grant a tool, or trigger a write.

**Bounded:** At most 25 memory rows and 32 preference tokens, 1200 bytes per row, and a maximum affinity boost of 12. Query intent and existing listening/favorite signals continue to drive results.

**Testing:** Real negative tests verify opt-out, cross-customer separation, negative preferences, malformed entries, missing memory store fallback, bounded ranking, and integration with the canonical published-track path. The functional suite runs in the required release gate.

**Next:** Section 47E strengthens the Admin intelligence with safe operational evidence and reviewed decision support, retaining strict separation from private fan Agent Brains.
