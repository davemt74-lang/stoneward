# Admin Agent Chat — One Sticky Footer Composer (v1.3.47)

The permanent ChatGPT-style footer bar is the sole input for Admin Agent Chat. The duplicate inline "Ask Admin Agent" form has been removed. The transcript scrolls between the topbar and composer and is not obscured by it. The old "IDLE" metadata strip is suppressed only on the chat view.

The footer still uses the **same** `SFAdminAgentCanvas.send` controller and authenticated `admin/api/agent-chat.php` endpoint, with its owner-scoped conversation ID, existing consent/approval mechanisms and Agent Brain drawer. There is no new dispatcher or separate Agent execution engine.

Enter sends; Shift+Enter inserts a newline. Dictation uses the same send routine, with a single-send guard. A request disables the controls while sending; on failure, the unsent draft stays in the textarea. The mobile view retains its own scrollable conversation, safe-area padding and independent navigation drawer.

No database migration or password update is required. Application build v1.3.47 bumps Admin and public asset cache versions; database target remains 1.3.46. Live-device smoke testing after deployment is still needed.

Run `php tests/admin-agent-sticky-composer.php` and the complete release suite, then merge only after green CI. 
