<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$css=(string)file_get_contents($root.'/admin/assets/agent-actions.css');
$ui=(string)file_get_contents($root.'/admin/assets/agent-actions.js');
$admin=(string)file_get_contents($root.'/admin/assets/admin.js');
$page=(string)file_get_contents($root.'/admin/index.php');
$checks=[
 'Workbenches use responsive CSS'=>str_contains($page,'assets/agent-actions.css'),
 'CSS mobile viewport handles narrow screens'=>str_contains($css,'@media(max-width:640px)'),
 'Dialogs fit available dynamic viewport'=>str_contains($css,'100dvh'),
 'Keyboard focus visibly outlined'=>str_contains($css,':focus-visible'),
 'Minimum touch target size'=>str_contains($css,'min-height:44px'),
 'Respects reduced motion'=>str_contains($css,'prefers-reduced-motion:reduce'),
 'Campaign dialog is real modal'=>str_contains($ui,'modal.showModal'),
 'History dialog is real modal'=>str_contains($ui,'history.showModal'),
 'Approval input has explicit label'=>str_contains($ui,'Approval phrase <input'),
 'Opening campaign focuses name'=>str_contains($ui,'form.elements.name.focus()'),
 'Opening history focuses close control'=>str_contains($ui,"history.querySelector('#closeAgentHistory').focus()"),
 'Admin Chat opens mission review'=>str_contains($admin,"q.includes('agent proposal')")&&str_contains($admin,"SFAgentCampaigns?.showHistory")
];
$failed=0;foreach($checks as $name=>$pass){echo ($pass?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$pass)$failed++;}
exit($failed?1:0);
