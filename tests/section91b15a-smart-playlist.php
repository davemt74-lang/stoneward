<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/agent-runtime.php';
require_once dirname(__DIR__).'/api/playlists-core.php';

$checks=0;
$check=static function(bool $ok,string $name)use(&$checks):void{
    if(!$ok){fwrite(STDERR,"91B15A FAIL $name\n");exit(1);}
    echo "91B15A PASS $name\n";$checks++;
};
$published=[
    ['id'=>'a','title'=>'Alpha','audio'=>'/a.mp3'],
    ['id'=>'b','title'=>'Beta','audio'=>'/b.mp3'],
    ['id'=>'c','title'=>'Gamma','audio'=>'/c.mp3'],
    ['id'=>'d','title'=>'Unplayable','audio'=>''],
    ['id'=>'a','title'=>'Alpha duplicate','audio'=>'/a.mp3'],
];
$mix=sf_agent_random_playlist_track_ids($published,7);
$check(count($mix)===3,'Random mix includes only distinct playable catalog tracks');
$check(count(array_unique($mix))===count($mix),'Random mix cannot repeat a track');
$check(!in_array('d',$mix,true),'Unplayable uploads are excluded');
$check(count(sf_agent_random_playlist_track_ids($published,2))===2,'Random mix respects the requested count');
$check(sf_agent_random_playlist_track_ids([],7)===[],'Empty catalog yields no action candidates');
$check(sf_agent_random_playlist_requested('Create a random playlist'),'Random playlist is an explicit intent');
$check(sf_agent_random_playlist_requested('Shuffle me a playlist'),'Shuffled playlist is an explicit intent');
$check(!sf_agent_random_playlist_requested('Save this playlist'),'Saving is separate from the temporary mix');
$check(!sf_agent_random_playlist_requested("Don't create a random playlist"),'Negated playlist commands are not executed');
$check(sf_agent_local_route('Create a random playlist')==='playlist_random','Local route selects temporary playlist');
$check(sf_agent_explicit_player_route('stop music')==='player_stop','Stop music is distinct from pause');
$check(sf_agent_explicit_player_route('pause music')==='player_pause','Pause remains non-destructive');
$check(sf_agent_explicit_player_route("Don't stop the music")!=='player_stop','Negated stop music is not treated as a stop command');
$check((sf_agent_public_page_target('load albums')['view']??'')==='releases','Load albums opens the releases page');
$routes=sf_agent_orchestrator_routes(false);
$check(in_array('playlist_random',$routes,true)&&in_array('player_stop',$routes,true),'LLM can select both new music actions');
echo "91B15A: $checks PHP playlist and routing checks passed\n";
