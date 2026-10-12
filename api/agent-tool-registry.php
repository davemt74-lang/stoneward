<?php
declare(strict_types=1);
/**
 * Section 44: server-authoritative Agent tool inventory.
 * Registry metadata is descriptive; entries never grant execution authority.
 */
function sf_agent_tool_registry(): array {
    return [
        'public_catalog_search'=>['label'=>'Public catalog discovery','kind'=>'read','scope'=>'fan','owner'=>'api/search-core.php','handler'=>'sf_catalog_search','confirmation'=>false,'execution'=>'server'],
        'public_site_search'=>['label'=>'Published site search','kind'=>'read','scope'=>'fan','owner'=>'api/search-core.php','handler'=>'sf_site_search','confirmation'=>false,'execution'=>'server'],
        'recommend_public_track'=>['label'=>'Public track recommendation','kind'=>'read','scope'=>'fan','owner'=>'api/agent-runtime.php','handler'=>'sf_agent_recommend_track','confirmation'=>false,'execution'=>'server'],
        'favorite_track'=>['label'=>'Favorite a track','kind'=>'write','scope'=>'signed_in_fan','owner'=>'api/personalization.php','handler'=>'set_favorite','confirmation'=>true,'execution'=>'authenticated_api'],
        'create_playlist'=>['label'=>'Create a playlist','kind'=>'write','scope'=>'signed_in_fan','owner'=>'api/playlists.php','handler'=>'create','confirmation'=>true,'execution'=>'authenticated_api'],
        'queue_track'=>['label'=>'Change player queue','kind'=>'local_action','scope'=>'fan','owner'=>'assets/js/app.js','handler'=>'executeAgentAction','confirmation'=>false,'execution'=>'browser'],
        'crm_aggregate'=>['label'=>'CRM aggregate overview','kind'=>'read','scope'=>'admin','owner'=>'api/crm-core.php','handler'=>'sf_crm_admin_summary','confirmation'=>false,'execution'=>'server'],
        'campaign_aggregate'=>['label'=>'Campaign aggregate overview','kind'=>'read','scope'=>'admin','owner'=>'api/campaign-core.php','handler'=>'sf_campaign_admin_summary','confirmation'=>false,'execution'=>'server'],
        'campaign_graph_validate'=>['label'=>'Validate campaign node graph','kind'=>'read','scope'=>'admin','owner'=>'api/campaign-core.php','handler'=>'sf_campaign_graph_validate','confirmation'=>false,'execution'=>'server'],
        'campaign_draft_save'=>['label'=>'Save campaign draft','kind'=>'write','scope'=>'admin','owner'=>'api/campaign-core.php','handler'=>'sf_campaign_save','confirmation'=>true,'execution'=>'governed_admin_api'],
        'campaign_publish'=>['label'=>'Publish a campaign','kind'=>'consequential','scope'=>'admin','owner'=>'admin/api/campaigns.php','handler'=>'save','confirmation'=>true,'execution'=>'governed_admin_api'],
        'campaign_send_email'=>['label'=>'Send campaign email','kind'=>'consequential','scope'=>'admin','owner'=>'api/campaign-core.php','handler'=>'sf_campaign_send_email_node','confirmation'=>true,'execution'=>'governed_admin_api'],
        'elevenlabs_voice'=>['label'=>'Voice synthesis','kind'=>'metered','scope'=>'signed_in_fan','owner'=>'api/agent-voice.php','handler'=>'sf_ai_resolve_voice','confirmation'=>false,'execution'=>'provider'],
        'llm_response'=>['label'=>'OpenAI/Claude reply','kind'=>'metered','scope'=>'signed_in_fan','owner'=>'api/agent-runtime.php','handler'=>'sf_ai_llm_candidates','confirmation'=>false,'execution'=>'provider'],
        'jev_routing'=>['label'=>'JEV policy routing','kind'=>'metered','scope'=>'signed_in_fan','owner'=>'api/agent-runtime.php','handler'=>'sf_agent_jev_route','confirmation'=>false,'execution'=>'provider'],
        'agent_brain_log'=>['label'=>'Agent Brain audit','kind'=>'internal','scope'=>'server','owner'=>'api/agent-runtime.php','handler'=>'sf_agent_brain_log','confirmation'=>false,'execution'=>'server'],
    ];
}
function sf_agent_tool_inventory(string $role='fan'): array {
    $result=[];
    foreach(sf_agent_tool_registry() as $name=>$tool) {
        if($tool['scope']==='server')continue;
        if($tool['scope']==='admin'&&$role!=='admin')continue;
        if($tool['scope']==='signed_in_fan'&&!in_array($role,['fan','admin'],true))continue;
        $result[]=['id'=>$name]+$tool;
    }
    return $result;
}
function sf_agent_tool_allowed(string $toolId,string $role,bool $confirmed=false): bool {
    $tool=sf_agent_tool_registry()[$toolId]??null;
    if(!$tool || $tool['scope']==='server')return false;
    if($tool['scope']==='admin'&&$role!=='admin')return false;
    if($tool['scope']==='signed_in_fan'&&!in_array($role,['fan','admin'],true))return false;
    if(!empty($tool['confirmation'])&&!$confirmed)return false;
    return true;
}
