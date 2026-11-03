'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const read=p=>fs.readFileSync(p,'utf8');
const core=read('api/image-studio-moodboards.php'),api=read('admin/api/image-generation.php'),ui=read('admin/assets/image-generation.js'),board=read('admin/assets/image-moodboards.js');
test('Admin auth and CSRF gate remain in front of every board action',()=>{
 assert.match(api,/sf_admin_require_auth\(\$write\)/);
 assert.ok(api.indexOf('sf_admin_require_auth')<api.indexOf("['board_add','board_remove','board_move']"));
 assert.match(api,/sf_image_studio_moodboard_change\(\(int\)\$me\['id'\]/);
 assert.match(core,/\(\$input\['confirmed'\]\?\?false\)!==true/);
});
test('Single-element selector regression is corrected without changing other player UI',()=>{
 for(const n of ['style','archive-style','preset'])assert.ok(ui.includes("$$('[data-imagegen-"+n+"]',canvas).forEach"));
 new vm.Script(ui);new vm.Script(board);
});
test('Source handoff resets existing image and mask consent without replacing the prompt',()=>{
 const part=ui.slice(ui.indexOf('onUseReference:assetId=>'),ui.indexOf('async function act('));
 assert.match(part,/dispatchEvent\(new Event\('change'\)\)/);
 assert.doesNotMatch(part,/\[name="prompt"\].*value=/);
 assert.match(ui,/\$\('#imagegenMaskSelect'\)\.value=''/);
});
test('All mutation SQL remains style scoped and audit is part of the same transaction',()=>{
 assert.match(core,/FOR UPDATE/);assert.match(core,/SET is_active=is_active/);
 assert.match(core,/WHERE id=\? AND style_id=\?/);
 assert.match(core,/WHERE style_id=\? AND id=\?/);
 assert.ok(core.indexOf('sf_log_admin_action(')<core.indexOf('$pdo->commit()'));
 assert.match(core,/rollBack\(\)/);assert.match(core,/count\(\$rows\)>=8/);
});
test('Media preview eligibility, source preservation and no-provider boundary',()=>{
 assert.match(core,/sf_image_gen_available_references\(\)/);assert.match(core,/sf_media_guard_generated_image_approval/);
 assert.match(core,/ON DELETE CASCADE/);assert.doesNotMatch(core,/DELETE FROM media_assets|curl_exec|sf_image_gen_request|sf_media_attach/);
 assert.doesNotMatch(board,/action:'generate'|action:'image_studio_apply'|fetch\(/);
});
test('Dynamic captions use textContent, busy controls recover and detached UI is not repainted',()=>{
 assert.match(board,/el.textContent=text/);assert.match(board,/if\(pending\|\|isBusy\(\)\)return/);
 assert.match(board,/finally\{pending=false;if\(host.isConnected\)controls\(\)/);
 assert.match(board,/No automatic retry was made/);
});
test('Module is loaded ahead of the main Studio renderer and responsive board rules ship',()=>{
 const shell=read('admin/index.php');assert.ok(shell.indexOf('image-moodboards.js')<shell.indexOf('image-generation.js'));
 assert.match(ui,/SFImageMoodBoards\?\.mount/);
 assert.match(read('admin/assets/image-generation.css'),/\.imagegen-board-gallery/);
 const version=read('version.php').match(/'stonefellow'=>'(1\.3\.\d+)'/)?.[1];assert.ok(version&&Number(version.split('.')[2])>=235);
 for(const p of ['admin/index.php','stonefellow-v120.php','service-worker.js'])assert.ok(read(p).includes(version));
});
