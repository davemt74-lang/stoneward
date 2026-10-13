<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $p):(string)=>file_get_contents($root.'/'.$p);
$index=$read('admin/index.php');
$main=$read('admin/assets/admin.js');
$ui=$read('admin/assets/unified-ui.js');
$css=$read('admin/assets/unified-ui.css');
$moduleFiles=['admin/assets/campaigns.js','admin/assets/products.js','admin/assets/membership.js',
  'admin/assets/ticketing.js','admin/assets/fulfillment.js','admin/assets/automations.js',
  'admin/assets/media.js','admin/assets/press.js','admin/assets/rights.js','admin/assets/operating-calendar.js',
  'admin/assets/gallery.js','admin/assets/video.js'];
$modules=implode("\n",array_map($read,$moduleFiles));
$checks=[
 'Shared UI loaded on all authenticated Admin pages'=>str_contains($index,'assets/unified-ui.js') && str_contains($index,'assets/unified-ui.css'),
 'Shared UI loads after the Admin runtime'=>strpos($index,'assets/admin.js')<strpos($index,'assets/unified-ui.js'),
 'Shared UI loads after all specialist modules'=>strpos($index,'assets/theme.js')<strpos($index,'assets/unified-ui.js'),
 'All 29 legacy navigation views remain'=>substr_count($index,'<button type="button" data-view=')===29,
 'Unified UI binds one existing canonical Admin context'=>str_contains($ui,'window.STONEFELLOW_ADMIN_CONTEXT')
     && str_contains($main,'window.STONEFELLOW_ADMIN_CONTEXT={app,api,uploadApi,canvas,head,say,esc,slug,money,openView,reloadCore}'),
 'No alternate network or save endpoints introduced'=>!preg_match('/\bfetch\s*\(|\bXMLHttpRequest\b|\bapi\s*\(/',$ui),
 'Buttons are moved rather than cloned'=>str_contains($ui,'actions.append(el)')&&!str_contains($ui,'cloneNode('),
 'Live-render DOM watching supports every Admin view'=>str_contains($ui,'new MutationObserver(()=>apply())')&&str_contains($ui,'subtree:true'),
 'Already-modern Settings/Chat/Brain views are excluded'=>str_contains($ui,"['settings','agent-chat','brain'].includes(getView())"),
 'Existing Story, Gallery, Video and Shipment modals not intercepted'=>!str_contains($ui,"['storyEditorForm'")
     && !str_contains($ui,"['videoEditorForm'") && !str_contains($ui,"['galleryEditorForm'")
     && !str_contains($ui,"['shipmentForm'"),
 'Existing page header actions are preserved'=>str_contains($ui,"header.querySelector(':scope > .actions')"),
 'Missing legacy page headers get a unified heading'=>str_contains($ui,'admin-unified-generated-header')&&str_contains($ui,"document.getElementById('viewTitle')"),
 'Header shows only the current Admin view new-action list'=>str_contains($ui,"const view=getView(),ids=createActions[view]||[]"),
 'Hidden or inactive module buttons are not promoted'=>str_contains($ui,"!el.closest('[hidden]')")&&str_contains($ui,"getComputedStyle(el).display!=='none'"),
 'Action promotion never relocates controls from existing modals'=>str_contains($ui,".media-modal,.story-modal,.admin-unified-form-overlay"),
 'All map-defined New/Add action IDs still exist in source'=>true,
 'Unified editor retains original DOM nodes and handlers'=>str_contains($ui,'const elements=Array.from(canvas.childNodes)')
     &&str_contains($ui,'for(const element of elements)content.append(element)'),
 '13 editor form mappings retain canonical return routes'=>substr_count($ui,"['")>=13,
 'Template editor is modalized'=>str_contains($ui,"['templateForm','backTemplates','templates']"),
 'Catalog track editor is modalized'=>str_contains($ui,"['trackForm','backCatalog','catalog']"),
 'Release and show editors are modalized'=>str_contains($ui,"['releaseForm','backReleases','releases']")
     &&str_contains($ui,"['showForm','backShows','shows']"),
 'Package and campaign editors are modalized'=>str_contains($ui,"['packageForm','backPackages','packages']")
     &&str_contains($ui,"['campaignMetaForm','campaignBack','campaigns']"),
 'Product and member content editors are modalized'=>str_contains($ui,"['productForm','productsBack','products']")
     &&str_contains($ui,"['membershipContentForm','membershipBack','membership']"),
 'Tickets, segments, automations modalized'=>str_contains($ui,"['ticketOfferForm','ticketBack','ticketing']")
     &&str_contains($ui,"['segmentForm','segmentBack','automations']")
     &&str_contains($ui,"['automationForm','automationBack','automations']"),
 'EPK and Operating Calendar editors modalized'=>str_contains($ui,"['pressKitForm','pressBack','press']")
     &&str_contains($ui,"['operatingPlanForm','planCancel','calendar']"),
 'Only matching view can enter editor mode'=>str_contains($ui,'if(view!==scope)return false'),
 'Dialog exposes name, role and accessibility semantics'=>str_contains($ui,"overlay.setAttribute('role','dialog')")
     &&str_contains($ui,"overlay.setAttribute('aria-modal','true')"),
 'Modal closes through original module Back button'=>str_contains($ui,'existingBack.click()'),
 'Unsaved input state tracked inside original forms'=>str_contains($ui,"content.addEventListener('input'")&&str_contains($ui,"content.addEventListener('change'"),
 'Close and Escape require unsaved-confirmation'=>str_contains($ui,"dirty&&!window.confirm('Discard unsaved changes")&&str_contains($ui,"if(e.key==='Escape')"),
 'Original Back button also requires unsaved confirmation'=>str_contains($ui,"event.target.closest('#'+backId)")
     && str_contains($ui,'event.stopImmediatePropagation()'),
 'Failed submit cannot clear dirty state'=>!str_contains($ui,"addEventListener('submit',()=>{dirty=false;}"),
 'Keyboard focus remains in open dialog'=>str_contains($ui,"e.key!=='Tab'")&&str_contains($ui,'fields.at(-1).focus()'),
 'Responsive overlay adapts to mobile viewport'=>str_contains($css,'height:100dvh')&&str_contains($css,'env(safe-area-inset-bottom)'),
 'Editor body scrolls vertically without horizontal scrollbar'=>str_contains($css,'overflow-y:auto;overflow-x:hidden'),
 'Header buttons wrap cleanly on mobile'=>str_contains($css,'grid-template-columns:repeat(auto-fit,minmax(130px,1fr))'),
 'Legacy card/panel padding remains responsive'=>str_contains($css,'.admin-canvas .panel{min-width:0;max-width:100%}'),
 'Tables remain scrollable without widening whole page'=>str_contains($css,'.admin-canvas .table-scroll{max-width:100%;overflow-x:auto'),
 'Admin API and form permission semantics untouched'=>!str_contains($ui,'executeAction')&&!str_contains($ui,'csrf'),
 'Story Settings keeps independent modal workflow'=>str_contains($index,'assets/settings-tabs.js')&&str_contains($index,'assets/stories.js'),
 'No password reset or new DB migration'=>str_contains($read('version.php'),"'database_schema_target'=>'1.3.46'")
     && !str_contains($ui,'password_reset'),
 'Frontend version synchronized to 1.3.49'=>str_contains($index,"SF_ADMIN_BUILD','1.3.49'")
    &&str_contains($read('service-worker.js'),'stonefellow-static-v1.3.49'),
];
// Action mappings should resolve to real control IDs, including those in the
// main Admin controller and within independently loaded feature modules.
$known=[
 'newTemplate','newRelease','newShow','newKnowledgeFolder','newPackage','mediaUploadLibrary','galleryNew',
 'videoHubNew','newCampaign','newProduct','newMemberContent','newTicketOffer','newShipment',
 'newSegment','newAutomation','pressKitNew','pressContactNew','pressCoverageNew','rightsPartyNew',
 'newOperatingPlan','newMilestone'
];
$unresolved=array_values(array_filter($known,fn($id)=>!str_contains($main.$modules,'id=\"'.$id.'\"')&&
    !str_contains($main.$modules,'id="'.$id.'"')));
$checks['All 21 header new-action IDs resolve in existing views']=$unresolved===[];
$checkEditors=[
 'templateForm','trackForm','releaseForm','showForm','packageForm','campaignMetaForm','productForm',
 'membershipContentForm','ticketOfferForm','segmentForm','automationForm','pressKitForm','operatingPlanForm'
];
$missing=array_values(array_filter($checkEditors,fn($id)=>!str_contains($main.$modules,$id)));
$checks['All 13 existing editor form IDs are present']=$missing===[];
$back=['backTemplates','backCatalog','backReleases','backShows','backPackages','campaignBack',
  'productsBack','membershipBack','ticketBack','segmentBack','automationBack','pressBack','planCancel'];
$missingBack=array_values(array_filter($back,fn($id)=>!str_contains($main.$modules,$id)));
$checks['All 13 existing canonical Back actions are present']=$missingBack===[];
$failed=0;
foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$failed++;}
echo 'Unified Admin UI: '.(count($checks)-$failed).'/'.count($checks).' checks passed'.PHP_EOL;
exit($failed?1:0);
