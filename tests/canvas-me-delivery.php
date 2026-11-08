<?php
declare(strict_types=1);

require __DIR__.'/listening-playback.php';

function me_data_binding(string $id,string $extra=''): string {
    return '<section class="sf-dataset-'.$id.$extra.'"><div class="sf-data-row"></div></section>';
}

function me_data_scene(string $html,?array $scope=null,?int $previous=null): array {
    $input=['canvas_kind'=>'custom','track_id'=>'','title'=>'Account data fixture','html'=>$html,'css'=>''];
    if($scope!==null)$input['artist_inputs']=['me_tools'=>$scope];
    return sf_agent_scene_history_save(1,$input,$previous);
}

function me_data_grant(array $user,array $context,array $ids): array {
    $access=sf_canvas_access_summary($user,$context);
    return sf_canvas_access_change($user,$context,'grant',$access['fingerprint'],$ids);
}

$scope=['my_preferences','my_listening_history'];
$private=array_filter(sf_agent_canvas_data_tools($scope),static fn(array $tool):bool=>$tool['scope']==='signed_in_fan');
$privateIds=array_column($private,'id');
sort($privateIds);$expected=$scope;sort($expected);
check($privateIds===$expected&&in_array('published_catalog',array_column(sf_agent_canvas_data_tools([]),'id'),true),
    'Explicit /me choices scope provider schemas independently of public tools');
check(!array_filter(sf_agent_canvas_data_tools([]),static fn(array $tool):bool=>$tool['scope']==='signed_in_fan'),
    'An explicit /me clear removes all private provider schemas');

sf_privacy_save_profile(1,['timezone'=>'America/Phoenix','country_code'=>'US','locale'=>'en-US',
    'listening_history_enabled'=>true,'personalization_enabled'=>false]);
$html=me_data_binding('my_preferences').me_data_binding('my_listening_history',' sf-current-track')
    .me_data_binding('my_purchases').me_data_binding('published_catalog');
$saved=me_data_scene($html,$scope);
$context=['scene_id'=>$saved['id']];
$read=static fn(?array $inputs=null):array=>sf_agent_canvas_data_for_scene($user,$html,'song-two',null,$inputs,$context);
$access=sf_canvas_access_summary($user,$context);
$permissionIds=array_column($access['permissions'],'id');
check($access['requires_consent']&&in_array('canvas.data.my_preferences',$permissionIds,true)
    &&in_array('canvas.data.my_listening_history',$permissionIds,true)&&!in_array('canvas.data.my_purchases',$permissionIds,true),
    'What this canvas uses discloses only declared personal sources inside the saved scope');
rejected(fn()=>me_data_grant($user,$context,['canvas.data.my_preferences','canvas.data.my_purchases']),
    InvalidArgumentException::class,'A forged mixed grant cannot commit any permission outside the saved scope');
$data=$read(['me_tools'=>['my_purchases']]);
check($data['my_preferences']['personal']&&$data['my_preferences']['rows']===[]
    &&$data['my_preferences']['state']==='consent_required'&&!isset($data['my_purchases']),
    'The authoritative selection rejects a wider caller scope and withholding survives a rejected grant');
rejected(fn()=>sf_agent_canvas_data_for_scene($other,$html,'song-two',null,null,$context),
    OutOfBoundsException::class,'Another viewer cannot read a private account canvas');
me_data_grant($user,$context,['canvas.data.my_preferences']);
$data=$read();
check($data['my_preferences']['rows']===[['locale'=>'en-US','timezone'=>'America/Phoenix','country_code'=>'US',
    'listening_history_enabled'=>1,'personalization_enabled'=>0]]&&$data['my_listening_history']['rows']===[],
    'One grant exposes only the declared preferences fields while listening requires separate consent');
