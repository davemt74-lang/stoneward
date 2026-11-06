<?php
declare(strict_types=1);

/** Privacy callers may already own a transaction. Never create schema or restore a deleted queue here. */
function sf_image_analysis_batch_export(int $owner): array {
    try{
        $q=sf_db()->prepare('SELECT id,scope,mode,status,remaining_count,created_at,updated_at FROM image_analysis_batch_jobs WHERE user_id=? ORDER BY created_at');
        $q->execute([$owner]);$jobs=$q->fetchAll();$q->closeCursor();
        foreach($jobs as &$job){
            $q=sf_db()->prepare('SELECT image_id,request_id,status,title,error,result_json FROM image_analysis_batch_items WHERE user_id=? AND job_id=? ORDER BY position');
            $q->execute([$owner,$job['id']]);$job['items']=$q->fetchAll();
            foreach($job['items'] as &$item){$item['result']=json_decode($item['result_json'],true,16,JSON_THROW_ON_ERROR);unset($item['result_json']);}unset($item);
        }
        unset($job);return $jobs;
    }catch(PDOException $error){if($error->getCode()==='42S02'||str_contains(strtolower($error->getMessage()),'no such table'))return [];throw $error;}
}

function sf_image_analysis_batch_clear(int $owner): array {
    try{
        $q=sf_db()->prepare('SELECT COUNT(*) FROM image_analysis_batch_items WHERE user_id=?');$q->execute([$owner]);$items=(int)$q->fetchColumn();
        $q=sf_db()->prepare('DELETE FROM image_analysis_batch_jobs WHERE user_id=?');$q->execute([$owner]);
        return ['image_analysis_batch_jobs'=>$q->rowCount(),'image_analysis_batch_items'=>$items];
    }catch(PDOException $error){if($error->getCode()==='42S02'||str_contains(strtolower($error->getMessage()),'no such table'))
        return ['image_analysis_batch_jobs'=>0,'image_analysis_batch_items'=>0];throw $error;}
}
