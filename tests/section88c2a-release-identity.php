<?php
declare(strict_types=1);
$root=dirname(__DIR__);$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
preg_match("/define\('SF_BUILD',\s*'([0-9.]+)'\)/",$read('stonefellow-v120.php'),$m);$version=$m[1]??'';
$ui=$read('assets/js/app.js');$site=$read('stonefellow-v120.php');$admin=$read('admin/index.php');
$checks=[
  'Version advanced to 1.3.110'=>version_compare($version,'1.3.110','>='),
  'Admin and version endpoint reflect same release'=>str_contains($admin,"define('SF_ADMIN_BUILD','".$version."')")
    &&str_contains($read('version.php'),"'stonefellow'=>'".$version."'"),
  'Apache and service-worker cache synchronized'=>str_contains($read('.htaccess'),'X-Stonefellow-Build "'.$version.'"')
    &&str_contains($read('service-worker.js'),"stonefellow-static-v".$version),
  'Public and builder static cache revised'=>str_contains($ui,$version)
    &&str_contains($read('assets/css/turntable-builder.css'),$version),
  'Team member portal and scoped access remain available'=>str_contains($site,'data-view="team"')
    &&str_contains($ui,"view==='team'")&&str_contains($read('api/team-workspace.php'),'sf_team_require'),
  'Website Manager and overlapping roles available'=>str_contains($read('api/team-workspace-core.php'),"'website_manager'")
    &&str_contains($read('api/team-workspace-core.php'),'sf_team_assign_roles('),
  'Admin editor is an existing module'=>str_contains($admin,'data-view="team"')
    &&str_contains($admin,'team-workspace.js?v='),
  'Unified timeline includes named uploads'=>str_contains($read('admin/api/upload.php'),"'song_source_uploaded'")
    &&str_contains($read('admin/api/catalog.php'),"'song_metadata_updated'"),
  'Team interface styling uses existing CSS entrypoints'=>str_contains($read('assets/css/site.css'),'.team-timeline-event')
    &&str_contains($read('admin/assets/admin.css'),'.team-admin-role-grid'),
  'Role and release acceptance suites remain in gate'=>str_contains($read('.github/workflows/release-gate.yml'),'php tests/section88c2-team-workspace.php')
    &&str_contains($read('.github/workflows/release-gate.yml'),'php tests/section88c2a-release-identity.php')
];
$failed=0;foreach($checks as $name=>$pass){echo ($pass?'PASS ':'FAIL ').$name.PHP_EOL;if(!$pass)$failed++;}
exit($failed?1:0);
