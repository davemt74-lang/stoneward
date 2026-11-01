<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/notification-core.php';
$checks=0;
$assert=static function(bool $yes,string $label)use(&$checks):void{
    if(!$yes){fwrite(STDERR,"91B13G FAIL: $label\n");exit(1);}
    echo "91B13G PASS: $label\n";$checks++;
};
$tracks=['sf-1'=>['id'=>'sf-1','title'=>'Scent Of A Pistol'],'sf-2'=>['id'=>'sf-2','title'=>'Static Sky']];
$listen=['kind'=>'listening','body'=>'You left this track unfinished. Pick it up from 2:30.','link_url'=>'?view=history'];
$act=sf_notification_action_context($listen,'listen:sf-1:2026-10-31T22:28:43+00:00',$tracks);
$assert(($act['track_id']??'')==='sf-1'&&($act['position_seconds']??0)===150,
    'Existing unfinished listening reminders resolve original track ID and resume offset');
$recommend=['kind'=>'recommendation','title'=>'A Stonefellow pick','link_url'=>'?view=track&id=sf-2'];
$rec=sf_notification_action_context($recommend,'recommendation:2026-44:sf-2',$tracks);
$assert(($rec['track_id']??'')==='sf-2'&&($rec['position_seconds']??-1)===0,
    'Recommendations resolve exact public catalog track by canonical link');
$assert(sf_notification_action_context($listen,'listen:missing:2026-10-31T22:28:43+00:00',$tracks)===null,
    'Missing or unpublished tracks never get a playback action');
$assert(sf_notification_action_context($listen,'',$tracks)===null,
    'Human-readable song title alone cannot authorize playback');
$assert(sf_notification_action_context(['kind'=>'release','link_url'=>'?view=track&id=sf-1'],'',$tracks)===null,
    'Release cards cannot silently be converted into a single-track play action');
$assert(sf_notification_action_context(['kind'=>'recommendation','link_url'=>'https://attacker.example/?view=track&id=sf-1'],'',$tracks)===null,
    'Off-site recommendation links are not converted to playback actions');
echo "Section 91B13G: $checks notification identity and resume checks passed\n";