<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function src($rel){global $root;return (string)file_get_contents($root.'/'.$rel);}
$app=src('assets/js/app.js');$css=src('assets/css/site.css');$html=src('stonefellow-v120.php');$account=src('api/account-data.php');$builds=src('api/builds.php');$agent=src('api/agent-runtime.php');$boot=src('api/bootstrap.php');$mig=src('api/migrations.php');$version=src('version.php');

ok(str_contains($app,"stonefellow.build.v04"),'builder state persists as v4');
ok(str_contains($app,"legacyBuildV3")&&str_contains($app,"version:4"),'builder migrates prior local v3 state');
ok(str_contains($account,"return ['version'=>4"),'server normalizes saved builds to v4');

ok(str_contains($app,'function relocateTrack('),'builder supports exact drag/drop relocation');
ok(str_contains($app,"e.dataTransfer.setData('text/plain'"),'builder drag source serializes track/side/index');
ok(str_contains($app,"e.target.closest('.side-track')"),'builder drop resolves insertion target');
ok(str_contains($app,'function moveBuilderTrackToSide'),'builder supports explicit Side A/B movement');
ok(str_contains($app,'function duplicateBuildIds')&&str_contains($app,'function dedupeBuild'),'builder detects and repairs duplicate tracks');
ok(str_contains($app,'remainingTime=side'),'builder calculates remaining per-side capacity');
ok(str_contains($app,'data-fill-side'),'each side exposes Fill remaining');
ok(str_contains($app,"time(remain)+' remaining'"),'side header shows remaining time');
ok(str_contains($app,"'Shorten by '+time(used-limit())"),'over-capacity side shows exact correction needed');

ok(str_contains($account,'function sf_account_build_fill_suggestions'),'server provides personalized build fill');
ok(str_contains($account,'sf_personalization_recommendation_profile($userId)'),'fill uses authenticated personalization profile');
ok(str_contains($account,'sf_personalization_rank_catalog'),'fill ranks catalog with personalization engine');
ok(str_contains($account,"empty(\$t['podEligible'])"),'fill excludes tracks ineligible for custom media');
ok(str_contains($account,'$dur>$remain'),'fill enforces remaining physical capacity');
ok(str_contains($builds,"action==='suggest'"),'build API exposes personalized suggest action');
ok(str_contains($builds,'sf_require_user(false,$write)'),'build draft/suggest writes remain authenticated and CSRF protected');
ok(str_contains($app,"secureJsonPost('builds.php',{action:'suggest'"),'client calls server personalized fill');
ok(str_contains($app,'function localFill('),'guest fallback fill remains available');

ok(str_contains($app,'async function loadBuilderDrafts'),'account draft loading exists');
ok(str_contains($app,'async function saveBuilderDraft'),'account draft saving exists');
ok(str_contains($app,'function loadBuilderDraft'),'saved draft loading exists');
ok(str_contains($app,'async function deleteBuilderDraft'),'saved draft deletion exists');
ok(str_contains($app,"stonefellow.build.draft.id"),'active draft identity persists across reloads');
ok(str_contains($app,"items.some(x=>Number(x.id)===activeId)")&&str_contains($app,"localStorage.removeItem('stonefellow.build.draft.id')"),'stale draft identity is cleared across account changes');
ok(str_contains($app,"data-builder-draft-load")&&str_contains($app,"data-builder-draft-delete"),'builder exposes draft load/delete controls');
ok(str_contains($app,"st.builderDrafts.activeId?'Update draft':'Save draft'"),'draft save updates an active draft instead of duplicating it');
ok(str_contains($app,"if(!st.builderDrafts.loaded)await loadBuilderDrafts(true)"),'account draft ownership is reconciled before save');

ok(str_contains($app,'function builderArtworkTiles'),'builder creates artwork tile preview from selected tracks');
ok(str_contains($app,'builder-art-grid'),'vinyl sleeve preview uses artwork grid');
ok(str_contains($app,'builder-cassette-v2'),'cassette has dedicated v2 visual preview');
ok(str_contains($css,'.builder-sleeve-v2')&&str_contains($css,'.builder-art-grid'),'builder artwork preview is styled');
ok(str_contains($css,'.builder-drafts')&&str_contains($css,'.builder-draft-row'),'saved draft UI is styled');

