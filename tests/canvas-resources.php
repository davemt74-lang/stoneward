<?php
declare(strict_types=1);

$root=dirname(__DIR__);$fixture=sys_get_temp_dir().'/stonefellow-canvas-resources-'.bin2hex(random_bytes(6));
foreach(['config','data'] as $directory)mkdir($fixture.'/'.$directory,0700,true);
register_shutdown_function(static function()use($fixture):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());
    }
    rmdir($fixture);
});
file_put_contents($fixture.'/config/database.php','<?php return '.var_export(['driver'=>'sqlite','path'=>$fixture.'/fixture.sqlite'],true).';');
file_put_contents($fixture.'/config/store.php','<?php return ["currency"=>"CAD"];');
file_put_contents($fixture.'/data/catalog.json','[]');file_put_contents($fixture.'/data/releases.json','[]');
file_put_contents($fixture.'/data/shows.json',json_encode([
    ['id'=>'public-show','title'=>'Public show','date'=>'2040-01-01','venue'=>'Public Hall'],
    ['id'=>'hidden-show','title'=>'Secret show','date'=>'2040-01-02','venue'=>'Private Hall','public_visible'=>false],
]));
define('SF_ROOT',$fixture);
require $root.'/api/bootstrap.php';
require_once $root.'/api/canvas-resource-core.php';
require_once $root.'/api/agent-scene-history.php';
require_once $root.'/api/agent-canvas-data.php';

$checks=0;
function verify(bool $ok,string $message): void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;echo 'PASS '.$message.PHP_EOL;}
function rejects(callable $run,string $message): void {
    try{$run();}catch(InvalidArgumentException){verify(true,$message);return;}
    throw new RuntimeException('Expected rejection: '.$message);
}

[$brief,$artist,$provided,$sources]=sf_canvas_resource_selection('Build around `{canvas:3}` {canvas:5} {merch:shirt} {ticket:7} {merch:shirt}.');
verify(!str_contains($brief,'{')&&$artist===['merch_ids'=>['shirt'],'ticket_ids'=>['7']]&&$provided&&$sources===[3,5],
    'Composer resources normalize independently and deduplicate without consuming the design brief');
verify(sf_canvas_resource_selection('Design {tickets-clear}', ['merch_ids'=>['shirt']],true)===
    ['Design',['merch_ids'=>['shirt'],'ticket_ids'=>[]],true,null],
    'Clearing tickets preserves the independently selected merchandise');
verify(sf_canvas_resource_selection('Design {merch-clear}')===['Design',['merch_ids'=>[]],true,null],
    'An explicit empty merchandise token clears only that resource kind');
verify(sf_canvas_resource_selection('Design',null,false,[])===['Design',null,false,[]],
    'Explicit empty canvas sources remain distinct from an omitted source selection');
verify(sf_canvas_resource_selection('Design',null,true)===['Design',null,true,null],
    'Explicit null artist inputs retain the request to clear inherited resource scopes');
rejects(fn()=>sf_canvas_resource_selection('Design {merch-clear} {merch:shirt}'),'A resource kind cannot be both cleared and selected');
rejects(fn()=>sf_canvas_resource_selection('Design {canvas:3}',null,false,[4]),'Stale source picker selections cannot silently change the referenced canvas');
rejects(fn()=>sf_canvas_resource_selection('Design {ticket:7}',['ticket_ids'=>[]],true),'Stale ticket tokens cannot overwrite an explicit empty picker selection');
rejects(fn()=>sf_canvas_resource_artist_inputs(['merch_ids'=>[1]]),'Structured resource IDs must be stable strings');
rejects(fn()=>sf_canvas_resource_artist_inputs(['purchase_ids'=>['secret']]),'Artist resource inputs cannot introduce a personal purchase lookup');
rejects(fn()=>sf_canvas_resource_selection('Design {canvas:92233720368547758080}'),'Oversized canvas token IDs are rejected');
verify(sf_canvas_resource_selection('Design {stories-all} {merch:shirt}')===
    ['Design',['merch_ids'=>['shirt'],'stories'=>['mode'=>'all']],true,null],
    'All Stories is an explicit live scope alongside other resource kinds');
verify(sf_canvas_resource_selection('Design `{stories-tag:Behind-The-Song}`')===
    ['Design',['stories'=>['mode'=>'tag','tag'=>'behind-the-song']],true,null],
    'Story tag tokens normalize to one live tag scope');
verify(sf_canvas_resource_selection('Design {story:9} {story:1} {story:9}')===
    ['Design',['stories'=>['mode'=>'selected','story_ids'=>['9','1']]],true,null],
    'Individual story IDs retain selection order and deduplicate');
verify(sf_canvas_resource_selection('Design {stories-clear}', ['ticket_ids'=>['7']],true)===
    ['Design',['ticket_ids'=>['7'],'stories'=>['mode'=>'selected','story_ids'=>[]]],true,null],
    'Clearing stories retains independently selected ticket offers');
