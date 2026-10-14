<?php
declare(strict_types=1);
/** Section 80: deterministic, read-only draft release comparison. */
$root=dirname(__DIR__);
function sf_artist_home_json(array $parts):string {
    return json_encode($parts,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}
require_once $root.'/api/artist-home-drafts.php';
$pass=0;$fail=0;
function ok80(bool $yes,string $name):void {global $pass,$fail;$yes?$pass++:$fail++;echo ($yes?'PASS ':'FAIL ').$name.PHP_EOL;}
$live=[
    ['section_key'=>'hero','enabled'=>true,'position'=>10,
        'eyebrow'=>'Music','heading'=>'Original Artist','body_text'=>'Live text',
        'config'=>['image'=>'/public.jpg']],
    ['section_key'=>'custom_blocks','enabled'=>true,'position'=>20,
        'eyebrow'=>'Shop','heading'=>'Merch','body_text'=>'Visit the store',
        'config'=>['blocks'=>[['id'=>'block01','type'=>'release','reference_id'=>'albumA']]]]
];
$hash=sf_home_design_hash($live);
$draft=['revision'=>1,'base_hash'=>$hash,'sections'=>$live];
$noChange=sf_home_design_review($draft,$live);
ok80($noChange['changed_count']===0&&!$noChange['can_publish'],'Identical draft cannot publish useless new version');
ok80(!$noChange['stale']&&$noChange['read_only']&&$noChange['requires_explicit_confirmation'],'Read-only diff requires explicit approval');
$draft['sections'][0]['heading']='Unpublished text';
$draft['sections'][1]['config']['blocks'][]=['id'=>'block02','type'=>'merch','reference_id'=>'shirtA'];
$review=sf_home_design_review($draft,$live);
ok80($review['can_publish']&&$review['changed_count']===2,'Two real edited sections are publishable');
ok80($review['changed_sections'][0]['section_key']==='custom_blocks'
    &&$review['changed_sections'][1]['section_key']==='hero','Changed section keys sort deterministically');
ok80($review['changed_sections'][0]['blocks_before']===1&&$review['changed_sections'][0]['blocks_after']===2,'Custom block change counts are accurate');
ok80(in_array('config',$review['changed_sections'][0]['fields'],true)
    &&in_array('heading',$review['changed_sections'][1]['fields'],true),'Report identifies changed fields for review');
ok80(!str_contains(json_encode($review,JSON_THROW_ON_ERROR),'Unpublished text')
    &&!str_contains(json_encode($review),'shirtA'),'Review never exposes unpublished text or private block contents');
$draft['base_hash']=str_repeat('f',64);
ok80(sf_home_design_review($draft,$live)['stale']&&!sf_home_design_review($draft,$live)['can_publish'],'Stale public baseline blocks publishing');
$draft['base_hash']=$hash;$draft['revision']=0;
ok80(!sf_home_design_review($draft,$live)['can_publish'],'First unsaved draft is never publishable');
$draft['revision']=2;
ok80(sf_home_design_review($draft,$live)['can_publish'],'Revised private draft can be reviewed independently of published state');
$api=file_get_contents($root.'/admin/api/homepage.php');
$visual=file_get_contents($root.'/admin/assets/homepage-visual.js');
$core=file_get_contents($root.'/api/artist-home-drafts.php');
ok80(str_contains($api,'sf_home_design_review(sf_home_design_state())')
    &&str_contains($api,"'draft_review'=>sf_home_design_review"),'Admin GET and mutation responses include read-only review metadata');
ok80(str_contains($visual,'homepageVisualReview')&&str_contains($visual,'reviewStatus()'),'Visual designer displays change review in existing toolbar');
ok80(str_contains($visual,'publishButton.disabled=dirty||!review?.can_publish||saving'),'Unsaved and no-change drafts cannot use Publish button');
ok80(str_contains($visual,'review?.stale')&&str_contains($visual,'Publish '+Number(review.changed_count)'),'Stale drafts and explicit publish confirmation remain in editor');
ok80(str_contains($core,'This draft has no new homepage changes to publish.'),'Server itself blocks publication of no-op drafts');
ok80(str_contains($api,"if(empty(\$b['confirmed']))"),'Server still requires explicit publish confirmation');
ok80(!str_contains((string)file_get_contents($root.'/api/artist-home-core.php'),'artist_home_design_draft'),'Public homepage never reads private drafts');
echo 'Section 80 homepage release review: '.$pass.'/'.($pass+$fail).' passed'.PHP_EOL;
exit($fail?1:0);
