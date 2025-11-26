<?php
$config = require_once 'auth.php';

set_time_limit(0);
ini_set('max_execution_time', 0);

header('Content-Type: application/json');

$phactorPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'phactor.php';

if (!file_exists($phactorPath)) 
{
    echo json_encode([
        'success' => false,
        'error' => 'phactor.php not found'
    ]);
    exit;
}

$progressFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phactor_progress.json';
file_put_contents($progressFile, json_encode([]));

$command = "php -f " . escapeshellarg($phactorPath) . " 2>&1";
$output = [];
$returnVar = 0;

exec($command, $output, $returnVar); //this works, but hanhs


echo json_encode([
    'success' => ($returnVar === 0),
    'output' => $output,
    'return_code' => $returnVar
]);