rejects(fn()=>sf_canvas_resource_selection('Design {stories-all} {story:1}'),'A story scope cannot select both all and individual IDs');
rejects(fn()=>sf_canvas_resource_selection('Design {stories-tag:live} {stories-tag:studio}'),'Multiple story tag scopes cannot be attached to one selection');
rejects(fn()=>sf_canvas_resource_selection('Design {stories-tag:../private}'),'Malformed tag tokens are rejected');
rejects(fn()=>sf_canvas_resource_selection('Design {story:9223372036854775808}'),'Story IDs must fit the canonical server integer range');
rejects(fn()=>sf_canvas_resource_selection('Design {stories-all}',['stories'=>['mode'=>'selected','story_ids'=>[]]],true),
    'A stale All Stories token cannot overwrite an explicit cleared scope');
rejects(fn()=>sf_canvas_resource_artist_inputs(['stories'=>['mode'=>'all','include_drafts'=>true]]),
    'Story scopes cannot request drafts or user authority');

$db=sf_db();$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');$db->exec('INSERT INTO users VALUES(1),(2)');
sf_agent_scene_history_ensure_schema();
$insert=$db->prepare('INSERT INTO agent_scene_history(user_id,track_id,title,html,css,created_at,canvas_kind,design_brief) VALUES(?,?,?,?,?,?,?,?)');
for($i=1;$i<=36;$i++)$insert->execute([1,'','Own canvas '.$i,'<section></section>','',gmdate('c'),'custom',$i===1?null:'Actual saved prompt '.$i]);
$insert->execute([2,'','Foreign secret canvas','<section></section>','',gmdate('c'),'custom','Foreign secret prompt']);
$foreign=(int)$db->lastInsertId();
$canvases=sf_canvas_resource_inventory(1,'canvas');
verify(count($canvases)===36&&$canvases[35]['id']==='1'&&$canvases[0]['id']==='36',
    'Canvas picker includes every owned history entry beyond the history preview limit');
verify($canvases[0]['design_brief']==='Actual saved prompt 36'&&!isset($canvases[35]['description'])&&
    !str_contains(json_encode($canvases),'Foreign secret'),
    'Picker exposes actual saved prompts and keeps foreign history and invented legacy prompts out');
rejects(fn()=>sf_canvas_resource_validate(1,null,[$foreign]),'Selected source canvases are checked against the requesting owner');
sf_canvas_resource_validate(1,null,[1,36]);
verify(true,'Owner source selection is accepted without an arbitrary three-source cap');

sf_media_ensure_schema();sf_campaign_ensure_schema();sf_entitlements_ensure_schema();
$variants=[];for($i=1;$i<=132;$i++)$variants[]=['title'=>'Color '.$i,'price_cents'=>1000+$i,'options'=>['Color'=>'Color '.$i]];
sf_commerce_save_product(['id'=>'shirt','title'=>'Public shirt','description'=>'Published shirt details','status'=>'active',
    'base_price_cents'=>1000,'metadata'=>['private_supplier'=>'Never expose this'],'variants'=>$variants],0);
sf_commerce_save_product(['id'=>'draft-product','title'=>'Draft secret product','status'=>'draft'],0);
sf_commerce_save_product(['id'=>'sold-out','title'=>'Public sold out product','status'=>'active','inventory_mode'=>'finite','inventory_qty'=>0],0);
$ticket=sf_ticket_offer_save(['show_id'=>'public-show','title'=>'Public entry','offer_type'=>'ticket','status'=>'published','price_cents'=>2400,
    'fulfillment_type'=>'external','external_url'=>'https://example.invalid/checkout'],0);
sf_ticket_offer_save(['show_id'=>'public-show','title'=>'Draft secret ticket','offer_type'=>'ticket','status'=>'draft'],0);
sf_ticket_offer_save(['show_id'=>'hidden-show','title'=>'Secret show ticket','offer_type'=>'ticket','status'=>'published'],0);
$ticketId=(string)$ticket['id'];
$merch=sf_canvas_resource_inventory(1,'merch');$tickets=sf_canvas_resource_inventory(1,'tickets');
verify(count($merch)===2&&$merch[0]['id']==='shirt'&&$merch[0]['variant_count']===132,
    'Merch picker groups every public variant under its stable product ID');
verify(count($tickets)===1&&$tickets[0]['id']===$ticketId&&$tickets[0]['show_title']==='Public show',
    'Ticket picker includes published offers from public shows only');
verify(!str_contains(json_encode([$merch,$tickets]),'secret')&&!str_contains(json_encode($merch),'private_supplier'),
    'Picker metadata excludes drafts, private shows and internal supplier fields');
$selection=['merch_ids'=>['shirt'],'ticket_ids'=>[$ticketId]];
sf_canvas_resource_validate(1,$selection);
$context=sf_canvas_resource_artist_context($selection);
verify(count($context['published_merch'])===132&&count($context['published_ticket_offers'])===1,
    'Selected commerce context includes all authoritative matching rows without a sample cap');
