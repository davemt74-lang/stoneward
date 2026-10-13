# Stonefellow v1.3.48 — Settings Tabs and Focused Story Editor

The Admin **Settings** page has ten individually labelled tabs, one for each existing settings section: Public Site, Visual Theme, Artist Homepage, Stories, Site & Artist Media, Folder Access, Upload Limits, Password, Database Upgrade and Admin Session.

Tabs are horizontally scrollable on narrow screens, expose WAI-ARIA tab and panel relationships, support Left/Right/Home/End keyboard controls and preserve unsaved fields by keeping panels in the DOM. Expensive editors load when first opened; no separate route or alternate settings backend was added.

The **+ New Story** action is at the top of Settings regardless of selected tab. It selects Stories and opens an accessible focused modal. Clicking an existing story opens the same editor in the modal. Escape, the Close control or clicking the backdrop offer to discard unsaved edits. A focus loop retains keyboard focus inside the modal, and focus returns to the opener on close.

After saving a new story, the modal stays open with that story record, allowing its **Hero**, **Inline**, and **Gallery** artwork to be attached and published using the existing universal media manager. Story publish/archive and deletion still require their existing explicit owner confirmations. No backend permissions, authentication, schema, customer privacy, or administrator passwords are changed.

**Release gate:** PHP acceptance for ten panels, keyboard/ARIA behavior, lazy mounting, modal lifecycle, media wiring, publish approvals and version sync; JavaScript syntax certification for both Story Editor and settings tab controller; all existing Stonefellow regressions, certified PR and main CI, then pinned deployment ZIP/checksum.
