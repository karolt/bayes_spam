<?php 

define('ROOT_DIR', '..');
require_once ROOT_DIR . "/xplod/XAutoloader.class.php";

$db = new XDB('root', '', 'localhost', 'bayes_spam');

$filter = new BayesianSpamFilter($db);

learnFromFiles($filter, ROOT_DIR . '/corpus/spam', $isSpam = true);
learnFromFiles($filter, ROOT_DIR . '/corpus/ham', $isSpam = false);


function learnFromFiles(BayesianSpamFilter $filter, $directoryPath, $isSpam)
{
	$start = time();
	echo "\nlearning ".($isSpam ? "spam" : "ham")." from directory ".$directoryPath;
	$dir = opendir($directoryPath);
	while (false !== ($filename = readdir($dir))) 
	{
		if ($filename == '..' || $filename == '.') {
			continue;
		}
		
		echo "\n\treading file ".$filename;
		$filePath = $directoryPath .'/'. $filename;
		$file = fopen($filePath, "r");
		
		$text = fread($file, filesize($filePath));
		$filter->learnNewData($text, $isSpam);
	}
	echo "\n\ndone in ".(time()-$start)." seconds";
}
?>