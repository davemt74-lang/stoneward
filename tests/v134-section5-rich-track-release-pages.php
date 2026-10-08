<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function src($rel){global $root;return (string)file_get_contents($root.'/'.$rel);}
$js=src('assets/js/app.js');$css=src('assets/css/site.css');$version=src('version.php');$migrations=src('api/migrations.php');
ok(str_contains($js,'function releaseForTrack('),'track details resolve release context');
ok(str_contains($js,'function trackArtwork('),'track artwork falls back through release artwork');
ok(str_contains($js,'function relatedTracksFor('),'related-track ranking exists');
ok(str_contains($js,'function creditRows('),'structured track credits are rendered');
ok(str_contains($js,'Words')&&str_contains($js,'Producer'),'songwriter and producer metadata are supported');
ok(str_contains($js,'recording_notes')&&str_contains($js,'lyrics-panel'),'recording notes and lyrics are shown when available');
ok(str_contains($js,'Ask about this song'),'track page exposes Ask about this song');
ok(str_contains($js,'id="storyBuy"'),'track purchase control remains available');
ok(str_contains($js,'id="storyAddBuild"'),'track can be added directly to a custom record');
ok(str_contains($js,'related-track-grid'),'track page renders related tracks');
ok(str_contains($js,'data-open-release'),'track page links to its release');
ok(str_contains($js,'function addReleaseTracksToCart('),'release supports digital bundle cart action');
ok(str_contains($js,'data-play-release'),'release supports play-from-start action');
ok(str_contains($js,'data-release-track-open'),'release tracklist opens rich track details');
ok(str_contains($js,'data-release-add-build'),'release tracklist adds tracks to custom records');
ok(str_contains($js,'release-context'),'release notes and credits surface exists');
ok(str_contains($css,'.track-detail-hero')&&str_contains($css,'.release-detail-hero'),'responsive rich detail hero styles exist');
ok(str_contains($css,'.credit-grid')&&str_contains($css,'.related-track-grid'),'credit and related-track styles exist');
ok(str_contains($version,"'rich_media_pages'=>'track-release-artwork-credits-related-agent'"),'version endpoint retains Section 5 rich-media capability');
ok(str_contains($migrations,"'id'=>'2026-10-08-008'")&&str_contains($migrations,'sf_playlists_ensure_schema'),'Section 5 remains compatible with the pre-Section-6 schema baseline');
echo "Stonefellow v1.3 Section 5 rich track/release pages audit: PASS\n";
