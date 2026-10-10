<?php
declare(strict_types=1);
$root=dirname(__DIR__);
define('SF_ROOT',$root);
require_once __DIR__.'/api/bootstrap.php';
if(!sf_installed()){http_response_code(503);exit('Stonefellow database configuration is unavailable.');}
$adminUser=sf_current_user();
if(!$adminUser||($adminUser['role']??'')!=='admin'){header('Location: login.php');exit;}
define('SF_ADMIN_BUILD','1.3.27');
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
  <link rel="stylesheet" href="assets/media.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/products.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/automations.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/membership.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/ticketing.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/fulfillment.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/operating-calendar.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
  <link rel="stylesheet" href="assets/rights.css?v=<?=rawurlencode(SF_ADMIN_BUILD)?>">
</head>
<body>
  <div id="adminApp" class="admin-app">
    <aside id="sidebar" class="sidebar">
      <div class="sidebar-brand"><a href="../" target="_blank" rel="noopener">STONEFELLOW</a><span>ADMIN</span></div>
      <nav id="adminNav">
        <button type="button" data-view="dashboard" class="active"><span>01</span>Dashboard</button>
        <button type="button" data-view="catalog"><span>02</span>Catalog</button>
        <button type="button" data-view="media"><span>03</span>Media Library</button>
        <button type="button" data-view="uploads"><span>04</span>Folder Scan & Upload</button>
        <button type="button" data-view="templates"><span>05</span>Metadata Templates</button>
        <button type="button" data-view="releases"><span>06</span>Releases</button>
        <button type="button" data-view="rights"><span>07</span>Rights + Licensing</button>
        <button type="button" data-view="shows"><span>08</span>Shows + Live</button>
        <button type="button" data-view="crm"><span>09</span>Fans + CRM</button>
        <button type="button" data-view="automations"><span>10</span>Segments + Automations</button>
        <button type="button" data-view="campaigns"><span>11</span>Campaigns</button>
        <button type="button" data-view="products"><span>12</span>Merch + Products</button>
        <button type="button" data-view="knowledge"><span>13</span>Knowledge Base</button>
        <button type="button" data-view="orders"><span>14</span>Orders</button>
        <button type="button" data-view="fulfillment"><span>15</span>Fulfillment + Care</button>
        <button type="button" data-view="pod"><span>16</span>POD Handoffs</button>
        <button type="button" data-view="users"><span>17</span>Users + Tokens</button>
        <button type="button" data-view="packages"><span>18</span>Monthly Packages</button>
        <button type="button" data-view="membership"><span>19</span>Membership + VIP</button>
        <button type="button" data-view="ticketing"><span>20</span>Tickets + VIP</button>
        <button type="button" data-view="ai"><span>21</span>AI Providers</button>
        <button type="button" data-view="brain"><span>22</span>Agent Brain</button>
        <button type="button" data-view="customer"><span>23</span>Customer Lifecycle</button>
        <button type="button" data-view="analytics"><span>24</span>Performance Intelligence</button>
        <button type="button" data-view="calendar"><span>25</span>Operating Calendar</button>
        <button type="button" data-view="operations"><span>26</span>Operations</button>
        <button type="button" data-view="settings"><span>27</span>Settings</button>
      </nav>
      <div class="sidebar-foot"><span id="adminStatusDot"></span><span>Local admin</span></div>
    </aside>

    <div class="workspace">
      <header class="admin-topbar">
        <button id="sidebarToggle" class="mobile-menu" type="button" aria-label="Open admin menu">☰</button>
        <div><div class="eyebrow">STONEFELLOW ADMIN</div><div id="viewTitle" class="view-title">Dashboard</div></div>
        <div class="admin-user-menu"><span><?=htmlspecialchars((string)$adminUser['display_name'],ENT_QUOTES,'UTF-8')?></span><a href="../" target="_blank" rel="noopener" class="public-link">View site ↗</a><a href="logout.php" class="public-link">Log out</a></div>
      </header>

      <main id="adminCanvas" class="admin-canvas" aria-live="polite"></main>

      <footer class="admin-chat-footer">
        <div class="agent-strip"><button id="adminOrb" type="button" class="admin-orb" aria-label="Stonefellow Admin Agent"></button><span id="agentStatus">IDLE</span><p id="agentMessage">Ask me to scan a folder, create a metadata template, find missing ISRCs, or show the catalog.</p></div>
        <form id="adminChatForm" class="admin-chatbar" autocomplete="off">
          <label class="sr-only" for="adminChatInput">Ask Stonefellow Admin</label>
          <input id="adminChatInput" type="text" maxlength="400" placeholder="Ask Stonefellow Admin anything…">
          <button id="adminVoiceButton" type="button" class="chat-button" aria-label="Voice input">◉</button>
          <button type="submit" class="chat-button send" aria-label="Send">↑</button>
        </form>
      </footer>
    </div>
  </div>

  <input id="folderInput" type="file" webkitdirectory directory multiple accept=".mp3,.wav,.wave,audio/mpeg,audio/wav" hidden>
  <script src="assets/analytics.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/admin.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/campaigns.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/media.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/products.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/automations.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/membership.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/ticketing.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/fulfillment.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/operating-calendar.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
  <script src="assets/rights.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>
</body>
</html>
