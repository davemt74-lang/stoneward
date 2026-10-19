# Stonefellow G4J — AI Director Production Task Orchestration

**v1.3.132 · Database schema 1.3.65 · Migration 056**

The existing Artist World Creative Director, production Studio and Admin Agent Brain now share **human-governed crew assignments** backed by real capture requests and existing Composer proposal review decisions. No separate agent or unmanaged background worker has been introduced.

## Task creation and assignment

An administrator can explicitly convert an existing *verified coverage gap* into an idempotent private shot task. This reuses a known human-reviewable `story_capture_requests` draft, rather than publishing anything. A second path creates a private producer review task only for an actual saved AI Composer proposal still awaiting review. Duplicate code/proposal submissions return the existing task. Task assignment is permitted only to an active account holding the request's target team role (including multiple simultaneous roles). Revoking that role removes access to the private task immediately.

In Production Review's Creative Director section, administrators can propose these work items, assign them to role-eligible crew members, and examine submitted evidence and notes before accepting or rejecting completion. The same stage is reflected as a bounded approval count inside the Admin Agent Brain; raw source media and creative proposals are never exported.

## Contributor workflow

The logged-in **Creative Capture** page now presents only the person's assigned production tasks. For a shot task, a contributor first responds to the linked request **after** an editor explicitly opens the AI-suggested shot request, then submits their own preexisting capture evidence ID. The task does not transfer evidence or create a new upload behind the contributor's back. For an editorial task, a producer submits their observations against the original Composer proposal ID; the authorized editor still separately reviews the actual Composer content.

Submissions are **submitted for completion review**, not marked completed. A separate authorized editor must inspect the original capture and approve it for editing before marking a shot task complete. For a Composer task, that original AI proposal must already be approved, rejected or applied through the proper human governance workflow. The person who performed the task cannot approve their own completion. Reviewer basis must contain 25+ characters and is preserved in the team activity timeline. Approval of task completion does not authorize publication, external AI processing, likeness use, synchronization licenses, or FFmpeg rendering.

## Safety and certification

Source provenance is verified by original request ID, capture contributor ID, evidence ID and current review state. Role and account activation are rechecked. Assignment, contributor submission and human completion decision roll back if their required activity audit fails. The crew-facing endpoint requires the existing authenticated user and CSRF checks; Admin review requires the existing Admin authorization and is independent of fan/customer Agent Brain state.

Automated checks: `php tests/section88d3g4j-production-tasks.php`, `php tests/section88d3g4j-release.php`, previous G4A–G4I fixtures, MySQL, FFmpeg and release ZIP checks. Real-life media rights, crew onboarding, field camera captures and HostGator deployment require human certification before live production.

**Next candidate:** G4K — production task progress, reassignment/rejection recovery, real shot evidence timelines, and agent-guided contributor notifications without automatic publication.
