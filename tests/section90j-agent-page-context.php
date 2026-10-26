<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/agent-navigation.php';

$passed=0;$failed=0;
$check=static function(string $label,bool $ok)use(&$passed,&$failed):void{
    echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;
    $ok?$passed++:$failed++;
};
$tracks=[['id'=>'song-a','title'=>'Northern Sky'],['id'=>'song-b','title'=>'Night Drive'],['id'=>'song-c','title'=>'Night Drive']];
$releases=[['id'=>'album-a','title'=>'First Light']];
$datedReleases=[['id'=>'r-old','release_date'=>'2024-01-02'],['id'=>'r-new','release_date'=>'2025-03-04']];

$detail=sf_agent_detail_request('Please open the track "Northern Sky"');
$check('Exact track detail parsed',($detail['kind']??'')==='track' && ($detail['title']??'')==='Northern Sky');
$check('Quoted title can match safely',count(sf_agent_exact_title_matches($detail['title'],$tracks))===1);
$check('Title matching tolerates case and whitespace',count(sf_agent_exact_title_matches('  northern   sky ',$tracks))===1);
$check('Album detail parsed',sf_agent_detail_request('show me the album First Light')['kind']==='release');
$check('Current song reference parsed',sf_agent_detail_request('open this song')['relative']===true);
$check('Latest release parsed',!empty(sf_agent_detail_request('open my latest release')['latest']));
$check('Latest published release chosen by valid date',sf_agent_latest_release_matches($datedReleases)[0]['id']==='r-new');
$check('Same-day releases require disambiguation',count(sf_agent_latest_release_matches([['release_date'=>'2025-01-01'],['release_date'=>'2025-01-01']]))===2);
$check('Ambiguous titles stay ambiguous',count(sf_agent_exact_title_matches('Night Drive',$tracks))===2);
$check('Partial titles cannot open an unrelated song',sf_agent_exact_title_matches('Night',$tracks)===[]);
$check('Freeform URLs cannot become details',sf_agent_detail_request('open https://example.com')===null);
$check('Oversized titles rejected',sf_agent_detail_request('open the track '.str_repeat('a',181))===null);
$check('Navigation list command stays page navigation',sf_agent_detail_request('show me the music catalog')===null);

$check('Current-page intent recognized',sf_agent_page_context_request('What page am I on?'));
$check('Capabilities intent recognized',sf_agent_page_context_request('What can I do here?'));
$check('Conversations do not trigger page introspection',!sf_agent_page_context_request('Tell me about this song'));
$check('Published track context resolved by server-visible list',sf_agent_public_page_context(['view'=>'track','route_id'=>'song-a'],$tracks,$releases)['label']==='Track “Northern Sky”');
$check('Hidden or forged track ID cannot be identified',sf_agent_public_page_context(['view'=>'track','route_id'=>'hidden-track'],$tracks,$releases)['view']==='unknown');
$check('Published release context resolved',sf_agent_public_page_context(['view'=>'release','route_id'=>'album-a'],$tracks,$releases)['view']==='release');
$check('Unknown page never echoes a caller-supplied title',sf_agent_public_page_context(['view'=>'<script>'], $tracks, $releases)['view']==='unknown');
$check('Admin context only uses installed pages',sf_agent_admin_page_context(['view'=>'crm'])['label']==='CRM');
$check('Admin rejects unregistered module',sf_agent_admin_page_context(['view'=>'secret_private'])['view']==='unknown');
$check('Admin song editor title parsed',sf_agent_admin_catalog_editor_request('Open the song editor for "Northern Sky"')==='Northern Sky');
$check('Admin editor never treats arbitrary URL as a record',sf_agent_admin_catalog_editor_request('open https://example.com')===null);

echo "Section 90J Agent page context: $passed passed, $failed failed.".PHP_EOL;
exit($failed?1:0);
