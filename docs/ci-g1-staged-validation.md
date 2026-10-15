# CI-G1 — Stonefellow staged validation

Before this change, the full PHP/JS/MySQL release suite ran on every push
to `feat/**` and again on every pull request update, duplicating expensive
certification while an implementation was still being edited.

## Draft PR
- GitHub starts the `pr-smoke` job and validates all changed PHP and
  JavaScript source files with PHP/Node syntax tools.
- Unsafe paths or a missing PR base revision fail closed.
- Feature-specific workflows remain active for their scoped source files.
- The long PHP/JS regression and real MySQL certification jobs are
  deliberately skipped. **A skipped draft check is never a 10/10.**

## Candidate PR (Ready for review)
- Mark the PR ready only when the section is a reviewed candidate.
- Both full release-gate and real MySQL certification run for the current
  candidate; subsequent edits to that non-draft PR rerun both.
- Never merge without exact-revision green acceptance.

## Main and deployment
- Every merge to `main` still runs the entire release gate and MySQL checks.
- The existing `workflow_run` deploy packaging only publishes when the
  full `main` Release Gate passes; it checks out the exact certified SHA.
- No production payment, provider or host integration setting is changed.

## Verification
`python3 tests/ci_g1_release_policy.py` asserts all stage triggers, the
preserved certified deploy gate and the changed-file lint input boundaries.
