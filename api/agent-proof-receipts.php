<?php
declare(strict_types=1);
/** Public-safe display contract for an owner-scoped Admin Agent proposal.
 *  The caller must authorize and owner-filter the ledger query first.
 *  No submitted draft, user note, or approval token is exposed in this receipt.
 */
function sf_agent_proof_proposal_receipt(array $proposal,array $catalog): array {
    $id=(string)($proposal['id']??'');
    $tool=(string)($proposal['tool_id']??'');
    $status=(string)($proposal['status']??'review_required');
    $labels=[
        'catalog_metadata_update'=>'Catalog metadata edit',
        'stem_automation_update'=>'Stem automation edit',
        'automation_draft_save'=>'Automation draft',
        'campaign_draft_save'=>'Campaign draft',
        'commerce_followup_add'=>'Customer follow-up',
    ];
    $title=$labels[$tool]??'Agent action';
    $result=is_array($proposal['result']??null)?$proposal['result']:[];
    $trackId=(string)($result['track_id']??'');
    $verified=$status==='completed'&&$tool==='catalog_metadata_update'
        &&$trackId!==''&&isset($catalog[$trackId]);
    if($verified)$title=(string)($catalog[$trackId]['title']??$title);
    return [
        'id'=>'proposal-'.$id,'action'=>$tool,'title'=>$title,
        'entity_type'=>$verified?'track':'agent_action',
        'entity_id'=>$verified?$trackId:$id,
        'state'=>$status,'verified'=>$verified,
        'provenance'=>'agent_action_proposal',
        'actor'=>$status==='completed'?'Agent tool · administrator approved':'Agent proposal · review required',
        'view'=>$verified?'catalog':'brain',
        'url'=>$verified?'index.php?view=catalog&track='.rawurlencode($trackId):'index.php?view=brain',
        'at'=>(string)($status==='completed'?($proposal['executed_at']??$proposal['approved_at']??$proposal['created_at']??''):
            ($proposal['approved_at']??$proposal['created_at']??'')),
    ];
}
