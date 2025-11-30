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
//@apache_setenv('no-gzip', 1);
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
header('Cache-Control: no-cache, no-store, must-revalidate');

// Set up Server-Sent Events headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable nginx buffering if present

// Disable output buffering completely
while (ob_get_level()) {
    ob_end_clean();
}

$phactorPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'phactor.php';

if (!file_exists($phactorPath))
{
    echo "data: " . json_encode([
        'type' => 'error',
        'message' => 'phactor.php not found'
    ]) . "\n\n";
    flush();
    exit;
}

// Set up process with pipes
$descriptorspec = [
    0 => ['pipe', 'r'],  // stdin
    1 => ['pipe', 'w'],  // stdout
    2 => ['pipe', 'w']   // stderr
];

$command = 'php ' . escapeshellarg($phactorPath) . ' 2>&1';
$process = proc_open($command, $descriptorspec, $pipes);

if (!is_resource($process))
{
    echo "data: " . json_encode([
        'type' => 'error',
        'message' => 'Failed to start phactor.php'
    ]) . "\n\n";
    flush();
    exit;
}

// Close stdin - we don't need it
fclose($pipes[0]);

// Set stdout to non-blocking so we can read it progressively
stream_set_blocking($pipes[1], false);

// Read output line by line and stream it back
while (!feof($pipes[1]))
{
    $line = fgets($pipes[1]);
    
    if ($line !== false && trim($line) !== '')
    {
        // Send the line as an SSE message
        echo "data: " . trim($line) . "\n\n";
        flush();
    }
    
    // Small sleep to prevent busy-waiting
    usleep(100000); // 0.1 second
    
    // Check if process is still running
    $status = proc_get_status($process);
    if (!$status['running'])
    {
        break;
    }
}

// Read any remaining output
while (!feof($pipes[1]))
{
    $line = fgets($pipes[1]);
    if ($line !== false && trim($line) !== '')
    {
        echo "data: " . trim($line) . "\n\n";
        flush();
    }
}

// Read stderr if there's anything there
$errors = stream_get_contents($pipes[2]);

// Close pipes
fclose($pipes[1]);
fclose($pipes[2]);

// Get exit code
$returnCode = proc_close($process);

// Send completion message
echo "data: " . json_encode([
    'type' => 'complete',
    'return_code' => $returnCode,
    'success' => ($returnCode === 0)
]) . "\n\n";

if ($errors)
{
    echo "data: " . json_encode([
        'type' => 'error',
        'message' => $errors
    ]) . "\n\n";
}

flush();