me_data_grant($user,$context,['canvas.data.my_listening_history']);
$data=$read();
check(array_column($data['my_listening_history']['rows'],'track_id')===['song-two']
    &&array_column($data['my_listening_history']['current_rows'],'track_id')===['song-two']
    &&$data['my_listening_history']['rows'][0]['position_seconds']===18
    &&!isset($data['my_listening_history']['rows'][0]['user_id'],$data['my_listening_history']['rows'][0]['session_key']),
    'Granted history reads owner-only records and current-track views without internal identifiers');

sf_db()->exec("UPDATE users SET status='disabled' WHERE id=1");
$inactive=$read();
check($inactive['my_preferences']['rows']===[]&&$inactive['my_listening_history']['rows']===[],
    'An inactive account cannot deliver personal rows through a stale viewer object');
sf_db()->exec("UPDATE users SET status='active' WHERE id=1");
$policy=sf_canvas_permission_policy();
sf_canvas_permission_save(['id'=>2,'role'=>'admin'],$policy['revision'],['canvas.data.my_listening_history'=>false]);
check(!isset($read()['my_listening_history'])&&!in_array('my_listening_history',array_column(sf_agent_canvas_data_tools($scope),'id'),true),
    'An administrator can stop one source even after the viewer granted it');
$policy=sf_canvas_permission_policy();
sf_canvas_permission_save(['id'=>2,'role'=>'admin'],$policy['revision'],['canvas.data.my_listening_history'=>true]);
$access=sf_canvas_access_summary($user,$context);
sf_canvas_access_change($user,$context,'revoke',$access['fingerprint'],['canvas.data.my_listening_history']);
$data=$read();
check($data['my_listening_history']['rows']===[]&&$data['my_listening_history']['state']==='consent_required'
    &&$data['my_preferences']['rows']!==[],
    'Revocation withholds just that source on the next read');

$cleared=me_data_scene($html,[],$saved['id']);
$clearedContext=['scene_id'=>$cleared['id']];
check(array_column(sf_canvas_access_summary($user,$clearedContext)['permissions'],'id')===['canvas.data.published_catalog']
    &&array_keys(sf_agent_canvas_data_for_scene($user,$html,'song-two',null,['me_tools'=>$scope],$clearedContext))===['published_catalog'],
    'An explicitly cleared scope retains public data and cannot reuse older grants for personal data');
check(array_column(sf_canvas_access_public_uses($html,$scope),'id')===$permissionIds,
    'Public use metadata applies the same saved-scope boundary');

$prefsHtml=me_data_binding('my_preferences');
$legacy=me_data_scene($prefsHtml);
$legacyContext=['scene_id'=>$legacy['id']];
me_data_grant($user,$legacyContext,['canvas.data.my_preferences']);
check(sf_agent_canvas_data_for_scene($user,$prefsHtml,'',null,null,$legacyContext)['my_preferences']['rows']!==[],
    'An unscoped canvas with preferences alone still works through viewer consent');

$listeningIds=['personal_listening_stats','my_listening_summary','my_listening_history'];
$listenHtml=implode('',array_map(static fn(string $id):string=>me_data_binding($id),$listeningIds));
$listen=me_data_scene($listenHtml,$listeningIds);
$listenContext=['scene_id'=>$listen['id']];
me_data_grant($user,$listenContext,array_map(static fn(string $id):string=>'canvas.data.'.$id,$listeningIds));
$readListening=static fn():array=>sf_agent_canvas_data_for_scene($user,$listenHtml,'song-two',null,null,$listenContext);
check(count(array_filter($readListening(),static fn(array $data):bool=>$data['rows']!==[]))===3,
    'Each granted listening source returns its own current-viewer dataset');

