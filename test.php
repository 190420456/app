<?php
include 'lib/autoload.php';

$filename = "E:/warship2.apk";
$parser = new \ApkParser\Parser($filename);
$manifest = $parser->getManifest();

//echo $manifest->getPackageName();
echo $parser->getResources($manifest->getApplication()->getLabel())[0] . $manifest->getVersionName();


$filename = "E:/twog_0.9.1.ipa";
$parser = new \IpaParser\Parser($filename);

//echo $ipa->getPackage();
echo $parser->getAppName() . $parser->getVersion();
?>