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
        'public_artist_facts'=>['label'=>'Read verified public music and artist facts','kind'=>'read','scope'=>'fan','owner'=>'api/agent-public-artist-context.php','handler'=>'sf_agent_public_artist_provider_context','confirmation'=>false,'execution'=>'server'],
        'recommend_public_track'=>['label'=>'Public track recommendation','kind'=>'read','scope'=>'fan','owner'=>'api/agent-runtime.php','handler'=>'sf_agent_recommend_track','confirmation'=>false,'execution'=>'server'],
        'favorite_track'=>['label'=>'Favorite a track','kind'=>'write','scope'=>'signed_in_fan','owner'=>'api/personalization.php','handler'=>'set_favorite','confirmation'=>true,'execution'=>'authenticated_api'],
        'create_playlist'=>['label'=>'Create a playlist','kind'=>'write','scope'=>'signed_in_fan','owner'=>'api/playlists.php','handler'=>'create','confirmation'=>true,'execution'=>'authenticated_api'],
        'queue_track'=>['label'=>'Change player queue','kind'=>'local_action','scope'=>'fan','owner'=>'assets/js/app.js','handler'=>'executeAgentAction','confirmation'=>false,'execution'=>'browser'],
        'public_page_navigate'=>['label'=>'Open installed public pages without reloading the player','kind'=>'local_action','scope'=>'fan','owner'=>'assets/js/app.js','handler'=>'executeAgentAction','confirmation'=>false,'execution'=>'browser'],
        'kunaki_publishing_queue'=>['label'=>'Stage purchased vinyl for HomeServer publishing review','kind'=>'write','scope'=>'admin','owner'=>'admin/api/vinyl-production.php','handler'=>'queue_kunaki_publishing','confirmation'=>true,'execution'=>'governed_admin_api'],
        'stem_mix_inspect'=>['label'=>'Inspect private REAPER mix metrics (Admin only)','kind'=>'read','scope'=>'admin','owner'=>'api/stem-agent-bridge.php','handler'=>'sf_stem_agent_review','confirmation'=>false,'execution'=>'server'],
        'stem_automation_update'=>['label'=>'Apply exactly approved private Stem Studio automation','kind'=>'write','scope'=>'admin','owner'=>'api/stem-agent-bridge.php','handler'=>'sf_stem_project_update','confirmation'=>true,'execution'=>'governed_admin_api'],
        'commerce_aggregate'=>['label'=>'Store payment, refund and fulfillment queue','kind'=>'read','scope'=>'admin','owner'=>'api/agent-commerce-bridge.php','handler'=>'sf_agent_commerce_admin_snapshot','confirmation'=>false,'execution'=>'server'],
        'commerce_followup_add'=>['label'=>'Save exactly approved customer order follow-up','kind'=>'write','scope'=>'admin','owner'=>'api/commerce-operations.php','handler'=>'sf_commerce_ops_update','confirmation'=>true,'execution'=>'governed_admin_api'],
        'customer_order_status'=>['label'=>'Read only signed-in customer order status','kind'=>'read','scope'=>'signed_in_fan','owner'=>'api/agent-commerce-bridge.php','handler'=>'sf_agent_commerce_customer_snapshot','confirmation'=>false,'execution'=>'server'],
        'crm_aggregate'=>['label'=>'CRM aggregate overview','kind'=>'read','scope'=>'admin','owner'=>'api/crm-core.php','handler'=>'sf_crm_admin_summary','confirmation'=>false,'execution'=>'server'],
        'campaign_aggregate'=>['label'=>'Campaign aggregate overview','kind'=>'read','scope'=>'admin','owner'=>'api/campaign-core.php','handler'=>'sf_campaign_admin_summary','confirmation'=>false,'execution'=>'server'],
        'campaign_graph_validate'=>['label'=>'Validate campaign node graph','kind'=>'read','scope'=>'admin','owner'=>'api/campaign-core.php','handler'=>'sf_campaign_graph_validate','confirmation'=>false,'execution'=>'server'],
        'admin_module_navigate'=>['label'=>'Open an installed Admin workflow, including user-selected audio upload','kind'=>'local_action','scope'=>'admin','owner'=>'admin/assets/agent-canvas.js','handler'=>'action','confirmation'=>false,'execution'=>'browser'],
        'agent_song_draft_save'=>['label'=>'Review and save an owner-only, unpublished song intake draft','kind'=>'write','scope'=>'admin','owner'=>'admin/api/agent-song-intake.php','handler'=>'save_draft','confirmation'=>true,'execution'=>'governed_admin_api'],
        'agent_song_catalog_prepare'=>['label'=>'Approve private song artwork and rights preflight; no publication','kind'=>'write','scope'=>'admin','owner'=>'admin/api/agent-song-intake.php','handler'=>'prepare_catalog','confirmation'=>true,'execution'=>'governed_admin_api'],
        'agent_song_catalog_publish'=>['label'=>'Publish one separately approved catalog track with a distinct MP3 listening preview','kind'=>'consequential','scope'=>'admin','owner'=>'admin/api/agent-song-catalog.php','handler'=>'publish_track','confirmation'=>true,'execution'=>'governed_admin_api'],
        'catalog_metadata_update'=>['label'=>'Review and save an existing song title, genre, story or year','kind'=>'write','scope'=>'admin','owner'=>'api/agent-catalog-actions.php','handler'=>'sf_agent_catalog_apply_snapshot','confirmation'=>true,'execution'=>'governed_admin_api'],
        'automation_draft_save'=>['label'=>'Save reviewed lifecycle journey draft','kind'=>'write','scope'=>'admin','owner'=>'api/automation-core.php','handler'=>'sf_automation_save','confirmation'=>true,'execution'=>'governed_admin_api'],
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

/**
 * Agent execution is an explicit narrow allowlist separate from tool discovery.
 * Sending, publishing, purchasing and deletes remain in owner-controlled UI.
 */
function sf_agent_tool_executable(string $toolId,string $role,bool $confirmed=false): bool {
    if(!in_array($toolId,['campaign_draft_save','automation_draft_save','commerce_followup_add','stem_automation_update','catalog_metadata_update'],true))return false;
    return sf_agent_tool_allowed($toolId,$role,$confirmed);
}
