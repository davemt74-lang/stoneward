<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$get=static fn(string $file):string=>(string)file_get_contents($root.'/'.$file);
$settings=$get('admin/assets/settings-tabs.js');
$admin=$get('admin/assets/admin.js');
$stories=$get('admin/assets/stories.js');
$media=$get('admin/assets/media.js');
$markup=$get('admin/index.php');
$tabCss=$get('admin/assets/settings-tabs.css');
$storyCss=$get('admin/assets/stories.css');
$storyApi=$get('admin/api/stories.php');
$check=[
 'Settings exposes ten distinct tabs'=>substr_count($settings,"['")>=10 && substr_count($settings,"'Public Site'")===1 && substr_count($settings,"'Admin Session'")===1,
 'All ten original sections retained'=>str_contains($settings,"if(panels.length!==sections.length)") && str_contains($admin,'id="siteSettingsForm"')
    && str_contains($admin,'id="themePanel"') && str_contains($admin,'id="artistHomepagePanel"')
    && str_contains($admin,'id="storytellingPanel"') && str_contains($admin,'id="passwordForm"'),
 'Tab keys cover each existing settings section'=>array_reduce(['site','theme','homepage','stories','media','folders','uploads','password','database','session'],
    fn($ok,$s)=>$ok&&str_contains($settings,"['".$s."',"),true),
 'Accessible labelled tablist and tabpanel roles'=>str_contains($settings,"setAttribute('role','tablist')") && str_contains($settings,"setAttribute('role','tabpanel')"),
 'Tabs use valid aria-controls and labels'=>str_contains($settings,'settings-panel-') && str_contains($settings,'settings-tab-')
    && str_contains($settings,"setAttribute('aria-labelledby'"),
 'Selected tabs report active state'=>str_contains($settings,"setAttribute('aria-selected',String(active))"),
 'Inactive section is genuinely hidden'=>str_contains($settings,'panel.hidden=!active') && str_contains($tabCss,'.settings-tab-panel[hidden]'),
 'Keyboard arrows Home End support tab navigation'=>str_contains($settings,"'ArrowRight','ArrowLeft','Home','End'") && str_contains($settings,'button.focus()'),
 'Mobile tabs scroll without horizontal page overflow'=>str_contains($tabCss,'overflow-x:auto') && str_contains($tabCss,'max-width:100%'),
 'Switching tabs retains mounted forms and inputs'=>str_contains($settings,'const loaded=new Set(),loading=new Map()')
    && !str_contains($settings,'panel.remove()'),
 'Heavy modules load only when their tabs open'=>str_contains($settings,"if(key==='homepage')")
    && str_contains($settings,"if(key==='stories')") && str_contains($settings,"if(key==='theme')"),
 'Shared site-media uploader loaded on its tab'=>str_contains($settings,"if(key==='media')") && str_contains($settings,"bindEntityMedia?.('site','public'"),
 'Existing public site settings saving remains present'=>str_contains($admin,"$('#siteSettingsForm').onsubmit=saveSiteSettings"),
 'Existing upgrade, password and logout controls remain'=>str_contains($admin,'../upgrade.php') && str_contains($admin,"$('#passwordForm').onsubmit=changePassword")
    && str_contains($admin,"$('#logoutButton').onclick"),
 'Settings now mounts tabs from canonical Admin route'=>str_contains($admin,'window.SFSettingsTabs?.mount?.(canvas)'),
 'All tab JS and styles included in Admin shell'=>str_contains($markup,'assets/settings-tabs.js') && str_contains($markup,'assets/settings-tabs.css'),
 'New Story action lives at top of Settings'=>str_contains($settings,'settings-workspace-header') && str_contains($settings,'id="settingsNewStory"')
    && str_contains($settings,'+ New Story'),
 'New Story button selects Stories tab first'=>str_contains($settings,"await activate('stories')") && str_contains($settings,'await window.SFStoriesAdmin.openNew()'),
 'Story list never embeds an edit form'=>!str_contains($stories,"'<section id=\"storyEditorHost\"")
    && str_contains($stories,'story-admin-list-full'),
 'Existing story rows open their editor modal'=>str_contains($stories,'[data-story-open]') && str_contains($stories,'openEditor(found,d.references||{})'),
 'Story dialog has accessible label and modal semantics'=>str_contains($stories,'role="dialog" aria-modal="true" aria-labelledby="storyModalLabel"'),
 'Modal has close button'=>str_contains($stories,'data-story-close') && str_contains($stories,'aria-label="Close story editor"'),
 'Modal can close with Escape or backdrop click'=>str_contains($stories,"if(ev.key==='Escape')") && str_contains($stories,'if(ev.target===backdrop)closeEditor()'),
 'Unsaved edits require confirmation before closing'=>str_contains($stories,'state.dirty&&!confirm(') && str_contains($stories,"state.dirty=true"),
 'Modal traps focus with Tab and Shift+Tab'=>str_contains($stories,"if(ev.key!=='Tab')return") && str_contains($stories,'ev.shiftKey'),
 'Focus returns to original control'=>str_contains($stories,'state.returnFocus?.isConnected') && str_contains($stories,'state.returnFocus.focus()'),
 'Modal prevents page scroll behind dialog'=>str_contains($storyCss,'.story-modal-open{overflow:hidden}'),
 'Mobile story modal uses full viewport and no horizontal scrolling'=>str_contains($storyCss,'height:100dvh') && str_contains($storyCss,'overflow-x:hidden'),
 'Story creation uses governed existing Story API'=>str_contains($stories,"api('stories.php',{action:'save',story,confirmed:consequential})"),
 'Publishing and archiving require confirmation'=>str_contains($stories,"if(consequential&&!confirm(") && str_contains($storyApi,"Publishing or archiving a story requires explicit Admin confirmation."),
 'Media publishing remains governed by universal Media Library'=>str_contains($stories,"SFMediaAdmin?.bindEntityMedia?.('story_entry',") && str_contains($media,"action:'publish_entity_image'"),
 'Story modal stays open after first save for image uploads'=>str_contains($stories,"openEditor(response.story,state.data?.references||{})")
    && str_contains($stories,"await renderInto(state.mount)"),
 'Gallery and hero media roles retained'=>str_contains($stories,"roles:['hero','inline','gallery']"),
 'Story delete still requests owner confirmation'=>str_contains($stories,"action:'delete',id,confirmed:true"),
 'Existing Story Editor exposed with backward-compatible render method'=>str_contains($stories,'window.SFStoriesAdmin={renderInto,openNew,close:closeEditor}'),
 'Additive schema repair preserved and no password reset from UI'=>(preg_match("/'database_schema_target'=>'([0-9.]+)'/",$get('version.php'),$schemaMatch) && version_compare($schemaMatch[1],'1.3.48','>='))
    && !str_contains($settings,'change_password'),
 'Frontend/Admin and PWA versions remain synchronized'=>preg_match("/SF_ADMIN_BUILD','([0-9.]+)'/",$markup,$sync)
    &&version_compare($sync[1]??'0','1.3.50','>=')
    &&str_contains($get('service-worker.js'),'stonefellow-static-v'.($sync[1]??'')),
];
$failed=0;
foreach($check as $name=>$passed){echo ($passed?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$passed)$failed++;}
echo 'Tabbed Settings + Modal Stories: '.(count($check)-$failed).'/'.count($check).' passed'.PHP_EOL;
exit($failed?1:0);
