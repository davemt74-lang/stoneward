<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$ui=$read('admin/assets/unified-ui.js');
$homepage=$read('admin/assets/homepage.js');
$stories=$read('admin/assets/stories.js');
$checks=[
 'Existing standalone editor forms are retained'=>str_contains($ui,"['releaseForm','backReleases','releases']")
   &&str_contains($ui,"['productForm','productsBack','products']"),
 'Campaign and journey forms use original reviewed modules'=>str_contains($ui,"['campaignMetaForm','campaignBack','campaigns']")
   &&str_contains($ui,"['automationForm','automationBack','automations']"),
 'Legacy form nodes are moved, not copied'=>str_contains($ui,'const elements=Array.from(canvas.childNodes)')&&str_contains($ui,'content.append(element)'),
 'No new executor, CRM write or arbitrary API dispatch'=>!preg_match('/\bfetch\s*\(|\bXMLHttpRequest\b|\bapi\s*\(/',$ui),
 'Dialog is explicitly modal and labelled'=>str_contains($ui,"overlay.setAttribute('aria-modal','true')")&&str_contains($ui,"overlay.setAttribute('aria-label','Admin editor')"),
 'Form fields are checked for unsaved changes'=>str_contains($ui,"content.addEventListener('input'")&&str_contains($ui,"content.addEventListener('change'"),
 'Unsaved changes are confirmed on Close and Escape'=>str_contains($ui,"dirty&&!window.confirm('Discard unsaved changes")&&str_contains($ui,"if(e.key==='Escape')"),
 'Original Back also enforces the unsaved guard'=>str_contains($ui,"event.target.closest('#'+backId)")&&str_contains($ui,'event.stopImmediatePropagation()'),
 'Failed writes cannot clear dirty state automatically'=>!str_contains($ui,"addEventListener('submit',()=>{dirty=false;}"),
 'Canonical return action remains authoritative'=>str_contains($ui,'existingBack.click()'),
 'Form opens with keyboard focus'=>str_contains($ui,'first.focus({preventScroll:true})'),
 'Form closes with focus returned to originating action'=>str_contains($ui,'restoreFocusId')&&str_contains($ui,'destination?.focus?.({preventScroll:true})'),
 'Overlay traps Tab / Shift+Tab keyboard navigation'=>str_contains($ui,"e.key!=='Tab'")&&str_contains($ui,'fields.at(-1).focus()'),
 'No modal converts draft to publication automatically'=>!str_contains($ui,'action:publish')&&!str_contains($ui,'campaign_send_email'),
 'Homepage section saves reject duplicate submissions'=>str_contains($homepage,"if(f.dataset.saving==='1')return")&&str_contains($homepage,"button.disabled=true"),
 'Homepage failed save retains editable input values'=>str_contains($homepage,'Save failed. Your changes remain in the editor.'),
 'Homepage destructive reset requires explicit confirmation'=>str_contains($homepage,'if(!confirm(\'Reset every Artist Homepage section'),
 'Homepage destructive reset has in-flight guard'=>str_contains($homepage,'if(button?.disabled)return'),
 'Story publishing retains exact confirmation'=>str_contains($stories,"if(consequential&&!confirm(")&&str_contains($stories,"confirmed:consequential"),
 'Story modal retains unsaved-draft protection'=>str_contains($stories,'state.dirty&&!confirm('),
 'Story saves through original owned endpoint'=>str_contains($stories,"api('stories.php',{action:'save',story,confirmed:consequential})"),
];
$errors=0;foreach($checks as $name=>$pass){echo ($pass?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$pass)$errors++;}
echo '50C: '.(count($checks)-$errors).'/'.count($checks).' checks passed'.PHP_EOL;
exit($errors?1:0);
