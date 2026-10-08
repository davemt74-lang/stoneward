<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo json_encode([
  'stonefellow'=>'1.3.6',
  'public_controller'=>'stonefellow-v120.php',
  'admin_controller'=>'admin/index.php',
  'notifications'=>'timeline-drawer',
  'listening_analytics'=>'enabled',
  'operations'=>'v1.2',
  'database_schema_target'=>'1.3.6',
  'database_upgrader'=>'upgrade.php',
  'dashboard'=>'activity-listening-command-center',
  'personalization'=>'favorites-library-history',
  'playlists'=>'ordered-public-private-agent',
  'listening_history'=>'sessions-resume-privacy',
  'recommendations'=>'favorites-listening-playlists-agent',
  'rich_media_pages'=>'track-release-artwork-credits-related-agent',
  'agent_listening_sessions'=>'persistent-queue-feedback-guided-release-save',
  'up_next_queue'=>'persistent-reorder-play-next-agent',
  'customer_lifecycle'=>'v1.1',
], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
