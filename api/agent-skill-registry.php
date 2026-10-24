<?php
declare(strict_types=1);
/**
 * Section 47B — static, server-authoritative skill catalog over Section 44 tools.
 *
 * Skill discovery is descriptive only: a manifest never invokes a handler,
 * authorizes a user, bypasses consent, or directly executes an action.
 */
require_once __DIR__.'/agent-tool-registry.php';

function sf_agent_skill_definitions(): array {
    return [
      'catalog_discovery'=>['label'=>'Find published music','contexts'=>['public','customer','admin'],'tool'=>'public_catalog_search','intents'=>['public_catalog_search']],
      'published_site_search'=>['label'=>'Search published site','contexts'=>['public','customer','admin'],'tool'=>'public_site_search','intents'=>['public_site_search']],
      'published_artist_facts'=>['label'=>'Research published Stonefellow music and artist information','contexts'=>['public','customer','admin'],'tool'=>'public_artist_facts','intents'=>['public_artist_research']],
      'public_recommendations'=>['label'=>'Recommend published tracks','contexts'=>['public','customer','admin'],'tool'=>'recommend_public_track','intents'=>['player_recommend']],
      'playback_queue'=>['label'=>'Manage local playback queue','contexts'=>['public','customer','admin'],'tool'=>'queue_track','intents'=>['queue_track']],
      'customer_favorites'=>['label'=>'Manage my favorites','contexts'=>['customer'],'tool'=>'favorite_track','intents'=>['favorite_save','favorite_remove']],
      'customer_playlists'=>['label'=>'Build my playlists','contexts'=>['customer'],'tool'=>'create_playlist','intents'=>['create_playlist']],
      'customer_voice'=>['label'=>'Generate customer voice reply','contexts'=>['customer'],'tool'=>'elevenlabs_voice','intents'=>['voice_reply']],
      'customer_llm'=>['label'=>'Answer with configured language model','contexts'=>['customer'],'tool'=>'llm_response','intents'=>['llm_response']],
      'customer_policy'=>['label'=>'Apply JEV routing','contexts'=>['customer'],'tool'=>'jev_routing','intents'=>['jev_routing']],
      'customer_order_status'=>['label'=>'Read my own orders and fulfillment','contexts'=>['customer'],'tool'=>'customer_order_status','intents'=>['customer_order_status']],
      'admin_kunaki_publishing'=>['label'=>'Stage purchased vinyl for HomeServer review','contexts'=>['admin'],'tool'=>'kunaki_publishing_queue','intents'=>['kunaki_publishing_queue']],
      'admin_stem_mix_review'=>['label'=>'Review private REAPER Stem Studio, diagnose production evidence and suggest governed improvements','contexts'=>['admin'],'tool'=>'stem_mix_inspect','intents'=>['stem_mix_review','stem_production_review']],
      'admin_stem_automation'=>['label'=>'Prepare governed mix automation with exact approval','contexts'=>['admin'],'tool'=>'stem_automation_update','intents'=>['stem_automation_update']],
      'admin_commerce_insights'=>['label'=>'Review store commerce exceptions','contexts'=>['admin'],'tool'=>'commerce_aggregate','intents'=>['commerce_report']],
      'admin_commerce_followup'=>['label'=>'Prepare reviewed customer follow-up','contexts'=>['admin'],'tool'=>'commerce_followup_add','intents'=>['prepare_commerce_followup']],
      'admin_crm_insights'=>['label'=>'Review CRM aggregates','contexts'=>['admin'],'tool'=>'crm_aggregate','intents'=>['crm_report']],
      'admin_module_navigation'=>['label'=>'Find and open authorized Admin workspaces, including private audio upload','contexts'=>['admin'],'tool'=>'admin_module_navigate','intents'=>[
        'bulk_music_import','upload_audio','metadata_templates','media_library','catalog_manager',
        'release_manager','shows_manager','rights_manager','distribution_manager','press_manager',
        'knowledge_manager','products_manager','ticketing_manager','membership_manager',
        'account_manager','team_manager','ai_settings','site_settings','operating_calendar',
        'payments_manager','fulfillment_manager','pod_manager','customer_lifecycle'
      ]],
      'admin_catalog_metadata'=>['label'=>'Prepare exact reviewed music catalog metadata edits','contexts'=>['admin'],'tool'=>'catalog_metadata_update','intents'=>['edit_catalog_metadata']],
      'admin_campaign_insights'=>['label'=>'Review campaign aggregates','contexts'=>['admin'],'tool'=>'campaign_aggregate','intents'=>['journey_report']],
      'admin_campaign_validation'=>['label'=>'Validate campaign graph','contexts'=>['admin'],'tool'=>'campaign_graph_validate','intents'=>['validate_campaign']],
      'admin_campaign_drafts'=>['label'=>'Prepare reviewed campaign draft','contexts'=>['admin'],'tool'=>'campaign_draft_save','intents'=>['prepare_campaign']],
      'admin_journey_drafts'=>['label'=>'Prepare reviewed lifecycle journey','contexts'=>['admin'],'tool'=>'automation_draft_save','intents'=>['prepare_journey']],
      'admin_campaign_publication'=>['label'=>'Publish campaign through owner UI','contexts'=>['admin'],'tool'=>'campaign_publish','intents'=>['publish_campaign']],
      'admin_campaign_outreach'=>['label'=>'Send campaign through owner UI','contexts'=>['admin'],'tool'=>'campaign_send_email','intents'=>['send_campaign']]
    ];
}

/** Only known calling contexts are eligible for discovery; fail closed. */
function sf_agent_skill_context_role(string $context): ?string {
    return match($context) {
        'public'=>'guest', 'customer'=>'fan', 'admin'=>'admin', default=>null
    };
}

/**
 * Return a public-safe descriptor, never callable function references.
 * Inspect actual registered tool policy for every descriptor.
 */
function sf_agent_skill_manifest(string $skillId,string $context): ?array {
    $role=sf_agent_skill_context_role($context);
    $def=sf_agent_skill_definitions()[$skillId]??null;
    if($role===null || !$def || !in_array($context,$def['contexts'],true))return null;
    $toolId=$def['tool'];
    $registered=sf_agent_tool_registry()[$toolId]??null;
    if(!$registered || !sf_agent_tool_allowed($toolId,$role,true))return null;
    // A skill may be described, but even a confirmed invocation is governed
    // by the underlying tool registry, existing endpoints and proposal ledger.
    $proposal=$context==='admin' && sf_agent_tool_executable($toolId,$role,true);
    return [
      'id'=>$skillId,
      'label'=>$def['label'],
      'context'=>$context,
      'intents'=>$def['intents'],
      'tool_id'=>$toolId,
      'kind'=>$registered['kind'],
      'execution_location'=>$registered['execution'],
      'requires_exact_approval'=>(bool)$registered['confirmation'],
      'may_prepare_reviewed_proposal'=>$proposal,
      'may_execute_directly'=>false,
      'contract'=>'stonefellow.skill.v1'
    ];
}

function sf_agent_skill_inventory(string $context): array {
    if(sf_agent_skill_context_role($context)===null)return [];
    $skills=[];
    foreach(sf_agent_skill_definitions() as $skillId=>$_) {
        $manifest=sf_agent_skill_manifest($skillId,$context);
        if($manifest!==null)$skills[]=$manifest;
    }
    return $skills;
}

function sf_agent_skill_for_intent(string $context,string $intent): ?array {
    if(sf_agent_skill_context_role($context)===null)return null;
    foreach(sf_agent_skill_inventory($context) as $skill) {
        if(in_array($intent,$skill['intents'],true))return $skill;
    }
    return null;
}
