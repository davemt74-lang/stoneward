<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$fixture=sys_get_temp_dir().'/stonefellow-canvas-catalog-'.bin2hex(random_bytes(8));
mkdir($fixture.'/config',0700,true);
mkdir($fixture.'/data',0700,true);
register_shutdown_function(static function()use($fixture):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());
    }
    rmdir($fixture);
});
file_put_contents($fixture.'/config/database.php','<?php return '.var_export(['driver'=>'sqlite','path'=>$fixture.'/fixture.sqlite'],true).';');
file_put_contents($fixture.'/config/store.php','<?php return ["currency"=>"CAD"];');
file_put_contents($fixture.'/data/catalog.json',json_encode([
    ['id'=>'one','title'=>'First track','release_id'=>'AlbumA','duration'=>85],
    ['id'=>'two','title'=>'Second track','release_id'=>'AlbumA','duration'=>96],
    ['id'=>'mix','title'=>'Private mix','release_id'=>'AlbumA','public_visible'=>false],
    ['id'=>'foreign','title'=>'From a hidden release','release_id'=>'hidden-album'],
],JSON_THROW_ON_ERROR));
$releases=[
    ['id'=>'AlbumA','title'=>'Album A','track_ids'=>['two','mix','one','two','foreign'],
        'release_date'=>'2024-01-01','artwork'=>['cover'=>'assets/covers/album-a.webp']],
    ['id'=>'AnotherAlbum','title'=>'Another Album','track_ids'=>[],'release_date'=>'2025-01-01'],
    ['id'=>'albums','title'=>'An album named Albums','track_ids'=>[],'release_date'=>'2023-01-01'],
    ['id'=>'hidden-album','title'=>'Do not expose this release','state'=>'published','public_visible'=>false,'release_date'=>'2030-01-01'],
];
file_put_contents($fixture.'/data/releases.json',json_encode($releases,JSON_THROW_ON_ERROR));
file_put_contents($fixture.'/data/shows.json',json_encode([
    ['id'=>'Night','title'=>'Northern night','date'=>'2033-10-10','venue'=>'Horizon Hall','city'=>'Toronto','region'=>'ON','country'=>'CA'],
    ['id'=>'NoOffers','title'=>'Future club','date'=>'2034-10-10','venue'=>'The Club'],
    ['id'=>'Hidden','title'=>'Private venue','status'=>'hidden','public_visible'=>true],
    ['id'=>'Old','title'=>'Past show','date'=>'2015-10-10','venue'=>'Old venue'],
    ['id'=>'Complete','title'=>'Completed show','date'=>'2031-10-10','status'=>'completed','venue'=>'Complete venue'],
    ['id'=>'Cancelled','title'=>'Cancelled show','date'=>'2031-10-10','status'=>'cancelled','venue'=>'Cancelled venue'],
    ['id'=>'Archived','title'=>'Archived show','date'=>'2031-10-10','status'=>'archived','venue'=>'Archived venue'],
],JSON_THROW_ON_ERROR));
define('SF_ROOT',$fixture);
require $root.'/api/bootstrap.php';
require_once $root.'/api/agent-canvas-catalog.php';

function check(bool $ok,string $label): void {
    if(!$ok)throw new RuntimeException($label);
    echo "PASS $label\n";
}

check(sf_agent_canvas_catalog_refs('Show {Merch-Hoodies}, [[tickets:price:Night]], {media:AlbumA}, and media-AlbumA.', ['show-locations'])===
    ['show-locations','merch-hoodies','price-ticket:Night','media-AlbumA'],'normalizes explicit shorthand, scope, and aliases while retaining order');
check(sf_agent_canvas_catalog_refs('Build a media-rich scene, not show-locations-suffix or /media-AlbumA.')===[]&&
    sf_agent_canvas_catalog_refs(' media-AlbumA ')===['media-AlbumA'],'bare references require a whole brief and do not match prose');
check(sf_agent_canvas_catalog_ref('media-albums')==='media-albums'&&sf_agent_canvas_catalog_ref('{media:albums}')==='media:albums',
    'the album wall and a release named Albums have distinct references');
$rejected=false;
try{sf_agent_canvas_catalog_refs('', ['media-../private']);}catch(InvalidArgumentException){$rejected=true;}
check($rejected,'explicit malformed shortcodes are rejected');
$rejected=false;
try{sf_agent_canvas_catalog_refs('Build {merch-caps}');}catch(InvalidArgumentException $e){$rejected=str_contains($e->getMessage(),'{merch-hoodies}');}
check($rejected,'unknown braced catalog references return actionable syntax help');
$rejected=false;
try{sf_agent_canvas_catalog_refs('{media-a} {media-b} {media-c} {media-d} {media-e} {media-f} {media-g}');}catch(InvalidArgumentException){$rejected=true;}
check($rejected,'more than six distinct references are rejected instead of truncated');

$db=sf_db();
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
sf_media_ensure_schema();
sf_campaign_ensure_schema();
sf_entitlements_ensure_schema();
sf_commerce_save_product(['id'=>'hoodie-main','title'=>'Stonefellow hoodie','status'=>'active','category'=>'merch','base_price_cents'=>6400,
    'metadata'=>['private_supplier'=>'fixture private vendor'],'variants'=>[
        ['title'=>'Small / Amber','price_cents'=>6800,'options'=>['Size'=>'Small','Color'=>'Amber']],
        ['title'=>'Medium / Amber','inventory_mode'=>'finite','inventory_qty'=>0],
        ['title'=>'Archived color','status'=>'archived','price_cents'=>1],
    ]],0);
