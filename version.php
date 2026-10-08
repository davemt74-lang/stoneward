<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo json_encode([
  'stonefellow'=>'1.3.1',
  'public_controller'=>'stonefellow-v120.php',
  'admin_controller'=>'admin/index.php',
  'notifications'=>'timeline-drawer',
  'listening_analytics'=>'enabled',
  'operations'=>'v1.2',
  'database_schema_target'=>'1.3.1',
  'database_upgrader'=>'upgrade.php',
  'dashboard'=>'activity-listening-command-center',
  'personalization'=>'favorites-library-history',
  'playlists'=>'ordered-public-private-agent',
  'customer_lifecycle'=>'v1.1',
], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
