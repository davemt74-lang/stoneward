<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/agent-proof-receipts.php';
function check90w(bool $ok,string $description):void {
    if(!$ok)throw new RuntimeException('FAIL: '.$description);
    echo 'PASS: '.$description.PHP_EOL;
}
$id='act_'.str_repeat('a',32);
$catalog=['song-1'=>['id'=>'song-1','title'=>'A Persisted Song']];
$base=['id'=>$id,'tool_id'=>'catalog_metadata_update','created_at'=>'2026-10-27T10:00:00Z',
    'payload'=>['private_note'=>'Never print this private draft'],
    'result'=>['track_id'=>'song-1']];
$pending=sf_agent_proof_proposal_receipt($base+['status'=>'proposed'],$catalog);
check90w($pending['verified']===false&&$pending['url']==='index.php?view=brain',
    'Unapproved Agent proposal cannot claim persisted work.');
$approved=sf_agent_proof_proposal_receipt(array_replace($base,['status'=>'approved']),$catalog);
check90w($approved['verified']===false,'Approval alone cannot claim completed work.');
$completed=sf_agent_proof_proposal_receipt(array_replace($base,['status'=>'completed']),$catalog);
check90w($completed['verified']===true&&$completed['url']==='index.php?view=catalog&track=song-1',
    'Completed Catalog edit links to the independently present record.');
check90w(!str_contains(json_encode($completed,JSON_THROW_ON_ERROR),'Never print'),
    'Action receipt never exposes proposal draft content.');
$missing=sf_agent_proof_proposal_receipt(array_replace($base,['status'=>'completed','result'=>['track_id'=>'missing']]),$catalog);
check90w($missing['verified']===false&&$missing['url']==='index.php?view=brain',
    'An absent saved record is never described as verified.');
$other=sf_agent_proof_proposal_receipt(array_replace($base,['tool_id'=>'campaign_draft_save','status'=>'completed','result'=>['campaign_id'=>12]]),$catalog);
check90w($other['verified']===false&&$other['entity_type']==='agent_action',
    'Other Agent results remain unverified until a real entity lookup exists.');
echo "Section 90W verified Agent proposal receipt contract: PASS\n";
