<?php
declare(strict_types=1);
// 88D2H1: real external-provider call boundary, using a local spy, no network.
function sf_ai_llm_candidates(string $intent):array{
    if($intent!=='agent_brain')throw new RuntimeException('Wrong provider intent');
    return [['provider'=>'openai']];
}
$providerCalls=[];
function sf_openai_call(array $provider,string $system,array $history,string $message,int $tokens):array{
    global $providerCalls;
    $providerCalls[]=compact('provider','system','history','message','tokens');
    return ['text'=>'Safe aggregate response'];
}
require dirname(__DIR__).'/api/agent-admin-brain.php';
$n=0;
function ok88h(bool $condition,string $name):void{
    global $n;
    if(!$condition)throw new RuntimeException('FAIL: '.$name);
    echo "PASS $name\n";$n++;
}
$secret='PRIVATE-SCENE-EVIDENCE-DO-NOT-SEND';
$prior=[
  ['role'=>'user','content'=>'Find production evidence: backstage'],
  ['role'=>'assistant','content'=>"Private Artist World production evidence. SCENE #42: $secret",'action'=>['type'=>'production_evidence_search']],
  ['role'=>'assistant','content'=>"Private Stem Studio details: $secret",'action'=>['type'=>'stem_mix_review']],
  ['role'=>'user','content'=>"My order note: $secret"]
];
$context=[
  'crm_contacts'=>18,'newsletter_opted_in'=>5,'campaigns_total'=>4,'campaigns_active'=>2,
  'commerce_available'=>true,'commerce_attention'=>3,
  'journey_counts'=>['journeys_started'=>9,'journeys_completed'=>7,'journeys_failed'=>1,'private_customer_name'=>$secret],
  'commerce_top_orders'=>[$secret],
  'commerce_triage'=>['customer_email'=>$secret],
  'fan_campaign_guidance'=>['notes'=>$secret],
  'fan_journey_signals'=>['raw_story'=>$secret]
];
$safe=sf_admin_agent_external_facts($context);
ok88h($safe['crm_contacts']===18 && $safe['journey_counts']['journeys_started']===9,'Allowlisted numeric aggregate facts retained');
ok88h(!str_contains(json_encode($safe,JSON_THROW_ON_ERROR),$secret),'Sensitive source data absent from provider facts');
ok88h(!array_key_exists('commerce_top_orders',$safe) && !array_key_exists('fan_campaign_guidance',$safe),'Unapproved Admin fields excluded');
$reply=sf_admin_agent_research_reply('What are our campaign totals?',$context,$prior,0);
ok88h($reply==='Safe aggregate response','Provider response returned');
ok88h(count($providerCalls)===1,'Only configured provider invoked');
$call=$providerCalls[0];
ok88h($call['history']===[],'No previous messages leave server, including evidence and order notes');
ok88h(!str_contains(json_encode($call,JSON_THROW_ON_ERROR),$secret),'Nothing private appears in outbound provider payload');
ok88h(str_contains($call['system'],'"crm_contacts":18'),'Approved aggregate facts reach provider');
ok88h($call['message']==='What are our campaign totals?','Only present research question forwarded');
echo "88D2H1: $n checks passed\n";
