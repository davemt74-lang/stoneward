<?php
declare(strict_types=1);
function sf_catalog():array{return [];}
function sf_seo_public_track(string $id):?array{return null;}
function sf_campaign_admin_summary():array{return ['campaigns'=>8,'active'=>2,'participants'=>51];}
function sf_crm_admin_summary():array{return ['newsletter'=>19,'contacts'=>40];}
require dirname(__DIR__).'/api/agent-runtime.php';
$tests=[];
$tests['admin overview intent']=sf_agent_local_route('show campaign dashboard metrics')==='admin_campaign_overview';
$admin=sf_agent_policy('admin_campaign_overview','campaign overview',['_authorized_admin'=>true],1);
$tests['aggregate numbers returned']=str_contains($admin['text'],'8 total campaigns')&&str_contains($admin['text'],'19 opted-in newsletter contacts');
$tests['no fan records in report']=!str_contains($admin['text'],'contacts: 40')&&$admin['action']['type']==='none';
$fan=sf_agent_policy('admin_campaign_overview','campaign overview',[],1);
$tests['unauthorized policy does not return private metrics']=!str_contains($fan['text'],'8 total campaigns');
$source=(string)file_get_contents(dirname(__DIR__).'/api/agent-runtime.php');
$tests['server supplies admin flag']=str_contains($source,"$"."client['_authorized_admin']=$"."isAdmin");
$tests['fan JEV escalation fenced']=str_contains($source,"!$"."isAdmin&&$"."route==='admin_campaign_overview'");
foreach($tests as $name=>$ok)echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;
if(in_array(false,$tests,true))exit(1);
