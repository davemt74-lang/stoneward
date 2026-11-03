from pathlib import Path
root=Path('.')
def patch(path,old,new,count=1):
 p=root/path;s=p.read_text();assert s.count(old)==count,(path,old[:90],s.count(old));p.write_text(s.replace(old,new))
patch('api/image-studio-styles.php','/** 91B20H',"require_once __DIR__.'/image-studio-moodboards.php';\n\n/** 91B20H")
patch('api/image-studio-styles.php',"    $now=gmdate('c');","    sf_image_studio_moodboard_schema($pdo,$sqlite);\n    $now=gmdate('c');")
patch('api/image-studio-styles.php',"    unset($row['created_by'],$row['created_at'],$row['updated_at']);","    $row['moodboard']=sf_image_studio_moodboard_items((int)$row['id']);\n    unset($row['created_by'],$row['created_at'],$row['updated_at']);")
patch('api/image-studio-styles.php',"function sf_image_studio_archive_style(int $id): void {","function sf_image_studio_archive_style(int $id): void {\n    sf_image_studio_style_schema();")
patch('admin/api/image-generation.php',"    if($action==='local_prompt'){","    if(in_array($action,['board_add','board_remove','board_move'],true)){\n        $items=sf_image_studio_moodboard_change((int)$me['id'],(int)($body['style_id']??0),$action,$body);\n        sf_json_response(['ok'=>true,'items'=>$items,'private'=>true]);\n    }\n    if($action==='local_prompt'){")
for name in ['style','archive-style','preset']:
 patch('admin/assets/image-generation.js',f"    $('[data-imagegen-{name}]',canvas).forEach",f"    $$('[data-imagegen-{name}]',canvas).forEach")
patch('admin/assets/image-generation.js',"      composerCache={...composerCache,mask_asset_id:0};","      composerCache={...composerCache,mask_asset_id:0};\n      $('#imagegenMaskSelect').value='';")
patch('admin/assets/image-generation.js',"  }\n  async function act(action,id,success){","""    window.SFImageMoodBoards?.mount({canvas,styles,references:referenceAssets,api,isBusy:()=>busy,
      onUseReference:assetId=>{
        if(busy||!referenceAssets.some(a=>Number(a.id)===assetId))return false;
        $('#imagegenReferenceSelect').value=String(assetId);
        $('#imagegenReferenceSelect').dispatchEvent(new Event('change'));
        $('#imagegenDraftForm').scrollIntoView({behavior:'smooth',block:'start'});
        say('Mood-board reference selected. Review separate image-use permission before saving.');
        return true;
      }});
  }
  async function act(action,id,success){""")
patch('admin/assets/image-generation.js',"head('91B20H · IMAGE STUDIO'","head('91B20I · IMAGE STUDIO'")
patch('admin/index.php','  <script src="assets/image-generation.js?','  <script src="assets/image-moodboards.js?v=<?=rawurlencode(SF_ADMIN_BUILD)?>"></script>\n  <script src="assets/image-generation.js?')
for file in ['version.php','stonefellow-v120.php','admin/index.php','service-worker.js','.htaccess','assets/js/app.js','assets/css/turntable-builder.css']:
 p=root/file;s=p.read_text();assert '1.3.234' in s,file;p.write_text(s.replace('1.3.234','1.3.235'))
for file in ['tests/section91b14c-ios-lunar-mobile.cjs','tests/section91b20h-visual-style-library.test.cjs']:
 p=root/file;s=p.read_text();s=s.replace('1.3.234','1.3.235').replace(r'1\.3\.234',r'1\.3\.235');p.write_text(s)
p=root/'.github/workflows/release-gate.yml';s=p.read_text()
old='          node --test tests/section91b20h-visual-style-library.test.cjs';assert old in s
s=s.replace(old,old+'\n          node --test tests/section91b20i-style-moodboards.test.cjs\n          php tests/section91b20i-style-moodboards.php');p.write_text(s)
# MySQL's original media schema intentionally requires explicit copyright text.
patch('tests/section91b20i-style-moodboards.php','title,caption,technical_json,variants_json,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)','title,caption,copyright_text,technical_json,variants_json,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
patch('tests/section91b20i-style-moodboards.php',"'Image '.$i,'','{}','{}',1,'now','now'","'Image '.$i,'','','{}','{}',1,'now','now'")
css='''

/* 91B20I — ordered private style references; one page scrollbar. */
.imagegen-board-workspace{margin-top:16px;padding:14px;border:1px solid #7bbfdc52;border-radius:13px;min-width:0}
.imagegen-board-workspace p{font-size:.8rem;line-height:1.5}
.imagegen-board-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,200px),1fr));gap:10px;margin:12px 0}
.imagegen-board-item{display:flex;flex-direction:column;min-width:0;margin:0;padding:8px;border:1px solid #8cc0dc40;border-radius:11px}
.imagegen-board-item img{display:block;width:100%;height:150px;flex:none;object-fit:cover;border-radius:7px}
.imagegen-board-item figcaption{margin:7px 0;display:grid;gap:4px;font-size:.8rem;overflow-wrap:anywhere}
.imagegen-board-actions{display:flex;flex-wrap:wrap;gap:5px}
.imagegen-board-actions button{flex:1 1 45%;min-height:44px;font-size:.75rem;padding:6px}
.imagegen-board-add{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) auto;gap:9px;align-items:end}
.imagegen-board-add select,.imagegen-board-add input{min-width:0;max-width:100%;width:100%}
.imagegen-board-workspace button:focus-visible{outline:2px solid currentColor;outline-offset:2px}
@media(max-width:680px){.imagegen-board-workspace{padding:10px}.imagegen-board-add{grid-template-columns:1fr}.imagegen-board-add button{width:100%}.imagegen-board-gallery{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:380px){.imagegen-board-gallery{grid-template-columns:1fr}}
'''
p=root/'admin/assets/image-generation.css';s=p.read_text();assert '.imagegen-board-gallery' not in s;p.write_text(s+css)
p=root/'DEPLOY-MANIFEST.txt'
if p.exists():p.write_text(p.read_text().replace('1.3.234','1.3.235')+'\n91B20I: Private Style Mood Boards; scoped audited references; no provider requests.\n')
