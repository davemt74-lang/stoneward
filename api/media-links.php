<?php
declare(strict_types=1);require __DIR__.'/bootstrap.php';sf_media_ensure_schema();$entityType=sf_clean_text($_GET['entity_type']??'',40);$entityId=sf_clean_text($_GET['entity_id']??'',160);
if(!in_array($entityType,['track','release','show','campaign','site','store','member_content','press_kit'],true)||$entityId==='')sf_json_response(['ok'=>false,'message'=>'Invalid media entity.'],422);
sf_json_response(['ok'=>true,'media'=>sf_media_public_links($entityType,$entityId)]);
