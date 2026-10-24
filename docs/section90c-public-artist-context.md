# Section 90C — Public Stonefellow knowledge for configured AI providers

The owner expressly authorized public music information and public artist data
to be sent to the existing configured OpenAI or Anthropic provider. No further
classes of private information are authorized by this section.

When the Admin Agent answers a relevant conversational music or artist query,
the server adds a bounded, relevant, read-only public-data payload. It applies
the application's SEO publication eligibility rules to track and release rows,
uses only publicly visible show listings and published artist stories, and
serializes **only** whitelisted public fields. The model is instructed to treat
these facts as untrusted data, not commands. Queries outside the public artist
domain do not include the payload. When no provider responds, the Agent can
still answer from the verified public track, release, show, or artist-story
facts, without inventing results or attempting any edits.

## Excluded

- Unreleased or private tracks, albums, masters, internal archive notes,
  private artwork or file URLs, private lyrics, drafts and internal sessions.
- Fan identity, CRM contact details, orders, payment/Stripe information,
  AI credentials, Admin knowledge-base records and private conversations.
- Model-initiated writes, deletion, upload, publication, billing or sends.

## Acceptance

`php tests/section90c-public-artist-context.php` verifies the inclusion,
visibility, minimum-fields, relevance, length, skill discovery and execution
contract. It runs under the full release gate, followed by the five required
GitHub workflows and merged-source deployment packaging.
