<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/agent-admin-brain.php';
require_once dirname(__DIR__).'/api/agent-capability-audit.php';

$fails=0;
$check=static function(bool $valid,string $label)use(&$fails):void{
    echo ($valid?'PASS ':'FAIL ').$label.PHP_EOL;
    if(!$valid)$fails++;
};
$cases=[
    'Can you upload a song?'=>['upload_audio','media'],
    'Upload an MP3 recording'=>['upload_audio','media'],
    'Scan a folder of audio files'=>['bulk_music_import','uploads'],
    'Open metadata templates'=>['metadata_templates','templates'],
    'Open my Media Library'=>['media_library','media'],
    'Add a new song'=>['catalog_manager','catalog'],
    'Create a song'=>['catalog_manager','catalog'],
    'Show me the track catalog'=>['catalog_manager','catalog'],
    'Create an album'=>['release_manager','releases'],
    'Show my releases'=>['release_manager','releases'],
    'Plan a tour'=>['shows_manager','shows'],
    'Check music rights'=>['rights_manager','rights'],
    'Open distribution'=>['distribution_manager','distribution'],
    'Open my press kit'=>['press_manager','press'],
    'Find the knowledge base'=>['knowledge_manager','knowledge'],
    'Manage merch products'=>['products_manager','products'],
    'Review ticketing'=>['ticketing_manager','ticketing'],
    'Review memberships'=>['membership_manager','membership'],
    'Manage user accounts'=>['account_manager','users'],
    'Open the team workspace'=>['team_manager','team'],
    'Open AI providers'=>['ai_settings','ai'],
    'Open site settings'=>['site_settings','settings'],
    'Show operating calendar'=>['operating_calendar','calendar'],
    'Open Stripe settings'=>['payments_manager','payments'],
    'Check fulfillment'=>['fulfillment_manager','fulfillment'],
    'Open POD handoffs'=>['pod_manager','pod'],
    'Open customer lifecycle'=>['customer_lifecycle','customer'],
];
foreach($cases as $query=>[$type,$view]){
    $plan=sf_admin_agent_plan($query);
    $check($plan['type']===$type && $plan['view']===$view && !$plan['requires_confirmation'],"Routes '$query' to $view");
}
$check(count(sf_admin_agent_module_routes())===23,'Exactly 23 known Admin navigation intents');
foreach(sf_admin_agent_module_routes() as $intent=>$route){
    $contract=sf_agent_orchestration_contract('admin',$intent);
    $check($contract['registered'] && $contract['tool_id']==='admin_module_navigate'
        && $contract['may_read_or_navigate'] && !$contract['may_execute_directly']
        && !$contract['may_prepare_reviewed_proposal'] && !$contract['requires_exact_approval'],
        "Admin $intent is navigable, never a privileged executor");
    $check(!sf_agent_orchestration_contract('customer',$intent)['registered'],"Fan cannot discover $intent");
}
$check(!sf_agent_tool_allowed('admin_module_navigate','fan',true),'Fan cannot invoke Admin navigation');
$check(!sf_agent_tool_executable('admin_module_navigate','admin',true),'Navigation never becomes a direct write tool');
$audit=sf_agent_capability_audit();
$check($audit['checks_passed'] && !in_array('admin_module_navigate',$audit['unsafe_owner_paths'],true),
    'Admin asset owner is local and passes the capability audit');
$check(sf_admin_agent_plan('Create a newsletter campaign')['type']==='prepare_campaign','Existing campaign approval path preserved');
$check(sf_admin_agent_plan('Create a campaign for my album release')['type']==='prepare_campaign','Campaign intent wins over album navigation');
$check(sf_admin_agent_plan('Build a follow-up journey')['type']==='prepare_journey','Existing journey approval path preserved');
$check(sf_admin_agent_plan('Create a follow-up for order SF-REAL-111: Contact warehouse')['type']==='prepare_commerce_followup',
    'Order follow-up wins over general journey matching');
$check(sf_admin_agent_plan('Add a song to the timeline')['type']==='production_timeline_media','Song placement preserves governed production tool');
$check(sf_admin_agent_plan('Show CRM subscribers')['type']==='crm_report','Existing CRM inspection preserved');
$check(sf_admin_agent_plan('Hello, how are you?')['type']==='research','General conversation remains conversational');
$source=(string)file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
$check(str_contains($source,'sf_agent_public_artist_provider_context($message,3600)')
    && !str_contains($source,'sf_catalog(),JSON_UNESCAPED_SLASHES'),
    'Provider receives allowlisted public artist context, never raw private catalog');
exit($fails?1:0);