sf_commerce_save_product(['id'=>'sold-out','title'=>'Out of stock hoodie','status'=>'active','category'=>'hoodies','base_price_cents'=>4800,
    'inventory_mode'=>'finite','inventory_qty'=>0],0);
sf_commerce_save_product(['id'=>'draft','title'=>'Draft hoodie','status'=>'draft','category'=>'hoodie'],0);
sf_commerce_save_product(['id'=>'shirt','title'=>'Stonefellow shirt','status'=>'active','description'=>'Pairs with a hoodie'],0);
sf_ticket_offer_save(['show_id'=>'Night','title'=>'General admission','offer_type'=>'ticket','status'=>'published',
    'fulfillment_type'=>'external','external_url'=>'https://example.invalid/fixture-private-checkout','price_cents'=>3500],0);
sf_ticket_offer_save(['show_id'=>'Night','title'=>'Free RSVP','offer_type'=>'rsvp','status'=>'published'],0);
sf_ticket_offer_save(['show_id'=>'Night','title'=>'Venue price','offer_type'=>'ticket','status'=>'published',
    'fulfillment_type'=>'external','external_url'=>'https://example.invalid/fixture-price-at-venue'],0);
sf_ticket_offer_save(['show_id'=>'Night','title'=>'Draft VIP','offer_type'=>'vip','status'=>'draft'],0);
sf_ticket_offer_save(['show_id'=>'Hidden','title'=>'Hidden show admission','offer_type'=>'ticket','status'=>'published'],0);
$db->exec('PRAGMA query_only=ON');

$candidates=sf_agent_canvas_catalog_candidates('Design an Album A listening wall','one');
check($candidates===['merch-hoodies','show-locations','price-ticket','media-albums','media-AlbumA','media-AnotherAlbum'],
    'catalog candidates prioritize an exact album title and current track over recent releases');
check(sf_agent_canvas_catalog_blocks($candidates,false)[4]['items']===[],'model candidate metadata has no live inventory or track item payloads');

[$merch,$shows,$tickets,$album,$wall,$hidden]=sf_agent_canvas_catalog_blocks([
    'merch-hoodies','show-locations','price-ticket','media-AlbumA','media-albums','media-hidden-album',
]);
check(array_column($merch['items'],'id')===['sold-out','hoodie-main']&&$merch['items'][0]['available']===false,
    'only active hoodies are listed, including explicit sold-out status');
$hoodie=$merch['items'][1];
check($hoodie['price_cents']===6400&&$hoodie['currency']==='CAD'&&array_column($hoodie['variants'],'price_cents')===[6800,6400]&&
    array_column($hoodie['variants'],'available')===[true,false]&&$hoodie['variants'][0]['options']['Color']==='Amber',
    'merch variants preserve their exact public prices, options, and stock');
check(array_column($shows['items'],'id')===['Night','NoOffers']&&$shows['items'][0]['location']==='Toronto, ON, CA',
    'show locations list upcoming public shows and omit hidden, past, and inactive records');
check(array_column($tickets['items'],'title')===['General admission','Free RSVP','Venue price']&&array_column($tickets['items'],'price_cents')===[3500,0,null]&&
    $tickets['items'][1]['available']===true&&$tickets['items'][0]['currency']==='CAD'&&$tickets['items'][0]['show_id']==='Night',
    'ticket rows preserve paid, free RSVP, and provider-priced options separately');
check($album['track_ids']===['two','one']&&array_column($album['items'],'id')===['two','one']&&
    array_column($album['items'],'position')===[1,2]&&$album['artwork']==='assets/covers/album-a.webp',
    'album tracks retain public release order and omit hidden or duplicate recordings');
check(array_column($wall['items'],'id')===['AnotherAlbum','AlbumA','albums']&&$wall['items'][1]['track_ids']===['two','one'],
    'the album wall shows only public releases and retains their ordered track IDs');
$serialized=json_encode([$merch,$shows,$tickets,$album,$wall,$hidden],JSON_THROW_ON_ERROR);
check($hidden['available']===false&&!isset($hidden['album_id'])&&$hidden['items']===[]&&
    !str_contains($serialized,'fixture private vendor')&&!str_contains($serialized,'fixture-private-checkout')&&!str_contains($serialized,'Private mix'),
    'unavailable releases reveal no private metadata or action URL');
[$valid,$empty,$invalid]=sf_agent_canvas_catalog_blocks(['price-ticket:Night','price-ticket:NoOffers','price-ticket:Hidden']);
check($valid['available']===true&&count($valid['items'])===3&&$empty['available']===true&&$empty['items']===[]&&
    $invalid['available']===false&&$invalid['items']===[],'scoped ticket references distinguish a public show with no offers from an unavailable show');

$db->exec('PRAGMA query_only=OFF');
$db->exec("UPDATE store_variants SET price_cents=7100 WHERE title='Small / Amber'");
$releases[0]['track_ids']=['one','two'];
file_put_contents($fixture.'/data/releases.json',json_encode($releases,JSON_THROW_ON_ERROR));
[$freshMerch,$freshAlbum]=sf_agent_canvas_catalog_blocks(['merch-hoodies','media-AlbumA']);
check($freshMerch['items'][1]['variants'][0]['price_cents']===7100&&$freshAlbum['track_ids']===['one','two'],
    'reopening the same references uses current variant prices and album order');
