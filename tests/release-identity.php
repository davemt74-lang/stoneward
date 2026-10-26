<?php
declare(strict_types=1);
// Compare every release asset to the canonical version instead of freezing old
// section tests to the version current when they were first written.
$sfTestVersionSource=(string)file_get_contents(dirname(__DIR__).'/version.php');
if(!preg_match("/'stonefellow'=>'([0-9]+\\.[0-9]+\\.[0-9]+)'/",$sfTestVersionSource,$sfTestVersionMatch)){
    throw new RuntimeException('The canonical Stonefellow release version is missing.');
}
if(version_compare($sfTestVersionMatch[1],'1.3.141','<')){
    throw new RuntimeException('Release version predates Section 88D3 baseline.');
}
define('SF_TEST_RELEASE_VERSION',$sfTestVersionMatch[1]);
