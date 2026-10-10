# Stonefellow v1.3.27 — Section 28: Rights, Credits & Licensing Registry

Stonefellow remains a **single-artist direct-to-fan platform**. Section 28 adds an authoritative Admin rights layer for the catalog and releases without pretending to be a royalty-accounting service.

## Rights Registry

Admin now has a first-class **Rights + Licensing** workspace connected to the existing Stonefellow catalog.

It manages:

- Rights parties: writers, composers, publishers, artists, labels, master owners and licensors
- One rights work per Stonefellow catalog track
- ISWC and PRO work identifiers
- Composition copyright
- Master copyright
- Publisher and territory metadata
- Composition ownership splits
- Master ownership splits
- Track/release license and clearance records
- Work and release rights-readiness status

## Ownership math

Stonefellow stores ownership as integer **basis points**.

- 10,000 basis points = 100%
- Composition ownership must total exactly 100%
- Master ownership must total exactly 100%

A work is rights-ready only when both ownership groups equal exactly 100% and no recorded license is pending, restricted or expired.

Stonefellow does **not** infer ownership percentages from existing writer/composer credit text.

## Registration vs readiness

ISWC, PRO work ID and registration status are tracked, but registration is not falsely treated as mandatory for every unreleased work.

Supported registration states:

- draft
- submitted
- registered
- not applicable

Rights readiness and registration state remain separate concepts.

## Licensing

Track and release rights can record:

- sync
- mechanical
- master use
- sample
- cover
- remix
- name / likeness
- other

Each record may include licensor, status, territory, start/end dates, reference/contract ID, terms and notes.

Pending/restricted/expired clearances block readiness. Cleared licenses expiring within 60 days generate a warning.

## Release clearance

Release readiness rolls up:

- every track’s composition ownership
- every track’s master ownership
- track-level license blockers
- release-level licenses

This gives Admin a single pre-release rights checkpoint without changing the existing Release Builder’s catalog/release authority.

## Catalog migration

Migration **2026-10-10-024** creates rights work records for the current catalog and safely copies existing descriptive rights metadata where present:

- title
- copyright year
- composition copyright
- master copyright
- publisher
- ISWC / PRO work ID if those fields already exist

The migration does **not** create parties or ownership splits from free-text credits.

## Governance + Agent Brain

Rights metadata edits are audited.

Ownership split changes are treated as consequential. Split/license deletion requires explicit confirmation.

Admin Agent Brain records:

- work metadata updates
- rights party updates
- ownership split changes
- license/clearance changes
- catalog synchronization

The Admin Agent routes questions about rights, ownership, splits, ISWC, PRO registration, licensing and clearance to this workspace.

Private ownership data is not added to public Agent context.

Application: **1.3.27**  
Database schema target: **1.3.23**