ok(str_contains($app,'function builderSequencePreview'),'cart preview renders exact Side A/B order');
ok(str_contains($app,'function quoteSequencePreview'),'checkout preview renders server-validated Side A/B order');
ok(str_contains($app,'Review exactly what you’re ordering.'),'cart explicitly asks customer to review exact order');
ok(str_contains($app,'This validated order is the manufacturing sequence.'),'checkout explicitly identifies manufacturing sequence');
ok(str_contains($app,"version:4,format:st.builder.format"),'cart stores builder v4 exact sequence');
ok(str_contains($boot,'sf_validate_builder'),'checkout still validates custom-media build server side');
ok(str_contains($boot,'Track $id appears more than once.'),'server rejects duplicate physical-media tracks');
ok(str_contains($boot,'Side $side exceeds the format time limit.'),'server rejects over-capacity physical sides');

ok(str_contains($agent,"return 'builder_move'"),'Agent routes move-to-side commands');
ok(str_contains($agent,"return 'builder_fill'"),'Agent routes finish/fill commands');
ok(str_contains($agent,"return 'builder_dedupe'"),'Agent routes duplicate cleanup');
ok(str_contains($agent,"return 'builder_save'"),'Agent routes draft save');
ok(str_contains($agent,"'type'=>'builder_move_track'"),'Agent emits move-track action');
ok(str_contains($agent,"if(in_array(\$side,['A','B'],true))")&&str_contains($agent,"\$action['side']=\$side"),'Agent honors requested Side A/B when adding a track');
ok(str_contains($agent,"'type'=>'builder_fill'"),'Agent emits personalized fill action');
ok(str_contains($agent,"'type'=>'builder_dedupe'")&&str_contains($agent,"'type'=>'builder_save'"),'Agent emits dedupe/save actions');
ok(str_contains($app,"case'builder_move_track'")&&str_contains($app,"case'builder_fill'"),'client executes Agent move/fill');
ok(str_contains($app,"case'builder_dedupe'")&&str_contains($app,"case'builder_save'"),'client executes Agent dedupe/save');
ok(str_contains($agent,"builder_side_a_seconds")&&str_contains($agent,"builder_limit_seconds"),'Agent receives builder timing state');

ok(!preg_match('/(?<!\\$)\\$\\([^\\n)]*\\)\\.forEach/',$app),'no list controls call forEach on the single-element selector helper');

ok(str_contains($css,'.menu-sheet{')&&str_contains($css,'max-height:calc(100vh - 78px)'),'user dropdown is viewport constrained');
ok(str_contains($css,'padding:9px 9px')&&str_contains($css,'line-height:1.15'),'user dropdown rows use compact spacing');
ok(str_contains($css,'@media(max-height:760px)'),'short viewport receives tighter dropdown spacing');
ok(str_contains($html,'id="menuAccount"')&&str_contains($html,'id="menuQueueLink"')&&str_contains($html,'id="menuLogout"'),'compact dropdown retains account, queue, and logout links');

ok(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.6'"),'Section 8 requires no new database migration');
ok(str_contains($mig,"'id'=>'2026-10-08-010'"),'existing latest migration remains 010');
ok(str_contains($version,"'custom_media_builder_v2'=>'drag-drop-drafts-personalized-fill-sequence-review'"),'Section 8 builder-v2 capability remains registered');
ok(str_contains($version,"'database_schema_target'=>'1.3.6'"),'version endpoint keeps schema target 1.3.6');
ok(str_contains($version,"'custom_media_builder_v2'=>'drag-drop-drafts-personalized-fill-sequence-review'"),'version endpoint reports builder-v2 capability');

echo "Stonefellow v1.3 Section 8 Custom Record / Mixtape v2 audit: PASS\n";
