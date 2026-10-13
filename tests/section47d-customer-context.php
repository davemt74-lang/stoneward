<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/agent-customer-context.php';
$GLOBALS['consent']=[1=>true,2=>false,3=>true,4=>true];
$GLOBALS['memories']=[
  1=>[
    ['memory_key'=>'music mood','memory_text'=>'I enjoy acoustic and mellow arrangements.'],
    ['memory_key'=>'music dislikes','memory_text'=>"I don't like loud electric songs."],
    ['memory_key'=>'other','memory_text'=>'My address is 123 Example Street.'],
  ],
  2=>[['memory_key'=>'music mood','memory_text'=>'I love forbidden orchestra.']],
  3=>[['memory_key'=>'music mood','memory_text'=>'I prefer heavy electric rock.']]
];
function sf_privacy_personalization_enabled(int $uid): bool {return (bool)($GLOBALS['consent'][$uid]??false);}
function sf_agent_customer_memory_list(int $uid): array {
    if($uid===4)throw new RuntimeException('memory data unavailable');
    return $GLOBALS['memories'][$uid]??[];
}
$one=sf_agent_customer_preference_terms(1);
$three=sf_agent_customer_preference_terms(3);
$acoustic=['id'=>'published-1','mood'=>['mellow','acoustic'],'themes'=>['reflective'],'energy'=>2];
$loud=['id'=>'published-2','mood'=>['loud'],'themes'=>['electric'],'energy'=>5];
$checks=[
 'Positive explicitly saved preference extracted'=>in_array('acoustic',$one,true)&&in_array('mellow',$one,true),
 'Negative saved memory not treated as positive'=>!in_array('electric',$one,true)&&!in_array('loud',$one,true),
 'Unrelated memory label is not promoted'=>!in_array('address',$one,true)&&!in_array('example',$one,true),
 'Other customer preferences never bleed into owner'=>in_array('electric',$three,true)&&!in_array('electric',$one,true),
 'Opt-out disables all preference signals'=>sf_agent_customer_preference_terms(2)===[],
 'Anonymous user has no preference signals'=>sf_agent_customer_preference_terms(0)===[],
 'Unavailable memory store falls back safely'=>sf_agent_customer_preference_terms(4)===[],
 'Matching published mood is scored higher'=>sf_agent_customer_track_affinity_score($acoustic,$one)>sf_agent_customer_track_affinity_score($loud,$one),
 'Preference score never exceeds safe cap'=>sf_agent_customer_track_affinity_score($acoustic,array_fill(0,40,'acoustic'))<=12,
 'Empty or withheld preferences have no effect'=>sf_agent_customer_track_affinity_score($acoustic,[])===0,
 'Negative clause fails closed'=>sf_agent_customer_preference_terms_from_rows([['memory_key'=>'mood','memory_text'=>'I love folk but not heavy metal']])===[],
 'Short or malformed memory rows ignored'=>sf_agent_customer_preference_terms_from_rows([[],['memory_key'=>'mood','memory_text'=>'']])===[],
 'Preference tokens bound to 32 words'=>count(sf_agent_customer_preference_terms_from_rows([['memory_key'=>'mood','memory_text'=>'I love '.implode(' ',array_map(fn($i)=>'word'.chr(97+$i%26).chr(97+intdiv($i,26)),range(0,70)))] ]))<=32,
];
$runtime=(string)file_get_contents(dirname(__DIR__).'/api/agent-runtime.php');
$checks['Existing recommendation handler remains in use']=str_contains($runtime,'function sf_agent_recommend_track(');
$checks['Published-only catalog enforced before affinity']=str_contains($runtime,'$catalog=sf_agent_public_catalog();');
$checks['Consent-gated preferences applied to ranking']=str_contains($runtime,'sf_agent_customer_preference_terms($userId)')
    && substr_count($runtime,'sf_agent_customer_track_affinity_score(')>=2;
$checks['No new privileged handler or direct write']=!str_contains(file_get_contents(dirname(__DIR__).'/api/agent-customer-context.php'),'INSERT INTO')
    && !str_contains(file_get_contents(dirname(__DIR__).'/api/agent-customer-context.php'),'DELETE FROM');
$fail=0;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$fail++;}
echo '47D: '.(count($checks)-$fail).'/'.count($checks).' checks passed'.PHP_EOL;
exit($fail?1:0);
