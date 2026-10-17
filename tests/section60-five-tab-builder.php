<?php
declare(strict_types=1);
$root=dirname(__DIR__);$read=static fn($p)=>(string)file_get_contents($root.'/'.$p);
$app=$read('assets/js/app.js');$parts=$read('assets/js/turntable-parts.js');$css=$read('assets/css/turntable-builder.css');$boot=$read('api/bootstrap.php');$html=$read('stonefellow-v120.php');$road=$read('docs/stonefellow-player-platform-roadmap.md');
$pos=strpos($app,'function renderBuilder(){');$end=strpos($app,'async function quoteCart()',$pos);
$builder=substr($app,$pos,$end-$pos);
$tabs=['songs'=>'Songs','song-lists'=>'Song Lists','album-artwork'=>'Album Artwork','personal-notes'=>'Personal Notes','saved-drafts'=>'Saved Drafts','submit'=>'Submit'];
$checks=[];
$checks['Exactly six purpose-built tabs defined']=preg_match_all("/\['(songs|song-lists|album-artwork|personal-notes|saved-drafts|submit)','([^']+)'\]/",$builder)==6;
foreach($tabs as $id=>$label){$checks[$label.' has named tab and panel']=str_contains($builder,"['".$id."','".$label."']")&&str_contains($builder,"panel('".$id."',");}
$checks['Accessible ARIA tablist, tabs and tabpanels']=str_contains($builder,'role="tablist"')&&str_contains($builder,'role="tab"')&&str_contains($builder,'role="tabpanel"')&&str_contains($builder,'aria-controls="builder-panel-')&&str_contains($builder,'aria-labelledby="builder-tab-');
$checks['Keyboard arrows Home End select tabs']=str_contains($builder,"e.key==='ArrowRight'")&&str_contains($builder,"e.key==='ArrowLeft'")&&str_contains($builder,"e.key==='Home'")&&str_contains($builder,"e.key==='End'");
$checks['State persists through edit re-renders']=str_contains($app,"let builderTab='songs'")&&str_contains($builder,'builderTab=id;');
$checks['Only active tabpanel is visible']=str_contains($builder,'region.hidden=!active')&&str_contains($css,'.builder-tab-panel[hidden]{display:none!important}');
$checks['Cassette format buttons removed']=!str_contains($builder,'data-format=')&&!str_contains($builder,'format-switch')&&str_contains($builder,"st.builder.format='vinyl'");
$checks['Historic cassette orders remain supported']=str_contains($boot,"$"."format==='cassette'")&&str_contains($read('api/account-data.php'),"['vinyl','cassette']");
$checks['Saved Drafts loads and deletes without changing behavior']=str_contains($builder,"data-builder-draft-load")&&str_contains($builder,"data-builder-draft-manage");
$checks['Album Artwork keeps title and theme']=str_contains($builder,'id="mixTitle"')&&str_contains($builder,'data-theme=');
$checks['Album Artwork supports independent front/back templates']=str_contains($parts,'data-cover-select="coverFront"')&&str_contains($parts,'data-cover-select="coverBack"');
$checks['Song Lists retains sides and ordering']=str_contains($builder,'data-side="A"')&&str_contains($builder,'data-side="B"')&&str_contains($builder,'r.ondragstart')&&str_contains($builder,'agentChoose');
$checks['Songs owns catalog separately with all available public tracks']=str_contains($builder,"panel('songs',songs)")&&str_contains($builder,"panel('song-lists',songLists)")&&str_contains($builder,'catalog.forEach(t=>')&&str_contains($parts,'data-builder-category')&&str_contains($parts,'data-builder-release');
$checks['Saved drafts is second to last']=strpos($builder,"panel('personal-notes',notes)")<strpos($builder,"panel('saved-drafts',draftsHtml)")&&strpos($builder,"panel('saved-drafts',draftsHtml)")<strpos($builder,"panel('submit',submit)");
$checks['Personal Notes separated from album artwork']=str_contains($parts,'function decorateNotes()')&&str_contains($parts,"host.querySelector('.builder-personal-note')")&&!str_contains($parts,'Custom note<textarea');
$checks['Note field persists on input with length count']=str_contains($parts,'input.maxLength=280')&&str_contains($parts,"input.addEventListener('input'")&&str_contains($parts,'ctx.persist()');
$checks['Submit retains cart, save, export and reset']=str_contains($builder,"$('#orderBuild',canvas).onclick")&&str_contains($builder,"$('#saveBuild',canvas).onclick")&&str_contains($builder,"$('#exportBuild',canvas).onclick")&&str_contains($builder,"$('#clearBuild',canvas).onclick");
$checks['Cart serializes artwork and personal note']=str_contains($app,"coverFront:st.builder.coverFront")&&str_contains($app,"coverBack:st.builder.coverBack")&&str_contains($app,"customNote:String(st.builder.customNote");
$checks['Server validates artwork and note in quote']=str_contains($boot,"'coverFront'=>$"."front")&&str_contains($boot,"'coverBack'=>$"."back")&&str_contains($boot,"'customNote'=>sf_clean_text(")&&str_contains($boot,'sf_world_vinyl_approved_reference($front)');
$checks['Module upgrades housed within artwork panel']=str_contains($parts,"host.querySelector('[data-builder-player-options]')?.append(partBox)")&&str_contains($builder,'data-builder-player-options');
$checks['Player is 90 degrees and fits stage']=str_contains($css,'rotate(-90deg)')&&str_contains($css,'left:75%')&&str_contains($css,'top:50%')&&!str_contains($css,'rotate(-45deg)');
$checks['Vinyl record spins independently of chassis']=str_contains($css,'.turntable-stage-body.is-playing .turntable-disc{animation-play-state:running}')&&str_contains($css,'@keyframes turntable-record-rotation');
$checks['Stage hidden below desktop breakpoint']=str_contains($css,'@media(max-width:1023px)')&&str_contains($css,'.builder-turntable-stage{display:none!important}');
$checks['Site header/chat intact']=str_contains($html,'class="chat-footer"')&&str_contains($html,'id="mainContent"');
$checks['Roadmap preserves DSP and cover-template commitments']=str_contains($road,'real audio DSP effects')&&str_contains($road,'Admin-uploaded front/back artwork templates');
$failed=0;foreach($checks as $label=>$ok){echo ($ok?'PASS':'FAIL').' '.$label.PHP_EOL;if(!$ok)$failed++;}
echo 'Section 60: '.(count($checks)-$failed).'/'.count($checks).' checks passed'.PHP_EOL;exit($failed?1:0);
