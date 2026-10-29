<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/agent-orchestration.php';
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed):void{
    echo ($ok?'91B5 PASS ':'91B5 FAIL ').$label."\n";
    if(!$ok)exit(1);
    ++$passed;
};
$tools=sf_agent_tool_registry();
$tool=$tools['audio_player_transport']??null;
$capabilities=['play_track','pause_player','resume_player','next_track','previous_track','player_status','set_player_volume'];
$check(is_array($tool),'Unified audio transport is registered');
$check(($tool['kind']??null)==='local_action' && ($tool['execution']??null)==='browser','Transport cannot mutate the server');
$check(($tool['owner']??null)==='assets/js/agent-audio-player-skill.js','Transport references the actual shared browser module');
$check(($tool['capabilities']??[])===$capabilities,'Registry describes every supported transport command');
$check(($tool['contract']??'')==='stonefellow.audio-player.v1','Registry advertises stable adapter contract');
$check(!sf_agent_tool_executable('audio_player_transport','admin',true),'Admin Agent cannot run local transport as a server mutation');
$check(!sf_agent_tool_executable('audio_player_transport','fan',true),'Customer Agent cannot run local transport as a server mutation');
$check(sf_agent_tool_allowed('audio_player_transport','fan',false),'A signed-in fan can discover player controls');
$check(sf_agent_tool_allowed('audio_player_transport','admin',false),'Admin Agent can discover the same controls');
$check(sf_agent_tool_allowed('audio_player_transport','guest',false),'Public capability metadata does not require login');
foreach(['public','customer','admin'] as $context){
    $skill=sf_agent_skill_manifest('unified_audio_player',$context);
    $check(is_array($skill),'Unified audio skill appears in '.$context.' context');
    $check(($skill['tool_id']??'')==='audio_player_transport','Skill uses the shared transport in '.$context);
    $check(($skill['capabilities']??[])===$capabilities,'Skill declares seven commands in '.$context);
    $check(($skill['execution_contract']??'')==='stonefellow.audio-player.v1','Skill contract remains consistent in '.$context);
    $check(($skill['execution_location']??'')==='browser' && empty($skill['may_execute_directly']),'Skill has browser-only execution in '.$context);
    $check(in_array('unified_audio_player',array_column(sf_agent_skill_inventory($context),'id'),true),'Context inventory discovers the skill in '.$context);
}
foreach(['player_play_named','player_pause','player_resume','player_next','player_previous','player_status','player_volume'] as $intent){
    $skill=sf_agent_skill_for_intent('customer',$intent);
    $check(($skill['id']??'')==='unified_audio_player','Customer intent routed through unified skill: '.$intent);
    $contract=sf_agent_orchestration_contract('admin',$intent);
    $check(($contract['tool_id']??'')==='audio_player_transport' && ($contract['execution_location']??'')==='browser'
        && empty($contract['may_execute_directly']),'Admin orchestration remains local and non-executable: '.$intent);
}
$check(sf_agent_skill_manifest('unified_audio_player','unknown')===null,'Unknown contexts cannot discover the skill');
$check(sf_agent_tool_executable('campaign_send_email','admin',true)===false,'Existing sensitive-action restrictions remain intact');
echo "91B5: $passed skill-registry and permission assertions passed\n";
