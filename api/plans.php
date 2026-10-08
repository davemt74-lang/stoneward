<?php
declare(strict_types=1);require __DIR__.'/bootstrap.php';$rows=array_map('sf_package_public',sf_packages(true));sf_json_response(['ok'=>true,'plans'=>$rows]);