rejects(fn()=>sf_canvas_resource_validate(1,['merch_ids'=>['draft-product']]),'Private or withdrawn merchandise cannot enter a new paid build');
rejects(fn()=>sf_canvas_resource_validate(1,['ticket_ids'=>['999999']]),'Unavailable ticket IDs fail before a provider call');
$html='<section class="sf-dataset-published_merch"><div class="sf-data-row"><span class="sf-field-title"></span></div></section><section class="sf-dataset-published_ticket_offers"><div class="sf-data-row"><span class="sf-field-title"></span></div></section>';
$data=sf_agent_canvas_data_for_scene(['id'=>1],$html,'',null,$selection);
verify(count($data['published_merch']['rows'])===132&&count($data['published_ticket_offers']['rows'])===1,
    'Runtime dataset delivery preserves the whole explicitly selected product and ticket scope');
$empty=sf_agent_canvas_data_for_scene(['id'=>1],$html,'',null,['merch_ids'=>[]]);
verify($empty['published_merch']['rows']===[]&&count($empty['published_ticket_offers']['rows'])===1,
    'Explicit empty merchandise does not disable independently unscoped public ticket data');
$db->exec("UPDATE store_products SET status='hidden' WHERE id='shirt'");
verify(sf_canvas_resource_artist_context($selection)['published_merch']===[],
    'Reopened canvas context drops newly private resources while retaining the saved selection');
$data=sf_agent_canvas_data_for_scene(['id'=>1],$html,'',null,$selection);
verify($data['published_merch']['rows']===[],
    'Runtime selection is rechecked against current public visibility');
rejects(fn()=>sf_canvas_resource_inventory(0,'canvas'),'Resource inventory requires an authenticated account');
rejects(fn()=>sf_canvas_resource_inventory(1,'purchases'),'Picker kinds cannot request unrelated account datasets');
mkdir($fixture.'/storage/knowledge',0700,true);
file_put_contents($fixture.'/storage/knowledge/index.json',json_encode(['files'=>[
    ['id'=>'K-0000000001','name'=>'Public notes','public_agent'=>true,'text'=>'Artist reference','stored_path'=>'private/file'],
    ['id'=>'K-0000000002','name'=>'Internal notes','public_agent'=>false,'text'=>'Private reference'],
]]));
verify(sf_canvas_resource_inventory(0,'knowledge')===[['id'=>'K-0000000001','title'=>'Public notes']],
    'Public knowledge inventory excludes private entries, excerpts and storage paths');
$references=['knowledge_ids'=>['K-0000000001'],'production_ids'=>['7-r2']];
verify(sf_canvas_resource_selection('Design {knowledge:K-0000000001} {production:7-r2}')===
    ['Design',$references,true,null],'Both picker tokens become independent structured selections');
rejects(fn()=>sf_canvas_resource_validate_scopes(['knowledge_ids'=>['K-0000000002']]),
    'Private knowledge cannot be attached directly');
$db->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'member'");
$db->exec("ALTER TABLE users ADD COLUMN status TEXT NOT NULL DEFAULT 'active'");
$db->exec("UPDATE users SET role='admin' WHERE id=1");
$db->exec("CREATE TABLE story_assemblies(id INTEGER PRIMARY KEY,title TEXT,revision INTEGER,created_by INTEGER,updated_at TEXT)");
$db->exec("CREATE TABLE story_assembly_items(assembly_id INTEGER)");
$db->exec("INSERT INTO story_assemblies VALUES (7,'My edit',2,1,'now'),(9,'Another edit',2,2,'now')");
$db->exec("INSERT INTO story_assembly_items VALUES(7),(7),(9)");
verify(sf_canvas_resource_inventory(1,'production')===[['id'=>'7-r2','title'=>'My edit','revision'=>2,'item_count'=>2]],
    'Production returns only the active admin’s own pinned project');
verify(sf_canvas_resource_inventory(0,'production')===[]&&sf_canvas_resource_inventory(2,'production')===[],
    'Guests and members receive no private production metadata');
sf_canvas_resource_validate_scopes($references,1);
verify(sf_canvas_resource_artist_context($references,true,1)===['knowledge'=>[
    ['id'=>'K-0000000001','title'=>'Public notes','reference_excerpt'=>'Artist reference']],
    'production'=>[['revision'=>2,'item_count'=>2]]],'Model receives bounded public knowledge and minimized production metadata');
rejects(fn()=>sf_canvas_resource_validate_scopes(['production_ids'=>['9-r2']],1),
    'Forged cross-account project references are rejected');
require_once $root.'/api/canvas-gallery-core.php';
rejects(fn()=>sf_canvas_gallery_snapshot(['html'=>'<p>Test</p>','css'=>'','artist_inputs'=>$references],'Test'),
    'Private production attachments block gallery submission');
$db->exec('UPDATE story_assemblies SET revision=3 WHERE id=7');
rejects(fn()=>sf_canvas_resource_validate_scopes($references,1),'Inherited stale production revisions require reselection');
file_put_contents($fixture.'/storage/knowledge/index.json','{"files":[]}');
rejects(fn()=>sf_canvas_resource_validate_scopes(['knowledge_ids'=>['K-0000000001']],1),
    'Withdrawn knowledge requires reselection before a revision');
echo 'Canvas resources: '.$checks.' checks passed'.PHP_EOL;
