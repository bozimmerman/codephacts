<?php
/*
 Copyright 2025-2025 Bo Zimmerman
 
 Licensed under the Apache License, Version 2.0 (the "License");
 you may not use this file except in compliance with the License.
 You may obtain a copy of the License at
 
 http://www.apache.org/licenses/LICENSE-2.0
 
 Unless required by applicable law or agreed to in writing, software
 distributed under the License is distributed on an "AS IS" BASIS,
 WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 See the License for the specific language governing permissions and
 limitations under the License.
 */
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

/*
$progressFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phactor_progress.json';
file_put_contents($progressFile, json_encode([]));
*/

$command = "php -f " . escapeshellarg($phactorPath) . " 2>&1";
$output = [];
$returnVar = 0;

exec($command, $output, $returnVar); //this works, but hanhs


echo json_encode([
    'success' => ($returnVar === 0),
    'output' => $output,
    'return_code' => $returnVar
]);