// Trigger state changes inside the ledger read, after initial authorization and before delivery.
$summaryHtml=me_data_binding('my_listening_summary');
$summaryScene=me_data_scene($summaryHtml,['my_listening_summary']);
$summaryContext=['scene_id'=>$summaryScene['id']];
$summaryPermission='canvas.data.my_listening_summary';
me_data_grant($user,$summaryContext,[$summaryPermission]);
$readSummary=static fn():array=>sf_agent_canvas_data_for_scene($user,$summaryHtml,'',null,null,$summaryContext);
$onLedgerRead=null;
$db->sqliteCreateFunction('fixture_me_ledger_read',static function(float $seconds)use(&$onLedgerRead):float {
    if($onLedgerRead!==null){$change=$onLedgerRead;$onLedgerRead=null;$change();}
    return $seconds;
},1);
$db->exec('CREATE TEMP VIEW listening_events AS SELECT id,user_id,track_id,session_key,event_type,created_at,evidence,
    fixture_me_ledger_read(listened_seconds) AS listened_seconds FROM main.listening_events');
$onLedgerRead=static fn()=>$db->exec('UPDATE listening_account_state SET epoch=epoch+1 WHERE user_id=1');
check($readSummary()['my_listening_summary']['rows']===[]&&$onLedgerRead===null,
    'An epoch change during the ledger read invalidates already selected listening data');
$grantContext='scene:'.$summaryScene['id'];
$onLedgerRead=static function()use($db,$grantContext,$summaryPermission):void {
    $db->prepare('DELETE FROM canvas_access_grants WHERE user_id=1 AND context_key=? AND permission_id=?')
        ->execute([$grantContext,$summaryPermission]);
};
$revokedRead=$readSummary()['my_listening_summary'];
check($revokedRead['rows']===[]&&$revokedRead['state']==='consent_required'&&$onLedgerRead===null,
    'A grant removed during the ledger read prevents delivery of the selected rows');
me_data_grant($user,$summaryContext,[$summaryPermission]);
$onLedgerRead=static function()use($db,$summaryPermission):void {
    $db->prepare('UPDATE canvas_permission_policy SET revision=revision+1,disabled_json=? WHERE id=1')
        ->execute([json_encode([$summaryPermission],JSON_THROW_ON_ERROR)]);
};
check(!isset($readSummary()['my_listening_summary'])&&$onLedgerRead===null,
    'Policy disabled during the ledger read suppresses delivery');
$policy=sf_canvas_permission_policy();
sf_canvas_permission_save(['id'=>2,'role'=>'admin'],$policy['revision'],[$summaryPermission=>true]);
$db->exec('DROP VIEW temp.listening_events');

sf_privacy_save_profile(1,['listening_history_enabled'=>false,'personalization_enabled'=>true]);
$q=sf_db()->prepare("INSERT INTO listening_events(user_id,session_key,track_id,event_type,position_seconds,duration_seconds,source,created_at)
    VALUES(1,'legacy retained fixture','song-two','start',0,120,'fixture',?)");
$q->execute([gmdate('c')]);
check(count(array_filter($readListening(),static fn(array $data):bool=>$data['rows']===[]))===3,
    'Listening opt-out withholds all three sources even when owner-linked legacy records remain');

@mkdir(SF_ROOT.'/storage/orders',0700,true);
foreach([
    ['SF-ME-LINKED',1,'Owned physical media','old-address@example.invalid'],
    ['SF-ME-GUEST',0,'Guest order canary','listen1@example.invalid'],
    ['SF-ME-OTHER',2,'Other order canary','listen1@example.invalid'],
] as [$orderId,$owner,$label,$email]){
    file_put_contents(SF_ROOT.'/storage/orders/'.$orderId.'.json',json_encode([
        'id'=>$orderId,'user_id'=>$owner,'created_at'=>gmdate('c'),'status'=>'paid',
        'customer'=>['email'=>$email],
        'quote'=>['total_cents'=>1200,'currency'=>'USD','items'=>[['label'=>$label,'type'=>'merch','price_cents'=>1200,'quantity'=>1]]],
        'payment'=>['status'=>'paid'],
    ],JSON_THROW_ON_ERROR));
}
$purchaseHtml=me_data_binding('my_purchases');
$purchase=me_data_scene($purchaseHtml,['my_purchases']);
$purchaseContext=['scene_id'=>$purchase['id']];
$buyer=$user+['email'=>'listen1@example.invalid','email_verified_at'=>gmdate('c')];
me_data_grant($buyer,$purchaseContext,['canvas.data.my_purchases']);
$purchases=sf_agent_canvas_data_for_scene($buyer,$purchaseHtml,'',null,null,$purchaseContext)['my_purchases']['rows'];
check(array_column($purchases,'order_id')===['SF-ME-LINKED']&&$purchases[0]['item_label']==='Owned physical media',
    'Purchases follow the linked account ID and exclude guest or other-owner email lookalikes');

$inboxTool='my_notifications';
$inboxPermission='canvas.data.'.$inboxTool;
$inboxHtml=me_data_binding($inboxTool);
$unscopedInbox=me_data_scene($inboxHtml);
$unscopedInboxContext=['scene_id'=>$unscopedInbox['id']];
check(!in_array($inboxTool,array_column(sf_agent_canvas_data_tools(),'id'),true)&&
    sf_canvas_access_summary($user,$unscopedInboxContext)['permissions']===[]&&
    sf_agent_canvas_data_for_scene($user,$inboxHtml,'',null,null,$unscopedInboxContext)===[]&&
    sf_canvas_access_public_uses($inboxHtml)===[],
    'An unattached inbox marker creates no provider schema, permission, dataset or gallery disclosure');
$inbox=me_data_scene($inboxHtml,[$inboxTool]);
$inboxContext=['scene_id'=>$inbox['id']];
$readInbox=static fn():array=>sf_agent_canvas_data_for_scene($user,$inboxHtml,'',null,null,$inboxContext);
for($i=1;$i<=32;$i++){
    $notificationId=sf_notify_user(1,'service','Inbox item '.$i,'PRIVATE_INBOX_BODY','https://example.invalid/private');
    if($i===31)sf_notification_mark_read(1,$notificationId);
    if($i===32)sf_notification_dismiss(1,$notificationId);
}
sf_notify_user(2,'service','Another account inbox title','private body');
$deliveryEvents=(int)$db->query('SELECT COUNT(*) FROM notification_events')->fetchColumn();
check($readInbox()[$inboxTool]['state']==='consent_required'&&$readInbox()[$inboxTool]['rows']===[],
    'The inbox dataset is withheld until its viewer grants consent');
me_data_grant($user,$inboxContext,[$inboxPermission]);
$inboxRows=$readInbox()[$inboxTool]['rows'];
check(count($inboxRows)===30&&array_column($inboxRows,'title')===array_map(static fn(int $i):string=>'Inbox item '.$i,range(31,2))&&
    $inboxRows[0]['is_read']===1&&$inboxRows[1]['is_read']===0&&
    array_keys($inboxRows[0])===['kind','title','created_at','is_read']&&strtotime($inboxRows[0]['created_at'])!==false,
    'Inbox data contains the thirty newest visible notifications from the granting viewer with only the approved fields');
check((int)$db->query('SELECT COUNT(*) FROM notification_events')->fetchColumn()===$deliveryEvents&&
    (int)$db->query('SELECT COUNT(*) FROM notification_generation_state')->fetchColumn()===0,
    'Reading the inbox neither modifies notification activity nor triggers generation');
$policy=sf_canvas_permission_policy();
sf_canvas_permission_save(['id'=>2,'role'=>'admin'],$policy['revision'],[$inboxPermission=>false]);
check(!isset($readInbox()[$inboxTool]),'Disabling inbox access in the admin policy stops delivery after consent');
$policy=sf_canvas_permission_policy();
sf_canvas_permission_save(['id'=>2,'role'=>'admin'],$policy['revision'],[$inboxPermission=>true]);
$access=sf_canvas_access_summary($user,$inboxContext);
sf_canvas_access_change($user,$inboxContext,'revoke',$access['fingerprint'],[$inboxPermission]);
check($readInbox()[$inboxTool]['state']==='consent_required'&&$readInbox()[$inboxTool]['rows']===[],
    'Withdrawing the inbox grant withholds the dataset on the next read');
