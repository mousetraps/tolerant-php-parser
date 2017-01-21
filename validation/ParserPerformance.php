<?php
/*---------------------------------------------------------------------------------------------
 *  Copyright (c) Microsoft Corporation. All rights reserved.
 *  Licensed under the MIT License. See License.txt in the project root for license information.
 *--------------------------------------------------------------------------------------------*/

require_once(__DIR__ . "/../src/bootstrap.php");

$totalSize = 0;
$directoryIterator = new RecursiveDirectoryIterator(__DIR__ . "/frameworks/WordPress");
$testProviderArray = array();

foreach (new RecursiveIteratorIterator($directoryIterator) as $file) {
    if (strpos($file, ".php")) {
        $totalSize += $file->getSize();
        $testProviderArray[] = file_get_contents($file->getPathname());
    }
}

$asts = [];
$parser = new \PhpParser\Parser();

$startMemory = memory_get_peak_usage(true);
$startTime = microtime(true);

foreach ($testProviderArray as $idx=>$testCaseFile) {
    $sourceFile = $parser->parseSourceFile($testCaseFile);
    $asts[] = $sourceFile;
}

$asts = SplFixedArray::fromArray($asts);

foreach ($asts as $ast) {
    foreach ($ast->getDescendantNodesAndTokens() as &$node) {
        $a = $node;
    }
}

$endTime = microtime(true);
$endMemory = memory_get_peak_usage(true);

// TODO - multiple runs, calculate statistical significance
$memoryUsage = $endMemory - $startMemory;
$timeUsage = $endTime - $startTime;
$totalSize /= 1024*1024;
$memoryUsage /= 1024*1024;

echo "MACHINE INFO\n";
echo "============\n";
echo "PHP int size: " . PHP_INT_SIZE . PHP_EOL;
echo "PHP version: " . phpversion() . PHP_EOL;
echo "OS: " . php_uname() . PHP_EOL . PHP_EOL;

echo "PERF STATS\n";
echo "==========\n";
echo "Input Source Files (#): $idx\n";
echo "Input Source Size (MB): $totalSize\n";
echo PHP_EOL;
echo "Time Usage (seconds): $timeUsage\n";
echo "Memory Usage (MB): $memoryUsage\n";