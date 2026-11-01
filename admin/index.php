<?php
declare(strict_types=1);
$root=dirname(__DIR__);
define('SF_ROOT',$root);
require_once __DIR__.'/api/bootstrap.php';
if(!sf_installed()){http_response_code(503);exit('Stonefellow database configuration is unavailable.');}
$adminUser=sf_current_user();
if(!$adminUser||($adminUser['role']??'')!=='admin'){header('Location: login.php');exit;}
define('SF_ADMIN_BUILD','1.3.198');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Stonefellow-Build: '.SF_ADMIN_BUILD);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#080d19">
  <meta name="robots" content="noindex,nofollow">
  <title>Stonefellow Admin</title>
  <link rel="stylesheet" href="assets/admin.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/campaigns.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/agent-actions.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/agent-canvas.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/agent-catalog-review.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/production-review.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/story-assembly.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/media.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/stem-studio.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/video.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/gallery.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/products.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/automations.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/membership.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/ticketing.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/fulfillment.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/operating-calendar.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/rights.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/distribution.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/press.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/inquiries.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/homepage.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/homepage-web-editor.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/stories.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/settings-tabs.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/stripe-connect.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/commerce-ops.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/unified-ui.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/songbook.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/theme.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/agent-mesh-theme.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
</head>
<body>
  <div id="adminApp" class="admin-app">
    <aside id="sidebar" class="sidebar">
      <div class="sidebar-brand"><a href="../" target="_blank" rel="noopener">STONEFELLOW</a><span>ADMIN</span></div>
      <nav id="adminNav" aria-label="Admin navigation">
        <details class="admin-nav-category" data-nav-category="overview" open>
          <summary class="admin-nav-summary"><span>Overview</span><span class="admin-nav-chevron" aria-hidden="true"></span></summary>
          <div class="admin-nav-items">
            <button type="button" data-view="dashboard" class="active"><span>01</span>Dashboard</button>
            <button type="button" data-view="analytics"><span>26</span>Performance Intelligence</button>
            <button type="button" data-view="calendar"><span>27</span>Operating Calendar</button>
          </div>
        </details>
        <details class="admin-nav-category" data-nav-category="music">
          <summary class="admin-nav-summary"><span>Music &amp; Media</span><span class="admin-nav-chevron" aria-hidden="true"></span></summary>
          <div class="admin-nav-items">
            <button type="button" data-view="catalog"><span>02</span>Catalog</button>
            <button type="button" data-view="media"><span>03</span>Media Library</button>
            <button type="button" data-view="uploads"><span>04</span>Folder Scan & Upload</button>
            <button type="button" data-view="templates"><span>05</span>Metadata Templates</button>
            <button type="button" data-view="releases"><span>06</span>Releases</button>
            <button type="button" data-view="shows"><span>10</span>Shows + Live</button>
          </div>
        </details>
        <details class="admin-nav-category" data-nav-category="publishing">
          <summary class="admin-nav-summary"><span>Publishing &amp; Rights</span><span class="admin-nav-chevron" aria-hidden="true"></span></summary>
          <div class="admin-nav-items">
            <button type="button" data-view="distribution"><span>07</span>Distribution + Publishing</button>
            <button type="button" data-view="press"><span>08</span>EPK + Press</button>
            <button type="button" data-view="rights"><span>09</span>Rights + Licensing</button>
          </div>
        </details>
        <details class="admin-nav-category" data-nav-category="audience">
          <summary class="admin-nav-summary"><span>Audience &amp; Marketing</span><span class="admin-nav-chevron" aria-hidden="true"></span></summary>
          <div class="admin-nav-items">
            <button type="button" data-view="crm"><span>11</span>Fans + CRM</button>
            <button type="button" data-view="customer"><span>25</span>Customer Lifecycle</button>
            <button type="button" data-view="campaigns"><span>13</span>Campaigns</button>
            <button type="button" data-view="automations"><span>12</span>Segments + Automations</button>
          </div>
        </details>
        <details class="admin-nav-category" data-nav-category="commerce">
          <summary class="admin-nav-summary"><span>Commerce &amp; VIP</span><span class="admin-nav-chevron" aria-hidden="true"></span></summary>
          <div class="admin-nav-items">
            <button type="button" data-view="products"><span>14</span>Merch + Products</button>
            <button type="button" data-view="orders"><span>16</span>Orders</button>
            <button type="button" data-view="fulfillment"><span>17</span>Fulfillment + Care</button>
            <button type="button" data-view="pod"><span>18</span>POD Handoffs</button>
            <button type="button" data-view="membership"><span>21</span>Membership + VIP</button>
            <button type="button" data-view="ticketing"><span>22</span>Tickets + VIP</button>
            <button type="button" data-view="packages"><span>20</span>Monthly Packages</button>
          </div>
        </details>
        <details class="admin-nav-category" data-nav-category="agent">
          <summary class="admin-nav-summary"><span>Agent &amp; Knowledge</span><span class="admin-nav-chevron" aria-hidden="true"></span></summary>
          <div class="admin-nav-items">
            <button type="button" data-view="brain"><span>24</span>Agent Brain</button>
            <button type="button" data-view="production-review"><span>24A</span>AI Production Review</button>
            <button type="button" data-view="knowledge"><span>15</span>Knowledge Base</button>
            <button type="button" data-view="ai"><span>23</span>AI Providers</button>
          </div>
        </details>
        <details class="admin-nav-category" data-nav-category="administration">
          <summary class="admin-nav-summary"><span>Administration</span><span class="admin-nav-chevron" aria-hidden="true"></span></summary>
          <div class="admin-nav-items">
            <button type="button" data-view="users"><span>19</span>Users + Tokens</button>
            <button type="button" data-view="team"><span>19A</span>Team Workspace + Timeline</button>
            <button type="button" data-view="operations"><span>28</span>Operations</button>
            <button type="button" data-view="settings"><span>29</span>Settings</button>
          </div>
        </details>
      </nav>
      <div class="sidebar-foot"><span id="adminStatusDot"></span><span>Local admin</span><a href="operations-health.php" title="View administrator operational diagnostics">Health</a></div>
    </aside>

    <div class="workspace">
      <header class="admin-topbar">
        <button id="sidebarToggle" class="mobile-menu" type="button" aria-label="Open admin menu">☰</button>
        <div class="admin-topbar-heading"><div class="eyebrow">STONEFELLOW ADMIN</div><div id="viewTitle" class="view-title">Dashboard</div></div>
        <div class="admin-topbar-actions">
          <button type="button" class="admin-notifications-button" id="adminNotificationsButton" aria-label="Open Agent Brain notifications and verified activity history" aria-haspopup="dialog" title="Agent Brain · Notifications · History">
            <svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
            <span class="admin-notifications-count" id="adminNotificationsCount" hidden></span>
          </button>
          <div class="admin-profile-shell" id="adminProfileShell">
            <button id="adminProfileToggle" class="admin-profile-toggle" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="adminProfileDropdown" aria-label="Open account menu for <?=htmlspecialchars((string)$adminUser['display_name'],ENT_QUOTES,'UTF-8')?>">
              <img class="admin-profile-avatar" id="adminHeaderAvatar" src="api/profile-photo.php" width="38" height="38" alt="">
              <span class="admin-profile-toggle-name"><?=htmlspecialchars((string)$adminUser['display_name'],ENT_QUOTES,'UTF-8')?></span>
              <svg class="admin-profile-chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m5 7 5 5 5-5"/></svg>
            </button>
            <div id="adminProfileDropdown" class="admin-profile-dropdown" aria-label="Account and Admin menu" hidden>
              <div class="admin-profile-identity">
                <img class="admin-profile-avatar admin-profile-avatar-large" id="adminProfileAvatar" src="api/profile-photo.php" width="50" height="50" alt="Your profile picture">
                <div><strong><?=htmlspecialchars((string)$adminUser['display_name'],ENT_QUOTES,'UTF-8')?></strong><small><?=htmlspecialchars((string)$adminUser['email'],ENT_QUOTES,'UTF-8')?></small><span>Administrator</span></div>
              </div>
              <button id="adminProfileChangePhoto" type="button" class="admin-profile-photo-action">Change profile photo</button>
              <input id="adminProfilePhotoInput" type="file" accept="image/jpeg,image/png,image/webp" hidden aria-label="Upload profile photo">
              <p id="adminProfilePhotoStatus" class="admin-profile-photo-status" role="status" aria-live="polite" hidden></p>
              <div class="admin-profile-menu-group">
                <a href="../?view=account" target="_blank" rel="noopener">My Account &amp; Profile <span aria-hidden="true">↗</span></a>
                <button type="button" data-profile-view="settings">Admin Settings</button>
                <button type="button" data-profile-view="users">Users &amp; Access</button>
                <button type="button" data-profile-view="brain">Agent Brain &amp; History</button>
                <button type="button" data-profile-view="operations">Operations &amp; Health</button>
              </div>
              <div class="admin-profile-menu-group admin-profile-menu-group-bottom">
                <a href="../" target="_blank" rel="noopener">View Public Site <span aria-hidden="true">↗</span></a>
                <a href="logout.php" class="admin-profile-logout">Log Out</a>
              </div>
            </div>
          </div>
        </div>
      </header>

      <main id="adminCanvas" class="admin-canvas" aria-live="polite"></main>

      <aside id="adminChatPopup" class="admin-chat-popup" role="dialog" aria-modal="false" aria-label="Stonefellow Admin Agent conversation" hidden>
        <header class="admin-chat-popup-head">
          <div><strong>Stonefellow Admin Agent</strong><span id="adminChatPopupState">PRIVATE CONVERSATION</span></div>
          <div class="admin-chat-popup-actions">
            <button type="button" id="adminChatPopupVoice" aria-pressed="true" aria-label="Toggle spoken Agent replies">Voice on</button>
            <button type="button" id="adminChatPopupNew">New chat</button>
            <button type="button" id="adminChatPopupFull">Full chat</button>
            <button type="button" id="adminChatPopupClose" aria-label="Close Admin Agent chat">×</button>
          </div>
        </header>
        <div id="adminChatPopupMessages" class="admin-chat-popup-messages" role="log" aria-live="polite" aria-relevant="additions text"></div>
        <p class="admin-chat-popup-hint">Type or speak using the bar below. Open Full chat to review detailed actions and approvals.</p>
      </aside>

      <footer class="admin-chat-footer" aria-label="Admin Agent sticky composer">
        <div id="adminAgentMusicBar" class="admin-agent-music-bar" aria-label="Admin Agent music player" hidden>
          <span id="adminAgentMusicTitle" class="admin-agent-music-title">No song loaded</span>
          <button id="adminAgentMusicPrevious" type="button" aria-label="Previous Stonefellow song" title="Previous song">◀◀</button>
          <button id="adminAgentMusicToggle" type="button" aria-label="Pause Stonefellow music">Ⅱ</button>
          <button id="adminAgentMusicNext" type="button" aria-label="Next Stonefellow song" title="Next song">▶▶</button>
          <label class="admin-agent-music-volume">Volume <input id="adminAgentMusicVolume" type="range" min="0" max="100" value="70" aria-label="Admin music volume"></label>
        </div>
        <audio id="adminAgentMusicAudio" preload="none"></audio>
        <div class="agent-strip"><button id="adminOrb" type="button" class="admin-orb" aria-label="Stonefellow Admin Agent"></button><span id="agentStatus">IDLE</span><p id="agentMessage">Ask me to scan a folder, create a metadata template, find missing ISRCs, or show the catalog.</p></div>
        <form id="adminChatForm" class="admin-chatbar" autocomplete="off">
          <button type="button" id="adminChatPopupToggle" class="chat-button" aria-label="Open Admin Agent conversation" aria-expanded="false" aria-controls="adminChatPopup">▤</button>
          <label class="sr-only" for="adminChatInput">Ask Stonefellow Admin</label>
          <textarea id="adminChatInput" rows="1" maxlength="4000" placeholder="Message your Admin Agent…" aria-label="Message your Admin Agent"></textarea>
          <button id="adminAgentSpeechStop" type="button" class="chat-button" hidden aria-label="Stop Admin Agent speech and resume listening" title="Stop speaking · listen">■</button>
          <button id="adminVoiceButton" type="button" class="chat-button" aria-label="Voice input">◉</button>
          <button type="submit" class="chat-button send" aria-label="Send message">↑</button>
        </form>
        <span id="adminAgentComposerStatus" class="sr-only" role="status" aria-live="polite"></span>
      </footer>
      <button id="adminFloatingAgent" class="admin-floating-agent" type="button"
              data-mode="idle" aria-label="Open or close Stonefellow Admin Agent conversation" aria-pressed="false"
              title="Toggle Admin Agent conversation · microphone available in the chat bar">
        <span class="admin-floating-ring" aria-hidden="true"></span>
        <span class="admin-floating-core" aria-hidden="true"><canvas class="admin-floating-points" aria-hidden="true"></canvas><span class="admin-floating-eyes"><i></i><i></i></span><span class="admin-floating-mouth"></span></span>
        <span class="admin-floating-caption" id="adminFloatingAgentState">READY</span>
      </button>
    </div>
  </div>

  <input id="folderInput" type="file" webkitdirectory directory multiple accept=".mp3,.wav,.wave,audio/mpeg,audio/wav" hidden>
  <script src="assets/analytics.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/team-workspace.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/director-capture.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/production-review.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/story-assembly.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/settings-tabs.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/sidebar-accordion.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="../assets/js/conversation-barge-in.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="../assets/js/speech-segments.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="../assets/js/agent-audio-player-skill.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/agent-music-controller.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/admin.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>&amp;e5=1"></script>
  <script src="assets/agent-particle-orb.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/speech-engine-pilot.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/stripe-connect.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/commerce-ops.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/campaigns.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/agent-actions.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/agent-commerce-review.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/agent-catalog-review.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/agent-knowledge-review.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/agent-media-review.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/agent-media-link-review.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/agent-canvas.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/media.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/stem-waveform.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>\n  <script src="assets/stem-timeline.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/stem-fx-engine.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/stem-plugin-rack.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/stem-automation.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/stem-mastering.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/stem-intelligence.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/stem-production-advisor.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/stem-alignment.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/stem-studio.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/video.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/gallery.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/products.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/turntable-modules.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/automations.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/membership.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/ticketing.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/fulfillment.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/operating-calendar.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/rights.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/distribution.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/press.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/inquiries.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/homepage-builder.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/homepage.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="../assets/js/artist-home-renderer.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/homepage-visual.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/homepage-web-editor.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/stories.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/songbook.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/theme.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/unified-ui.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
</body>
</html>
