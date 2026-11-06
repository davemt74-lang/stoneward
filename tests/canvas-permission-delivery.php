<?php
declare(strict_types=1);
require __DIR__.'/canvas-projects.php';
$admin=['id'=>9,'role'=>'admin'];
$before=sf_agent_canvas_data_tools();
check(in_array('my_purchases',array_column($before,'id'),true),'Current canvas data discovery includes legacy enabled tool');
sf_canvas_permission_save($admin,1,['canvas.data.my_purchases'=>false,'canvas.data.published_catalog'=>false]);
check(!in_array('my_purchases',array_column(sf_agent_canvas_data_tools(),'id'),true),'Disabled private data disappears from model discovery');
$html='<section class="sf-dataset-my_purchases"><div class="sf-data-row"></div></section><section class="sf-dataset-published_catalog"></section>';
check(sf_agent_canvas_data_for_scene(['id'=>1],$html)===[],'Direct authored dataset markers cannot bypass disabled private or public permissions');
check(sf_agent_canvas_data_for_scene([],$html)===[],'Guest delivery cannot bypass disabled policy');
sf_canvas_permission_save($admin,2,['canvas.data.my_purchases'=>true]);
check(!isset(sf_agent_canvas_data_for_scene([],$html)['my_purchases']),'Re-enabling does not grant guests account access');
check(sf_agent_tool_allowed('published_catalog','guest'),'Canvas policy does not disable unrelated public catalog access');
