<?php
declare(strict_types=1);

@ini_set('display_errors','0');
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/canvas-data-core.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){
    header('Allow: GET');
    sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
}
try{
    $action=$_GET['action']??'inventory';
    if($action==='inventory'&&!array_diff(array_keys($_GET),['action'])){
        sf_json_response(['ok'=>true]+sf_canvas_data_inventory());
    }
    if($action==='places'&&!array_diff(array_keys($_GET),['action','id','q'])&&!(isset($_GET['id'],$_GET['q']))){
        $id=$_GET['id']??null;$query=$_GET['q']??'';
        if(!is_string($query)||strlen($query)>100||preg_match('//u',$query)!==1||
            ($id!==null&&(!is_string($id)||strlen($id)>100)))throw new InvalidArgumentException('Choose a valid public location.');
        require_once __DIR__.'/canvas-data-places.php';
        $items=[];
        if(sf_canvas_data_inventory()['products']){
            if($id===null)$items=sf_canvas_data_places(trim($query));
            elseif(($place=sf_canvas_data_place($id))!==null)$items=[$place];
        }
        sf_json_response(['ok'=>true,'items'=>array_map('sf_canvas_data_public_place',$items)]);
    }
    if($action==='read'&&!array_diff(array_keys($_GET),['action','scene_id','gallery_id','alias','location_id'])){
        require_once __DIR__.'/canvas-access-core.php';
        $alias=$_GET['alias']??null;
        if(!is_string($alias))throw new InvalidArgumentException('Choose a live data name.');
        $location=$_GET['location_id']??null;
        if($location!==null&&!is_string($location))throw new InvalidArgumentException('Choose a valid public location.');
        $context=[];
        if(isset($_GET['scene_id']))$context['scene_id']=filter_var($_GET['scene_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(isset($_GET['gallery_id']))$context['gallery_id']=$_GET['gallery_id'];
        $context=sf_canvas_access_context($context);
        $viewer=sf_current_user()??[];
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        sf_json_response(['ok'=>true,'data'=>sf_canvas_data_read($viewer,$context,$alias,null,$location)]);
    }
    throw new InvalidArgumentException('Choose a valid canvas data request.');
}catch(OutOfBoundsException $error){
    sf_json_response(['ok'=>false,'error'=>'canvas_data_not_found','message'=>'Canvas data source unavailable.'],404);
}catch(InvalidArgumentException $error){
    sf_json_response(['ok'=>false,'error'=>'invalid_canvas_data','message'=>$error->getMessage()],422);
}catch(Throwable $error){
    error_log('[Stonefellow canvas data] '.get_class($error));
    sf_json_response(['ok'=>false,'error'=>'canvas_data_unavailable','message'=>'Live data is temporarily unavailable.'],503);
}
