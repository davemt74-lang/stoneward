<?php
declare(strict_types=1);

/**
 * Read-only navigation intents. A page name is never interpreted as a URL.
 * Only exact aliases of installed public routes can be returned to the browser.
 */
function sf_agent_public_page_destinations(): array {
    return [
        'home'=>['label'=>'Home','aliases'=>['home','homepage','home page','start page']],
        'music'=>['label'=>'Music','aliases'=>['music','catalog','music catalog','song catalog','all songs','tracks','songs']],
        'releases'=>['label'=>'Releases','aliases'=>['releases','albums','discography','singles','eps']],
        'archive'=>['label'=>'Archive','aliases'=>['archive','music archive','timeline']],
        'shows'=>['label'=>'Shows','aliases'=>['shows','tour dates','concerts','live shows']],
        'tickets'=>['label'=>'Tickets','aliases'=>['tickets','ticketing','vip tickets']],
        'store'=>['label'=>'Store','aliases'=>['store','shop','merch','merchandise']],
        'videos'=>['label'=>'Videos','aliases'=>['videos','music videos','video gallery']],
        'photos'=>['label'=>'Photos','aliases'=>['photos','photo gallery','galleries']],
        'world'=>['label'=>'Artist World','aliases'=>['artist world']],
        'about'=>['label'=>'About','aliases'=>['about','about stonefellow','artist bio']],
        'contact'=>['label'=>'Contact','aliases'=>['contact','contact us','contact stonefellow']],
        'newsletter'=>['label'=>'Newsletter','aliases'=>['newsletter','mailing list']],
        'community'=>['label'=>'Community','aliases'=>['community','fan community']],
        'search'=>['label'=>'Search','aliases'=>['search','site search']],
        'library'=>['label'=>'My Library','aliases'=>['library','my library','saved music','my collection','my collections']],
        'builder'=>['label'=>'Record Builder','aliases'=>['builder','record builder','vinyl builder','mixtape builder']],
        'cart'=>['label'=>'Cart','aliases'=>['cart','shopping cart','checkout']],
        'account'=>['label'=>'Account','aliases'=>['account','my account','account settings','profile','my profile','settings']],
        'history'=>['label'=>'Listening History','aliases'=>['history','listening history']],
        'plans'=>['label'=>'Plans','aliases'=>['plans','pricing','subscriptions']],
        'membership'=>['label'=>'Membership','aliases'=>['membership','vip membership']],
        'agent-chat'=>['label'=>'Agent Chat','aliases'=>['agent chat','chat','conversation','conversations']],
        'agent-brain'=>['label'=>'Agent Brain','aliases'=>['agent brain','brain']],
        'account-privacy'=>['label'=>'Privacy Center','aliases'=>['privacy center','privacy settings']],
        'privacy'=>['label'=>'Privacy Policy','aliases'=>['privacy policy']],
        'terms'=>['label'=>'Terms','aliases'=>['terms','terms of service']],
    ];
}

function sf_agent_page_navigation_phrase(string $message): ?string {
    $input=strtolower(trim($message));
    if(!preg_match('/^(?:hey\s+(?:stonefellow|agent)[, ]+)?(?:please\s+)?(?:go to|navigate to|switch to|take me to|bring me to|open|show me|show|pull up|bring up|visit)\s+(.+)$/iu',$input,$m))return null;
    $name=trim((string)$m[1]);
    $name=preg_replace('/[.!?]+$/u','',$name)??$name;
    $name=preg_replace('/^(?:the|my|our)\s+/u','',$name)??$name;
    $name=preg_replace('/\s+(?:page|screen|section|tab)$/u','',$name)??$name;
    return trim($name);
}

function sf_agent_public_page_target(string $message): ?array {
    $name=sf_agent_page_navigation_phrase($message);
    if($name===null||$name==='')return null;
    foreach(sf_agent_public_page_destinations() as $view=>$entry){
        if(in_array($name,$entry['aliases'],true))return ['view'=>$view,'label'=>$entry['label']];
    }
    return null;
}

