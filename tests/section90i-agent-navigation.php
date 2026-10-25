<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/agent-admin-brain.php';

$passed=0;$failed=0;
$check=static function(string $label,bool $ok)use(&$passed,&$failed):void{
    echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;
    $ok?$passed++:$failed++;
};
$public=[
    'please switch to the releases page'=>'releases',
    'take me to my library'=>'library',
    'open the agent chat screen'=>'agent-chat',
    'show me the music catalog'=>'music',
    'navigate to the privacy policy page'=>'privacy',
    'open account settings'=>'account',
    'go to the shows page'=>'shows',
];
foreach($public as $command=>$view){
    $target=sf_agent_public_page_target($command);
    $check('Public navigation: '.$command,($target['view']??'')===$view);
    $check('Public routes through deterministic Agent action',sf_agent_local_route($command)==='page_navigate');
}
$admin=[
    'open dashboard'=>'dashboard',
    'switch to the agent brain page'=>'brain',
    'take me to analytics'=>'analytics',
    'go to CRM'=>'crm',
    'open site settings'=>'settings',
    'open the catalog page'=>'catalog',
];
foreach($admin as $command=>$view){
    $target=sf_agent_admin_page_target($command);
    $check('Admin navigation: '.$command,($target['view']??'')===$view);
    $plan=sf_admin_agent_plan($command);
    $check('Admin plan stays on the requested page',($plan['view']??'')===$view && empty($plan['requires_confirmation']));
}
foreach([
    'open https://example.net','open /admin','open ../config.php',
    'open secret admin dashboard','navigate to another user orders',
    'open private master files','show me something about production',
] as $invalid){
    $check('Reject unregistered public destination: '.$invalid,sf_agent_public_page_target($invalid)===null);
    $check('Reject unregistered Admin destination: '.$invalid,sf_agent_admin_page_target($invalid)===null);
}
$check('Navigation never grants Admin tool to public fan',!sf_agent_tool_allowed('admin_module_navigate','fan',true));
$check('Public navigation is browser-only and never a write executor',
    sf_agent_orchestration_contract('customer','page_navigate')['tool_id']==='public_page_navigate'
    && !sf_agent_tool_executable('public_page_navigate','fan',true));
$check('Admin navigation is browser-only and never a write executor',
    sf_agent_orchestration_contract('admin','navigate_admin_page')['tool_id']==='admin_module_navigate'
    && !sf_agent_tool_executable('admin_module_navigate','admin',true));
$check('Campaign authoring retains approval',sf_admin_agent_plan('Create a newsletter campaign')['type']==='prepare_campaign');
$check('Song upload remains an upload workflow',sf_admin_agent_plan('Upload an MP3 recording')['type']==='upload_audio');
$check('Non-navigation questions remain conversational',sf_agent_public_page_target('Why did you write this song?')===null);
echo "Section 90I page navigation: $passed passed, $failed failed.".PHP_EOL;
exit($failed?1:0);
