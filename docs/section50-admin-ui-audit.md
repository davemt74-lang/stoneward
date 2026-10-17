# Section 50 — Admin UI and Workflow Certification

**Source baseline:** v1.3.49, `main` at `2c7507d6bd2b516e2eaed4be41dc66506e780b4c`.
**Scope:** 30 Admin navigation views (29 historical baseline plus Team Workspace), authenticated Admin shell, shared page headers and form overlays.
**Audience:** Single-artist Stonefellow operators.

## 50A — Page-by-page static UI audit

The audit classifies each view by the existing owner of its markup and primary action. **Header standardization** means a consistent page title, responsive gutters and a page-level action when a safe action already exists. No new Create button is invented for purely informational or status views.

| Sidebar # | View | Primary editor / existing action | UI strategy |
| --- | --- | --- | --- |
| 01 | Dashboard | Existing KPI cards and quick links | Standard header + responsive panels |
| 02 | Catalog | Catalog read/list, track metadata | Standard header; legacy Track form in governed overlay |
| 03 | Media Library | Upload media; Gallery/Video managers | Promote upload/new controls; retain native media dialog |
| 04 | Folder Scan & Upload | Folder scan, import, progress | Preserve existing scanner; responsive progress/table |
| 05 | Metadata Templates | New template | Page-header action, governed template form overlay |
| 06 | Releases | New release | Page-header action and release editor overlay |
| 07 | Distribution + Publishing | Existing distribution/sync actions | Preserve publish confirmations; responsive panels |
| 08 | EPK + Press | New EPK, contact, coverage | Promote create actions; EPK overlay; existing contact modals |
| 09 | Rights + Licensing | New rights party | Promote existing action; retain scoped native editor |
| 10 | Shows + Live | New show | Page-header action and show editor overlay |
| 11 | Fans + CRM | Read/CRM operations | Responsive list and original permissions |
| 12 | Segments + Automations | New segment and automation | Page-header actions and respective overlays |
| 13 | Campaigns | New campaign | Page-header action, existing campaign builder and reviews |
| 14 | Merch + Products | New product | Page-header action and product editor overlay |
| 15 | Knowledge Base | New folder, upload files | Both controls in header; folder-content operations remain local |
| 16 | Orders | Existing order-management actions | Responsive tables, governed lifecycle actions |
| 17 | Fulfillment + Care | New shipment | Page-header action with existing shipment modal |
| 18 | POD Handoffs | Existing POD handoff actions | Preserve handoff permissions and state |
| 19 | Users + Tokens | Existing account/token actions | Preserve admin ownership and existing forms |
| 20 | Monthly Packages | New package | Page-header action and package editor overlay |
| 21 | Membership + VIP | New member content | Page-header action and member-content editor overlay |
| 22 | Tickets + VIP | New offer | Page-header action and ticket editor overlay |
| 23 | AI Providers | Existing provider configuration | Preserve secrets and per-provider validation |
| 24 | Agent Brain | Existing right-side Brain drawer | No conflicting composer or duplicate header |
| 25 | Customer Lifecycle | Existing lifecycle management | Preserve approvals, privacy and messaging policy |
| 26 | Performance Intelligence | Analytics, read-only | Responsive reports and tables |
| 27 | Operating Calendar | New launch plan, milestone | Header actions and plan editor overlay |
| 28 | Operations | Health, backups and diagnostics | Preserve existing destructive-operation guards |
| 29 | Settings | Ten-tab workspace, + New Story | Retain Settings tabs and specialized Story modal |
| 30 | Team Workspace | Assign multiple staff roles; inspect named, timestamped operational activity | New, separate role-scoped team module; does not alter Admin ownership or permissions |

## 50B — Header actions and editor lifecycle

The shared UI now checks computed visibility of **all ancestors**, not just the element itself, before promoting New/Add buttons. Both Knowledge Base creation and upload are header actions. The original click listeners and DOM nodes remain untouched. The 13 legacy form contracts and canonical Back actions are preserved. Editor dismissal restores focus to the original creation control where possible.

## 50C — Form, save and approval preservation

The common UI never calls an Admin API or writes data itself. Save, delete, publish, consent, campaign send, uploads, moderation and order transitions continue through the original controllers. Unsaved text input/change is tracked; Cancel, Back, Escape and backdrop close require confirmation. A failed save must not silently clear draft state. Special existing media, gallery, story and shipment modals remain independent.

## 50D — Responsive and accessible shell

Page, nested grid, table, form and panel width constraints apply across all 29 views. Tables scroll within their own containers; the full Admin page must not gain horizontal overflow. Small screens use a full-viewport editor with safe-area padding; header actions wrap in rows rather than overflow; keyboard focus remains in the active dialog and returns to the originating header action.

## 50E — Artist Homepage and Story end-to-end linking

The homepage section editor now offers **Upload / choose hero image**, **Edit artist story**, and **Preview homepage**. These use the *same Settings tab controller* and existing universal media uploader, with its published `site/public/hero` asset contract. Artist biography is edited in the original Story modal; published Artist About artwork populates the homepage Artist Story section through the existing backend. No second homepage or story source of truth was created.

## 50F — Certification and release

Mandatory release gate: each Section 50A–F test, existing Section 41–47 and v1.3 UI regressions, PHP lint, JavaScript syntax, PR and post-merge main CI, pinned certified ZIP plus SHA-256. A release is not called certified until every gate is green.

**Known limit:** Browser execution in this runner is blocked at Chromium startup. Automated code assertions and CI establish functional integration contracts, not proof that every production browser/device renders perfectly. Operator live acceptance remains necessary for upload/edit/publish and mobile layout. No MySQL schema change or administrator password reset is intended in this UI release.