/** Admin destinations must also be installed in admin/assets/admin.js. */
function sf_agent_admin_page_destinations(): array {
    return [
        'dashboard'=>['label'=>'Dashboard','aliases'=>['dashboard','admin dashboard','overview']],
        'catalog'=>['label'=>'Catalog','aliases'=>['catalog','song catalog','track catalog','songs','tracks']],
        'media'=>['label'=>'Media Library','aliases'=>['media','media library','uploaded files']],
        'uploads'=>['label'=>'Folder Import','aliases'=>['folder import','folder upload','bulk import','uploads']],
        'templates'=>['label'=>'Metadata Templates','aliases'=>['metadata templates','templates']],
        'releases'=>['label'=>'Releases','aliases'=>['releases','albums','singles','release manager']],
        'distribution'=>['label'=>'Distribution','aliases'=>['distribution','music distribution']],
        'press'=>['label'=>'Press','aliases'=>['press','epk','press kit']],
        'shows'=>['label'=>'Shows','aliases'=>['shows','tour dates','concerts']],
        'crm'=>['label'=>'CRM','aliases'=>['crm','fans','fans crm','fan management']],
        'automations'=>['label'=>'Automations','aliases'=>['automations','fan automations','segments']],
        'campaigns'=>['label'=>'Campaigns','aliases'=>['campaigns','newsletter campaigns']],
        'products'=>['label'=>'Products','aliases'=>['products','merch','merchandise']],
        'payments'=>['label'=>'Payments','aliases'=>['payments','stripe','stripe settings']],
        'knowledge'=>['label'=>'Knowledge Base','aliases'=>['knowledge','knowledge base']],
        'orders'=>['label'=>'Orders','aliases'=>['orders','customer orders']],
        'commerce-ops'=>['label'=>'Commerce Operations','aliases'=>['commerce operations','commerce ops']],
        'fulfillment'=>['label'=>'Fulfillment','aliases'=>['fulfillment','shipping']],
        'pod'=>['label'=>'POD Handoffs','aliases'=>['pod','pod handoffs']],
        'users'=>['label'=>'Users & Tokens','aliases'=>['users','users and tokens','user accounts']],
        'team'=>['label'=>'Team Workspace','aliases'=>['team','team workspace']],
        'production-review'=>['label'=>'Production Review','aliases'=>['production review','video production review']],
        'packages'=>['label'=>'Packages','aliases'=>['packages','monthly packages']],
        'membership'=>['label'=>'Membership','aliases'=>['membership','vip membership']],
        'ticketing'=>['label'=>'Ticketing','aliases'=>['ticketing','tickets','vip tickets']],
        'ai'=>['label'=>'AI Providers','aliases'=>['ai','ai providers','llm settings']],
        'brain'=>['label'=>'Agent Brain','aliases'=>['brain','agent brain']],
        'agent-chat'=>['label'=>'Agent Chat','aliases'=>['chat','agent chat','admin chat']],
        'customer'=>['label'=>'Customer Lifecycle','aliases'=>['customer lifecycle','lifecycle']],
        'analytics'=>['label'=>'Analytics','aliases'=>['analytics','performance','performance intelligence']],
        'calendar'=>['label'=>'Operating Calendar','aliases'=>['calendar','operating calendar','release calendar']],
        'rights'=>['label'=>'Rights & Licensing','aliases'=>['rights','rights and licensing','licensing']],
        'operations'=>['label'=>'Operations','aliases'=>['operations','system operations']],
        'settings'=>['label'=>'Site Settings','aliases'=>['settings','site settings']],
    ];
}

function sf_agent_admin_page_target(string $message): ?array {
    $name=sf_agent_page_navigation_phrase($message);
    if($name===null||$name==='')return null;
    foreach(sf_agent_admin_page_destinations() as $view=>$entry){
        if(in_array($name,$entry['aliases'],true))return ['view'=>$view,'label'=>$entry['label']];
    }
    return null;
}

