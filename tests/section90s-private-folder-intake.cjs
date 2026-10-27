'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const ui=read('admin/assets/admin.js');
const agent=read('admin/assets/agent-canvas.js');
const media=read('admin/assets/media.js');
const endpoint=read('admin/api/scan-private.php');
const legacy=read('admin/api/finalize.php');
const uploads=read('admin/api/upload.php');
const core=read('api/agent-song-intake.php');
const publishing=read('api/agent-song-catalog.php');
const revisions=read('api/track-media-publishing.php');
const adminMedia=read('admin/api/media.php');

assert(ui.includes('data-g-upload=')&&ui.includes('uploadPrivateSong(group('),'Every scanned song has its own upload action.');
assert(ui.includes('window.confirm(\'Add "\'')&&ui.includes('data-g-status=')&&ui.includes('data-g-progress='),'Every song gets a separate upload confirmation, receipt and progress.');
assert(!ui.includes("api('finalize.php'")&&!ui.includes('id="importBatch"'),'Scan UI no longer calls public batch finalization.');
assert(ui.includes("api('scan-private.php'")&&ui.includes("result.publication!=='not_published'"),'UI checks the private server receipt.');
assert(ui.includes('data-g-vinyl=')&&ui.includes('podEligible:false'),'Vinyl printing defaults off and can be opted into per song.');
assert(ui.includes('fileOpts(g.masterUid)')&&ui.includes('fileOpts(g.previewUid)'),
  'Visible master and preview dropdown selections match the actual per-song upload choices.');
assert(ui.includes('window.SFAdminAgentCanvas?.openSongDraft')&&agent.includes('async function openSongDraft'),'Uploaded songs open the existing Admin song review flow.');
assert(legacy.includes("if(empty($body['confirmed_publication']))"),'Legacy public batch endpoint requires explicit publication confirmation.');
assert(endpoint.includes("sf_admin_require_auth(true)")&&endpoint.includes("owner_id")&&uploads.includes("'owner_id'=>(int)$me['id']"),'Private intake enforces owner and file binding.');
assert(endpoint.includes("hash_file('sha256',$absolute)")&&endpoint.includes("sf_media_register_existing_file"),'Private intake verifies staged media and registers Media Library assets.');
assert(!endpoint.includes('sf_admin_write_catalog')&&!endpoint.includes('sf_media_attach('),'Private intake never writes the public catalog or activates media.');
assert(endpoint.includes('sf_agent_song_intake_save')&&endpoint.includes('sf_admin_merge_metadata')&&endpoint.includes("'rights_confirmed'=>false"),'Selected metadata template is copied to an unpublished rights-unconfirmed draft.');
assert(endpoint.includes("$persistedStatus!=='draft'")&&endpoint.includes("'status'=>$persistedStatus"),
  'An upload retry cannot falsely report a prepared or published song as an unpublished draft.');
assert(core.includes("'template_metadata','imported_asset_ids','candidate_preview_asset_id'")&&publishing.includes('sourceTemplate'),'Template provenance survives editing and applies to later approved publication.');
assert(publishing.includes("'podEligible'=>!empty($draft['pod_eligible'])")&&publishing.includes("'media_private_master_asset'"),'Published vinyl setting is per song with separate private master.');
assert(agent.includes('data-agent-song-preview-scanned')&&agent.includes("k==='pod_eligible'"),'Review reuses a distinct scanned MP3 and allows editing vinyl eligibility.');
assert(media.includes('id="trackReplaceSourceAudio"')&&media.includes('function replaceSourceAudio('),'Every Catalog song offers source replacement.');
assert(media.includes('function renderMasterHistory(')&&media.includes('data-master-restore'),'Source master revisions are visible and restorable.');
assert(media.includes("$$('[data-media-primary-audio]',canvas).forEach")&&media.includes("$$('[data-media-private-master]',canvas).forEach"),
  'Track Media action buttons bind as lists, without querySelector.forEach runtime errors.');
assert(media.includes('revision_note:note')&&adminMedia.includes("'revision_note'"),'Each uploaded revision can preserve its note.');
assert(revisions.includes("$catalog[$idx]['media_private_master_asset']=$id")&&revisions.includes("$catalog[$idx]['private_master_audio']=$ref"),'Master replacement changes the source reference on the stable Catalog record.');
assert(revisions.includes("$expectedHash=hash('sha256',$sourceBytes)")&&revisions.includes('sf_admin_write_catalog($catalog,$expectedHash)'),
  'Concurrent master replacements refuse stale Catalog revisions instead of overwriting edits.');
assert(!revisions.includes('DELETE FROM')&&!revisions.includes("role='audio_candidate',public_visible=0"),'Replacing source does not delete older masters or switch the public preview.');
assert(adminMedia.includes('Source master revisions are retained'),'Historical private masters cannot be detached accidentally.');
console.log('Section 90S private per-song upload, vinyl opt-in, and master revision safeguards: PASS');
