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

header('Content-Type: application/json');

$projectRoot = dirname(__DIR__);
$abortFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'codephacts_abort_' . md5(realpath($projectRoot)) . '.txt';

try {
    if (file_put_contents($abortFile, date('Y-m-d H:i:s')) === false) {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to write abort file'
        ]);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Cancellation requested'
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}