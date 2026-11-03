# Stonefellow 91B17A — Private Agent Brain continuity

Deployment candidate: **v1.3.207**. Built on v1.3.206 without replacing the proven Agent Canvas, player, queue, or speech controls.

## Changes

1. **Explicit conversational memory commands** in authenticated `agent-chat.php`:
   - `Stonefellow, remember that I prefer acoustic songs.`
   - `Remember listening mood: late-night introspective tracks.`
   - `What do you remember about me?`
   - `Forget listening mood.` (the exact saved label is required; no fuzzy deletion).
2. **Private conversation recall** responds to `What did we discuss about the playlist?`, `What did we talk about last time?`, or `Search my chats for Earth Girl.` Results contain earlier **user statements**, timestamps, and a disclaimer that they are not verified action outcomes. Recall searches up to 180 recent owner messages and returns at most four excerpts, each up to 320 characters.
3. **Agent Brain UI** has a native, theme-aware, 16px-mobile-input search panel for prior chats. Search results provide an `Open conversation` action. The API returns up to six owner-only excerpts upon an explicit search request.
4. **Consent and privacy:** the existing Account → Privacy Personalization switch governs private search and explicit saves. The saved note store remains capped at 25 records per user; notes are never created automatically from conversations. Common secret labels are excluded from recall and rejected when saving a new note. Memory commands are executed and acknowledged by local server policy, not language-model speculation.
5. **External model boundary:** archived conversation excerpts are **not added to prompts sent to Claude, OpenAI, or another remote provider**. Ordinary chat retains the existing last-ten-message context and explicit saved-preference behavior. Extension of cross-conversation context to an online LLM requires distinct, informed user approval and privacy design.

## Architecture and reliability

- Authenticated route: `sf_agent_reply` checks local remember/forget/list/search intent before orchestration or metered language-model calls. The existing owner-scoped `agent_customer_memories` and `agent_messages` tables stay canonical; no new storage or migration.
- Read isolation: `sf_agent_cross_thread_recall` accepts a trusted server-side `userId`, uses prepared SQL `user_id=?` and `role='user'`, then scores and bounds excerpts locally. The current query is not returned as its own recollection.
- Retrieval is not a claim that the user completed an action, and previous assistant claims are not presented as facts. Search is intentionally approximate, not semantic embeddings.
- Search excerpts are deliberately **not persisted into assistant chat messages**; the saved ledger contains a generic search marker. Otherwise a later normal LLM chat turn could accidentally forward those excerpts as part of ordinary recent-message history.
- Existing verified browser action and player adapters remain unchanged. The normal on-device voice-to-chat path uses the same local memory commands. ElevenLabs Speech Engine has its own external conversation flow and is not retroactively claimed to share these server-backed memory commands.
- If the database read fails, direct memory search reports that search is unavailable; unrelated ordinary chat continues.

## Validation

- `node --test --test-reporter=dot tests/*.cjs` (browser-source regression checks)
- `git diff --check`
- The environment lacks PHP CLI, so real PDO/MySQL behavior, `php -l`, live Claude calls, iPhone microphone behavior, and PHP endpoint integration must be validated on the self-hosted staging site before certification.

## Deployment acceptance

1. Sign in as Customer A, enable Personalization, say `Remember music preference: acoustic recordings`, then ask `What do you remember about me?`. Refresh and verify it persists.
2. Ask about an earlier conversation; confirm a timestamped excerpt from your *own* previous user messages is displayed, not an assistant's unverified promise.
3. Go to **My Agent Brain**, search for a phrase, and choose **Open conversation**. Confirm the existing canvas conversation opens. On iPhone, focusing the search field must not zoom the page.
4. Sign in as Customer B; B must not see A's memories or conversation search results. Turn off A's Personalization and verify saved/previous-chat recall is blocked.
5. Say `Forget music preference` and verify only that labeled memory is removed. Confirm normal music, queue, agent actions, and listening still function as in v1.3.206.

Next slices: governed user-editable Agent identity/personality notes (SOUL profile), a shared voice/text intent receipt, semantic retrieval and fine-grained provider consent, and a verified tool-result memory ledger.
