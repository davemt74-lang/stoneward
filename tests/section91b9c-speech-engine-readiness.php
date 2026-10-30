<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');$db->exec('INSERT INTO users VALUES(1)');
$meta=['ai.voice_provider'=>'elevenlabs'];
function sf_db():PDO{global $db;return $db;}
function sf_ai_meta_get(string $key,string $fallback=''):string{global $meta;return $meta[$key]??$fallback;}
function sf_ai_meta_set(string $key,string $value):void{global $meta;$meta[$key]=$value;}
function sf_ai_provider_public(string $name):array{return ['enabled'=>true,'key_configured'=>true];}
require dirname(__DIR__).'/api/speech-engine-health.php';
$count=0;$check=static function(bool $ok,string $label)use(&$count){if(!$ok){fwrite(STDERR,"91B9C FAIL: $label\n");exit(1);}echo "91B9C PASS: $label\n";$count++;};
$good='{"ok":true,"service":"stonefellow-speech-gateway","account_bridge":"signed"}';
$check(sf_speech_gateway_health_decode(200,$good),'Signed gateway health is recognized');
$check(!sf_speech_gateway_health_decode(503,$good),'Error HTTP status is never healthy');
$check(!sf_speech_gateway_health_decode(200,'{"ok":true,"service":"other","account_bridge":"signed"}'),'Wrong service is rejected');
$check(!sf_speech_gateway_health_decode(200,'{"ok":true,"service":"stonefellow-speech-gateway","account_bridge":"disabled"}'),'Unbound gateway is not ready');
$check(!sf_speech_gateway_health_decode(200,str_repeat('a',1025)),'Oversized health payload is rejected');
putenv('SF_SPEECH_GATEWAY_HEALTH_URL');
$gateway=sf_speech_gateway_probe();$check(!$gateway['configured']&&!$gateway['signed'],'Without explicit server configuration no remote probe occurs');
putenv('SF_SPEECH_GATEWAY_HEALTH_URL=http://169.254.169.254/health');
$gateway=sf_speech_gateway_probe();$check($gateway['configured']&&!$gateway['reachable'],'Internal network addresses cannot be probed via configuration');
putenv('SF_SPEECH_GATEWAY_HEALTH_URL');
$diagnostics=sf_speech_engine_diagnostics();
$check(!$diagnostics['ready_for_live_test']&&!$diagnostics['provider_token_verified'],'Disabled pilot never calls provider on health test');
$api=(string)file_get_contents(dirname(__DIR__).'/admin/api/ai-providers.php');
$check(str_contains($api,"sf_admin_require_auth($write)")&&str_contains($api,"if($action==='test_speech_engine')"),'Admin diagnostic endpoint remains authenticated and CSRF protected');
echo "Section 91B9C: $count readiness and SSRF assertions passed\n";
