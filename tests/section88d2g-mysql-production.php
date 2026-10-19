<?php
declare(strict_types=1);
/**
 * MySQL 8 schema certification for Artist World v1.3.113.
 * Executes on the isolated CI MySQL service after its commerce tests.
 */
if(!extension_loaded('pdo_mysql'))throw new RuntimeException('Real MySQL driver required.');
$user=(string)getenv('SF_E2_DB_USER');$pass=(string)getenv('SF_E2_DB_PASS');
if($user==='')throw new RuntimeException('Isolated CI MySQL credentials required.');
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=stonefellow_test;charset=utf8mb4',
 $user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false]);
$GLOBALS['story_mysql_db']=$db;
function sf_db():PDO{return $GLOBALS['story_mysql_db'];}
function sf_db_config():array{return ['driver'=>'mysql'];}
function sf_media_ensure_schema():void{
 sf_db()->exec('CREATE TABLE IF NOT EXISTS media_assets(
   id BIGINT UNSIGNED NOT NULL PRIMARY KEY,category VARCHAR(30) NOT NULL DEFAULT \'video\')
   ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}
$db->exec('CREATE TABLE IF NOT EXISTS users(id BIGINT UNSIGNED NOT NULL PRIMARY KEY)
 ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
require dirname(__DIR__).'/api/story-capture-core.php';
require_once dirname(__DIR__).'/api/story-media-evidence-core.php';
require dirname(__DIR__).'/api/story-transcript-core.php';
require dirname(__DIR__).'/api/story-scene-core.php';
require dirname(__DIR__).'/api/story-graph-core.php';
require dirname(__DIR__).'/api/story-director-core.php';
require dirname(__DIR__).'/api/story-capture-rights-core.php';
require dirname(__DIR__).'/api/story-inference-queue-core.php';
require dirname(__DIR__).'/api/story-inference-worker-core.php';
require dirname(__DIR__).'/api/story-production-review-core.php';
require dirname(__DIR__).'/api/story-assembly-core.php';
require dirname(__DIR__).'/api/story-composer-core.php';
require dirname(__DIR__).'/api/storyboard-preview-core.php';
require_once dirname(__DIR__).'/api/story-media-evidence-core.php';
require dirname(__DIR__).'/api/story-render-source-core.php';
require dirname(__DIR__).'/api/story-render-worker-core.php';
require dirname(__DIR__).'/api/story-render-review-core.php';
require dirname(__DIR__).'/api/story-render-mix-core.php';
sf_capture_schema();sf_evidence_schema();sf_transcript_schema();
sf_scene_schema();sf_story_graph_schema();sf_director_schema();sf_capture_rights_schema();sf_inference_schema();sf_inference_results_schema();sf_production_import_schema();sf_assembly_schema();sf_composer_schema();sf_storyboard_schema();sf_render_source_schema();sf_render_jobs_schema();sf_render_review_schema();sf_render_mix_schema();
$required=['story_capture_requests','story_capture_submissions','story_media_evidence',
 'story_transcripts','story_transcript_segments','story_scene_annotations',
 'story_graph_entities','story_graph_links','story_director_drafts','story_capture_rights_reviews','story_inference_jobs','story_inference_results','story_inference_imports','story_assemblies','story_assembly_items','story_composer_proposals','story_track_license_reviews','story_render_track_masters','story_render_jobs','story_render_reviews','story_render_mixes'];
foreach($required as $table){
 $q=$db->query('SHOW TABLES LIKE '.$db->quote($table));
 if(!$q->fetchColumn())throw new RuntimeException('Missing production table: '.$table);
 echo 'PASS MySQL 8 table '.$table.PHP_EOL;
}
$q=$db->query("SHOW INDEX FROM story_director_drafts WHERE Column_name='recommendation_code' AND Non_unique=0");
if(!$q->fetch())throw new RuntimeException('Director draft deduplication index missing.');
echo 'PASS MySQL 8 unique Director draft guard'.PHP_EOL;
$q=$db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='story_graph_links' AND REFERENCED_TABLE_NAME IS NOT NULL");
if((int)$q->fetchColumn()<3)throw new RuntimeException('Story graph provenance foreign keys missing.');
echo 'PASS MySQL 8 story graph referential constraints'.PHP_EOL;
$q=$db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='story_capture_rights_reviews' AND REFERENCED_TABLE_NAME IS NOT NULL");
if((int)$q->fetchColumn()<2)throw new RuntimeException('Human rights evidence foreign keys missing.');
echo 'PASS MySQL 8 scoped rights referential constraints'.PHP_EOL;
$q=$db->query("SHOW INDEX FROM story_inference_jobs WHERE Key_name='uq_inference_submission_task' AND Non_unique=0");
if((int)$q->rowCount()<2)throw new RuntimeException('Inference source/task deduplication key missing.');
echo 'PASS MySQL 8 inference duplicate prevention'.PHP_EOL;
echo '88D3A: MySQL production schemas certified'.PHP_EOL;
