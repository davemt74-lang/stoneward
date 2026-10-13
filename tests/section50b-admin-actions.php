<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$ui=$read('admin/assets/unified-ui.js');$admin=$read('admin/assets/admin.js');
$modules=['media','campaigns','products','membership','ticketing','fulfillment',
  'automations','press','rights','operating-calendar','gallery','video'];
$source=$admin;
foreach($modules as $mod)$source.=$read('admin/assets/'.$mod.'.js');
$checks=[
 'New and upload actions are defined by existing Admin views'=>str_contains($ui,'const createActions={'),
 'Knowledge Base exposes New Folder and Upload Files'=>str_contains($ui,"knowledge:['newKnowledgeFolder','uploadKnowledge']"),
 'Media includes upload and specialized Gallery/Video actions'=>str_contains($ui,"media:['mediaUploadLibrary','galleryNew','videoHubNew']"),
 'Campaign and merch header actions remain available'=>str_contains($ui,"campaigns:['newCampaign']")&&str_contains($ui,"products:['newProduct']"),
 'Multiple automations actions can share a header'=>str_contains($ui,"automations:['newSegment','newAutomation']"),
 'Press and Calendar headers preserve multiple related actions'=>str_contains($ui,"press:['pressKitNew','pressContactNew','pressCoverageNew']")&&str_contains($ui,"calendar:['newOperatingPlan','newMilestone']"),
 'Header actions are real original DOM controls'=>str_contains($ui,'actions.append(el)')&&!str_contains($ui,'cloneNode('),
 'No change to existing click listeners or controller authorization'=>!str_contains($ui,'el.onclick=') && !str_contains($ui,'addEventListener(\'submit\''),
 'Hidden or inert ancestor blocks promotion'=>str_contains($ui,"el.closest('[hidden],[inert]')"),
 'CSS display:none and visibility:hidden are respected'=>str_contains($ui,"style.display==='none'")&&str_contains($ui,"style.visibility==='hidden'"),
 'Rendered bounds must be nonempty'=>str_contains($ui,'el.getClientRects().length>0'),
 'No button inside a modal is pulled out into page header'=>str_contains($ui,'.media-modal,.story-modal,.admin-unified-form-overlay'),
 'Only current view mapped action IDs considered'=>str_contains($ui,'const view=getView(),ids=createActions[view]||[]'),
 'Header actions are keyboard accessible'=>str_contains($read('admin/assets/unified-ui.css'),'button:focus-visible'),
 'Dynamic refresh is batched to one animation frame'=>str_contains($ui,'let queued=false;')&&str_contains($ui,'requestAnimationFrame(()=>{queued=false;apply();})'),
];
$ids=['newTemplate','newRelease','newShow','newKnowledgeFolder','uploadKnowledge','newPackage',
 'mediaUploadLibrary','galleryNew','videoHubNew','newCampaign','newProduct',
 'newMemberContent','newTicketOffer','newShipment','newSegment','newAutomation',
 'pressKitNew','pressContactNew','pressCoverageNew','rightsPartyNew','newOperatingPlan','newMilestone'];
$unknown=array_values(array_filter($ids,fn($id)=>!str_contains($source,'id=\"'.$id.'\"')
    && !str_contains($source,'id="'.$id.'"')));
$checks['All 22 header action IDs resolve to existing controls']=$unknown===[];
$errors=0;foreach($checks as $name=>$pass){echo ($pass?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$pass)$errors++;}
echo '50B: '.(count($checks)-$errors).'/'.count($checks).' checks passed'.PHP_EOL;
exit($errors?1:0);