/** Section 90J: recognize a specific record, not just its parent page. */
function sf_agent_detail_request(string $message): ?array {
    $input=trim($message);
    if(preg_match('/^(?:please\s+)?(?:open|show(?: me)?|view|pull up|take me to|go to)\s+(?:(?:the|my)\s+)?(?:latest|newest)\s+(album|release)[.!?]*$/iu',$input,$latest)){
        return ['kind'=>'release','title'=>'latest','relative'=>false,'latest'=>true];
    }
    if(preg_match('/^(?:hey\s+(?:stonefellow|agent)[, ]+)?(?:please\s+)?(?:open|show(?: me)?|view|pull up|take me to|go to|navigate to)\s+(?:this|the current)\s+(song|track|album|release)[.!?]*$/iu',$input,$relative)){
        return ['kind'=>in_array(strtolower($relative[1]),['song','track'],true)?'track':'release',
            'title'=>'this','relative'=>true];
    }
    if(!preg_match('/^(?:hey\s+(?:stonefellow|agent)[, ]+)?(?:please\s+)?(?:open|show(?: me)?|view|pull up|take me to|go to|navigate to)\s+(?:(?:the|my)\s+)?(song|track|album|release)\s+(.+)$/iu',$input,$m))return null;
    $kind=in_array(strtolower($m[1]),['song','track'],true)?'track':'release';
    $name=trim(preg_replace('/[.!?]+$/u','',trim($m[2]))??$m[2]);
    $name=preg_replace('/\s+(?:page|screen|details?)$/iu','',$name)??$name;
    $name=trim($name," \t\n\r\0\x0B\"'“”‘’");
    if($name===''||strlen($name)>180)return null;
    return ['kind'=>$kind,'title'=>$name,'relative'=>in_array(strtolower($name),['this','this one','current','current one'],true)];
}

/** Exact normalized title matching only; no fuzzy selection of the wrong song. */
function sf_agent_exact_title_matches(string $title,array $records): array {
    $normalize=static function(string $value):string {
        $value=trim(preg_replace('/\s+/u',' ',$value)??$value);
        return function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);
    };
    $needle=$normalize($title);$matches=[];
    foreach($records as $record){
        if(!is_array($record)||!isset($record['id'],$record['title']))continue;
        if($needle!==''&&$normalize((string)$record['title'])===$needle)$matches[]=$record;
    }
    return $matches;
}

function sf_agent_latest_release_matches(array $releases): array {
    $dated=[];
    foreach($releases as $release){
        $date=(string)($release['release_date']??'');
        if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$date,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))continue;
        $dated[]=$release;
    }
    if(!$dated)return [];
    usort($dated,static fn($a,$b)=>strcmp((string)$b['release_date'],(string)$a['release_date']));
    $latest=(string)$dated[0]['release_date'];
    return array_values(array_filter($dated,static fn($row)=>(string)$row['release_date']===$latest));
}

function sf_agent_page_context_request(string $message): bool {
    return (bool)preg_match('/^(?:please\s+)?(?:what page am i on|where am i(?: on (?:the|this) (?:site|website))?|what can i do (?:on this page|here)|what actions (?:are available|can i take) here)[.!?]*$/iu',trim($message));
}

/** Client route data is only a hint. Names and entities are resolved server-side. */
function sf_agent_public_page_context(array $client,array $tracks=[],array $releases=[]): array {
    $view=(string)($client['view']??'');$id=(string)($client['route_id']??'');
    $pages=sf_agent_public_page_destinations();
    if(isset($pages[$view]))return ['view'=>$view,'label'=>$pages[$view]['label']];
    if(in_array($view,['track','release'],true)&&$id!==''){
        $pool=$view==='track'?$tracks:$releases;
        foreach($pool as $record)if((string)($record['id']??'')===$id){
            return ['view'=>$view,'label'=>($view==='track'?'Track':'Release').' “'.(string)$record['title'].'”'];
        }
    }
    return ['view'=>'unknown','label'=>'an unrecognized page'];
}

function sf_agent_admin_page_context(array $client): array {
    $view=(string)($client['view']??'');
    $pages=sf_agent_admin_page_destinations();
    if(!isset($pages[$view]))return ['view'=>'unknown','label'=>'an unrecognized Admin page'];
    return ['view'=>$view,'label'=>$pages[$view]['label']];
}

/** An explicit request to inspect a named track in the existing Admin editor. */
function sf_agent_admin_catalog_editor_request(string $message): ?string {
    $input=trim($message);$title=null;
    if(preg_match('/^(?:please\s+)?(?:open|show(?: me)?|view)\s+(?:the\s+)?(?:song|track)\s+editor\s+for\s+(.+)$/iu',$input,$m))$title=$m[1];
    elseif(preg_match('/^(?:please\s+)?(?:open|show(?: me)?|view)\s+(?:the\s+)?(?:song|track)\s+(.+)\s+in\s+(?:the\s+)?editor[.!?]*$/iu',$input,$m))$title=$m[1];
    if($title===null)return null;
    $title=trim(preg_replace('/[.!?]+$/u','',$title)??$title," \t\n\r\0\x0B\"'“”‘’");
    return $title!==''&&strlen($title)<=180?$title:null;
}
