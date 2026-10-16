<?php
declare(strict_types=1);
$root=dirname(__DIR__);
define('SF_ROOT',$root);
require_once __DIR__.'/api/bootstrap.php';
if(!sf_installed()){http_response_code(503);exit('Stonefellow database configuration is unavailable.');}
$adminUser=sf_current_user();
if(!$adminUser||($adminUser['role']??'')!=='admin'){header('Location: login.php');exit;}
define('SF_ADMIN_BUILD','1.3.90');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Stonefellow-Build: '.SF_ADMIN_BUILD);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#0a0908">
  <meta name="robots" content="noindex,nofollow">
  <title>Stonefellow Admin</title>
  <link rel="stylesheet" href="assets/admin.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/campaigns.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/agent-actions.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/agent-canvas.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
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
            <button type="button" data-view="knowledge"><span>15</span>Knowledge Base</button>
            <button type="button" data-view="ai"><span>23</span>AI Providers</button>
          </div>
        </details>
        <details class="admin-nav-category" data-nav-category="administration">
          <summary class="admin-nav-summary"><span>Administration</span><span class="admin-nav-chevron" aria-hidden="true"></span></summary>
          <div class="admin-nav-items">
            <button type="button" data-view="users"><span>19</span>Users + Tokens</button>
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
        <div><div class="eyebrow">STONEFELLOW ADMIN</div><div id="viewTitle" class="view-title">Dashboard</div></div>
        <button type="button" class="secondary" id="openAdminAgentChat">Admin Agent Chat</button>
        <div class="admin-user-menu"><span><?=htmlspecialchars((string)$adminUser['display_name'],ENT_QUOTES,'UTF-8')?></span><a href="../" target="_blank" rel="noopener" class="public-link">View site ↗</a><a href="logout.php" class="public-link">Log out</a></div>
      </header>

      <main id="adminCanvas" class="admin-canvas" aria-live="polite"></main>

      <footer class="admin-chat-footer" aria-label="Admin Agent sticky composer">
        <div class="agent-strip"><button id="adminOrb" type="button" class="admin-orb" aria-label="Stonefellow Admin Agent"></button><span id="agentStatus">IDLE</span><p id="agentMessage">Ask me to scan a folder, create a metadata template, find missing ISRCs, or show the catalog.</p></div>
        <form id="adminChatForm" class="admin-chatbar" autocomplete="off">
          <label class="sr-only" for="adminChatInput">Ask Stonefellow Admin</label>
          <textarea id="adminChatInput" rows="1" maxlength="4000" placeholder="Message your Admin Agent…" aria-label="Message your Admin Agent"></textarea>
          <button id="adminVoiceButton" type="button" class="chat-button" aria-label="Voice input">◉</button>
          <button type="submit" class="chat-button send" aria-label="Send message">↑</button>
        </form>
        <span id="adminAgentComposerStatus" class="sr-only" role="status" aria-live="polite"></span>
      </footer>
    </div>
  </div>

  <input id="folderInput" type="file" webkitdirectory directory multiple accept=".mp3,.wav,.wave,audio/mpeg,audio/wav" hidden>
  <script src="assets/analytics.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/settings-tabs.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/sidebar-accordion.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/admin.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>&amp;e5=1"></script>
  <script src="assets/stripe-connect.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/commerce-ops.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/campaigns.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/agent-actions.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/agent-commerce-review.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
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
