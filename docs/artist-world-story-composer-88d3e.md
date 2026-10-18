# Stonefellow 88D3E — Supervised AI Story Composer

**Release v1.3.118** · **Database schema 1.3.55** · **Migration 2026-10-18-046**.

## What it does

Under **Admin → Agent & Knowledge → AI Production Review → AI Director Editorial Assembly**, editors can explicitly enable the Story Composer, request a composition for the current saved timeline revision, review an AI-proposed sequence, then separately **approve** and **apply** a new ordering. Proposal approval alone never changes an assembly.

The model can suggest only a permutation of existing verified source positions, a short logline, editorial transitions and up to five missing-shot ideas. Model output cannot create or attach new media, songs, users, source identifiers, external links, or new capture requests. It cannot render or publish video. All suggestions are private editorial drafts. The previous rules-based Director coverage and missing-shot drafts remain available.

## Model egress and rights

The explicit opt-in is **off by default**. This is a paid-model feature and every user-requested proposal counts against a maximum of **three proposals per UTC day across the installation**. It reuses encrypted OpenAI/Anthropic provider configuration, fixed HTTPS provider destinations, server-side timeouts and redirect prohibition.

On each requested proposal the server derives a limited JSON payload: short editor-supplied assembly title and premise, numbered source indices, verified short scene/track descriptions, source kinds and placement durations. It does **not** send source media, media URLs, full dialogue or transcripts, participant identities, raw database records, or credential contents. Contributor footage/transcript summaries require **independently verified external-AI rights clearance** at the time of request and again immediately before/after each provider call.

Provider output is treated as untrusted data, decoded as strict JSON, and checked for a complete permutation of the current source indices. Invalid or uncertain provider responses become interrupted; they are never automatically replayed or charged again for the same revision. A changed assembly or revoked source clearance makes prior proposals ineligible for application.

Publication consent is independent of AI-processing consent; selecting Stonefellow music is **not proof of synchronization or master-use licensing**.

## Operate

1. Back up the installation and apply migration 046 through `upgrade.php`.
2. Configure a supported OpenAI/Anthropic provider in the existing server-side Admin AI settings.
3. Independently clear the external-AI use of **every** captured source in the assembly.
4. Save an assembly containing 2–30 verified/canonical source items. Enable AI Composer explicitly in the Admin interface, then click **Request AI composition**.
5. To execute **one** queued proposal, run from the server terminal:
   `php scripts/artist-world-story-composer.php --editor-id=EDITOR_ID`
6. Return to Admin, refresh and review the proposal. **Approve** or **Reject** the content; only then **Apply approved ordering** to create a new private timeline revision.

No browser endpoint invokes a provider, and CLI processing is **not** an unattended daemon.

## Verification and limits

Automated CI verifies rights gates, provider request shapes using mocked OpenAI/Anthropic responses, rejected duplicate/invalid indices, duplicate billing prevention, daily spend-attempt limits, stale-revision rejection, human approval and application, MySQL 8 schema and artifact checksums.

This is a metadata-based editorial composer, **not** a full screenplay-writing model with access to private footage, a multi-shot video editor, or a production rendering service. Paid provider and HostGator field certification still require live testing. No automatic soundtrack license selection, video publishing or film rendering is included.
