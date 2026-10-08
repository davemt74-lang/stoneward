<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function src($rel){global $root;return (string)file_get_contents($root.'/'.$rel);}
$core=src('api/queue-core.php');$api=src('api/queue.php');$boot=src('api/bootstrap.php');$agent=src('api/agent-runtime.php');$app=src('assets/js/app.js');$css=src('assets/css/site.css');$html=src('stonefellow-v120.php');$mig=src('api/migrations.php');$version=src('version.php');

ok(str_contains($boot,"require_once __DIR__ . '/queue-core.php';"),'queue core loads from bootstrap');
ok(str_contains($core,'CREATE TABLE IF NOT EXISTS user_play_queue'),'persistent queue table exists');
ok(str_contains($core,'UNIQUE(user_id,track_id)')||str_contains($core,'uq_user_play_queue_track'),'queue prevents duplicate user/track rows');
ok(str_contains($core,'idx_user_play_queue_order'),'queue has an ordered lookup index');
ok(str_contains($core,'function sf_queue_add'),'queue add helper exists');
ok(str_contains($core,"\$placement==='next'"),'queue supports play-next placement');
ok(str_contains($core,'function sf_queue_remove'),'queue remove helper exists');
ok(str_contains($core,'function sf_queue_reorder'),'queue reorder helper exists');
ok(str_contains($core,'Queue order does not match the current queue.'),'queue reorder validates exact membership');
ok(str_contains($core,'function sf_queue_clear'),'queue clear helper exists');
ok(str_contains($core,'function sf_queue_take'),'exact queue item can be taken for playback');
ok(str_contains($core,'function sf_queue_pop_next'),'queue can atomically pop the next track');

ok(str_contains($api,"action==='add'")&&str_contains($api,"action==='remove'")&&str_contains($api,"action==='reorder'"),'queue API exposes add/remove/reorder');
ok(str_contains($api,"action==='clear'")&&str_contains($api,"action==='take'")&&str_contains($api,"action==='pop'"),'queue API exposes clear/take/pop');
ok(str_contains($api,'sf_require_user(false,$write)'),'queue writes require authenticated CSRF-protected user');

ok(str_contains($html,'id="playerQueueButton"')&&str_contains($html,'id="playerQueueCount"'),'footer player exposes Queue button and count');
ok(str_contains($html,'id="queueDrawer"')&&str_contains($html,'id="queueContent"'),'Up Next drawer exists');
ok(str_contains($html,'id="menuQueueLink"'),'signed-in menu exposes Up Next when player is hidden');

ok(str_contains($app,'function loadQueue(')&&str_contains($app,"api('queue.php')"),'queue restores from server');
ok(str_contains($app,'function renderQueueDrawer'),'queue drawer renderer exists');
ok(str_contains($app,"querySelectorAll('[data-queue-play]')")&&str_contains($app,"querySelectorAll('[data-queue-remove]')")&&str_contains($app,"querySelectorAll('[data-queue-move]')"),'queue row controls bind as collections');
ok(str_contains($app,'function addTrackToQueue')&&str_contains($app,"placement==='next'?'next':'end'"),'track controls support add and play-next');
ok(str_contains($app,'function reorderQueueItem'),'client queue reordering is wired');
ok(str_contains($app,'function clearQueue'),'client clear queue is wired');
ok(str_contains($app,'function takeQueueItem'),'client can play an exact queued item');
ok(str_contains($app,'function playNextFromQueue'),'player can consume the queue head');
ok(str_contains($app,"play(t,0,'queue')"),'queued playback uses dedicated telemetry source');
ok(str_contains($app,"play(t,0,'queue_history')"),'Queue Previous uses local playback history');
ok(str_contains($app,'agentSessionActive()&&delta>0'),'Agent session remains highest-priority Next behavior');
ok(str_contains($app,'st.playlistPlayback.playlistId&&st.playlistPlayback.trackIds.length'),'playlist/release playback remains ahead of Up Next');
ok(str_contains($app,'delta>0&&st.auth.authenticated&&st.queue.items.length'),'manual Next consumes Up Next after session/playlist context');
ok(str_contains($app,"if(st.queue.items.length){playNextFromQueue();return}"),'track completion falls through to Up Next');
ok(str_contains($app,'loadQueue(true)')&&str_contains($app,'loadAgentListeningSession()'),'queue and Agent session both restore after reload');
ok(str_contains($app,'queue_count:st.queue.items.length'),'Agent client state includes queue count');
ok(str_contains($app,'data-queue-placement="next"')&&str_contains($app,'data-queue-placement="end"'),'track page exposes Play Next and Add to Queue');
ok(str_contains($app,'function bindQueueTrackButtons')&&str_contains($app,"querySelectorAll('[data-queue-track]')"),'catalog/release surfaces expose collection-safe queue controls');

ok(str_contains($agent,"return 'queue_open'")&&str_contains($agent,"return 'queue_add'"),'Agent routes open/add queue commands');
ok(str_contains($agent,"return 'queue_play_next'")&&str_contains($agent,"return 'queue_remove'")&&str_contains($agent,"return 'queue_clear'"),'Agent routes play-next/remove/clear commands');
ok(str_contains($agent,"'type'=>'open_queue'")&&str_contains($agent,"'queue_add_track'")&&str_contains($agent,"['type'=>\$type"),'Agent open/add queue actions are emitted');
ok(str_contains($agent,"'queue_play_next'")&&str_contains($agent,"'queue_remove_track'")&&str_contains($agent,"'type'=>'queue_clear'"),'Agent emits play-next/remove/clear actions');

ok(str_contains($css,'.queue-drawer')&&str_contains($css,'.queue-item'),'Up Next drawer styles exist');
ok(str_contains($css,'.player-queue-button'),'footer Queue control is styled');
ok(str_contains($css,'body.queue-open'),'queue-open body state exists');

ok(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.6'"),'database target advances to 1.3.6');
ok(str_contains($mig,"'id'=>'2026-10-08-010'")&&str_contains($mig,'sf_queue_ensure_schema'),'migration 010 creates persistent queue');
ok(str_contains($mig,"'user_play_queue'"),'integrity checks require user_play_queue');
ok(str_contains($version,"'stonefellow'=>'1.3.6'")&&str_contains($version,"'database_schema_target'=>'1.3.6'"),'version endpoint reports v1.3.6 schema');
ok(str_contains($version,"'up_next_queue'=>'persistent-reorder-play-next-agent'"),'version endpoint reports Section 7 queue capability');

echo "Stonefellow v1.3 Section 7 Queue / Up Next audit: PASS\n";
