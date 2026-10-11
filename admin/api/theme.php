<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$me=sf_admin_require_auth($method!=='GET');
if($method==='GET')sf_json_response(['ok'=>true,'theme'=>sf_theme_state(),'presets'=>sf_theme_presets(),'fonts'=>sf_theme_font_options(),'csrf'=>sf_admin_csrf()]);
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=sf_clean_text($b['action']??'',40);
try{
    if($action==='save_draft'){
        $r=sf_theme_save_draft((array)($b['theme']??[]));
        sf_log_admin_action((int)$me['id'],'theme_draft_saved','site_theme','public',['readiness'=>$r['readiness'],'preset'=>$r['draft']['preset'],'mode'=>$r['draft']['mode']]);
        sf_agent_brain_log((int)$me['id'],'admin_theme','theme_draft',['route'=>'artist_brand','action'=>'save_theme_draft','context_profile'=>'none','needs_llm'=>false,'requires_confirmation'=>false,'status'=>'completed','request'=>'Save Stonefellow public theme draft','response'=>'Theme draft saved; readiness '.$r['readiness']['score'].'%.']);
        sf_json_response(['ok'=>true,'theme'=>sf_theme_state()]);
    }
    if($action==='publish'){
        if(empty($b['confirmed']))throw new RuntimeException('Publishing the public visual theme requires explicit Admin confirmation.');
        $r=sf_theme_publish_draft();
        sf_log_admin_action((int)$me['id'],'theme_published','site_theme','public',['readiness'=>$r['published_readiness'],'preset'=>$r['published']['preset'],'mode'=>$r['published']['mode']]);
        sf_agent_brain_log((int)$me['id'],'admin_theme','theme_publish',['route'=>'artist_brand','action'=>'publish_theme','context_profile'=>'none','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Publish Stonefellow public visual theme','response'=>'Published '.$r['published']['preset'].' / '.$r['published']['mode'].' theme; readiness '.$r['published_readiness']['score'].'%.']);
        sf_json_response(['ok'=>true,'theme'=>$r]);
    }
    if($action==='reset_draft'){
        $r=sf_theme_reset_draft();
        sf_log_admin_action((int)$me['id'],'theme_draft_reset','site_theme','public',[]);
        sf_json_response(['ok'=>true,'theme'=>$r]);
    }
    if($action==='restore_default'){
        if(empty($b['confirmed']))throw new RuntimeException('Restoring the default public theme requires explicit Admin confirmation.');
        $r=sf_theme_restore_default();
        sf_log_admin_action((int)$me['id'],'theme_default_restored','site_theme','public',['preset'=>$r['published']['preset']]);
        sf_agent_brain_log((int)$me['id'],'admin_theme','theme_restore',['route'=>'artist_brand','action'=>'restore_default_theme','context_profile'=>'none','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Restore default Stonefellow visual theme','response'=>'Restored and published the default Desert theme.']);
        sf_json_response(['ok'=>true,'theme'=>$r]);
    }
    sf_json_response(['ok'=>false,'message'=>'Unsupported theme action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
