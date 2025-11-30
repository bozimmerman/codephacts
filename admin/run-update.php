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

$phactorPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'phactor.php';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Running Update - CodePhacts</title>
    <link rel="stylesheet" href="../public/style.css">
    <style>
        .progress-bar {
            width: 100%;
            height: 30px;
            background: #f0f0f0;
            border-radius: 4px;
            overflow: hidden;
            margin: 20px 0;
        }
        
        .progress-bar-fill {
            height: 100%;
            background: #007bff;
            width: 0%;
            transition: width 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #007bff;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .output-box {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 15px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            max-height: 500px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .output-line {
            margin: 2px 0;
        }
        
        .output-error {
            color: #f48771;
        }
        
        .output-success {
            color: #89d185;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>CodePhacts Admin</h1>
            <nav>
                <a href="index.php">Dashboard</a>
                <a href="projects.php">Manage Projects</a>
                <a href="../public/index.php">View Public Site</a>
                <a href="logout.php">Logout</a>
            </nav>
        </div>
    </header>
    
    <div class="container">
        <div class="card">
            <h2>Updating Projects</h2>
            
            <div class="spinner" id="spinner"></div>
            
            <div class="progress-bar">
                <div class="progress-bar-fill" id="progressBar">Starting...</div>
            </div>
            
            <p id="status">Initializing update process...</p>
            
            <div class="output-box" id="outputBox"></div>
            
            <div style="margin-top: 20px; display: none;" id="completedActions">
                <a href="index.php" class="button">Back to Dashboard</a>
                <a href="projects.php" class="button secondary">Manage Projects</a>
            </div>
        </div>
    </div>
    
    <script>
        const outputBox = document.getElementById('outputBox');
        const progressBar = document.getElementById('progressBar');
        const status = document.getElementById('status');
        const spinner = document.getElementById('spinner');
        const completedActions = document.getElementById('completedActions');
        
        function addOutput(text, type = 'normal')
        {
            const line = document.createElement('div');
            line.className = 'output-line';
            if (type === 'error') line.className += ' output-error';
            if (type === 'success') line.className += ' output-success';
            line.textContent = text;
            outputBox.appendChild(line);
            outputBox.scrollTop = outputBox.scrollHeight;
        }
        
        function updateProgress(percent, text)
        {
            progressBar.style.width = percent + '%';
            progressBar.textContent = text;
        }
        
        function updateStatus(text)
        {
            status.textContent = text;
        }
        
        // Initialize
        updateProgress(0, '0%');
        addOutput('=== CodePhacts Update Process ===');
        addOutput('Started at: ' + new Date().toLocaleString());
        addOutput('');
        addOutput('Waiting for updates from phactor.php...');
        addOutput('');
        
        let currentProgress = 0;
        let totalCommits = 0;
        let processedCommits = 0;
        
        // Create EventSource for Server-Sent Events
        const eventSource = new EventSource('run-update-exec.php');
        
        eventSource.onmessage = function(event) {
            try {
                console.info(event.data);
                const data = JSON.parse(event.data);
                
                switch(data.type) {
                    case 'project_start':
                        addOutput('→ Starting: ' + data.message);
                        updateStatus(data.message);
                        break;
                        
                    case 'project_skip':
                        addOutput('  ✗ ' + data.message);
                        break;
                        
                    case 'commits_found':
                        addOutput('  ✓ ' + data.message, 'success');
                        break;
                        
                    case 'commit_start':
                        addOutput('    • Processing: ' + data.message);
                        if (data.data && data.data.total > 0) {
                            processedCommits = data.data.current;
                            totalCommits = data.data.total;
                            currentProgress = 0 + Math.floor((processedCommits / totalCommits) * 100);
                            updateProgress(currentProgress, currentProgress + '%');
                        }
                        updateStatus(data.message);
                        break;
                        
                    case 'commit_complete':
                        addOutput('    ✓ ' + data.message, 'success');
                        break;
                        
                    case 'project_complete':
                        addOutput('✓ ' + data.message, 'success');
                        addOutput('');
                        break;
                        
                    case 'error':
                        addOutput('✗ ERROR: ' + data.message, 'error');
                        break;
                        
                    case 'all_complete':
                        addOutput('');
                        addOutput('=== ' + data.message.toUpperCase() + ' ===', 'success');
                        updateProgress(100, 'Complete!');
                        updateStatus('All updates completed!');
                        spinner.style.display = 'none';
                        completedActions.style.display = 'block';
                        eventSource.close();
                        break;
                        
                    case 'complete':
                        if (data.return_code !== 0) {
                            addOutput('✗ Process exited with code: ' + data.return_code, 'error');
                            updateStatus('Update failed!');
                        } else {
                            addOutput('');
                            addOutput('=== UPDATE COMPLETE ===', 'success');
                            updateProgress(100, 'Complete!');
                            updateStatus('All updates completed!');
                        }
                        spinner.style.display = 'none';
                        completedActions.style.display = 'block';
                        eventSource.close();
                        break;
                        
                    case 'loop_start':
                        addOutput('--- ' + data.message + ' ---');
                        break;
                        
                    default:
                        // Unknown message type, just log it
                        console.log('Unknown message:', data);
                }
            } catch (e) {
                // If it's not JSON, it might be raw output - just display it
                if (event.data && event.data.trim() !== '') {
                    addOutput(event.data);
                }
            }
        };
        
        eventSource.onerror = function(error) {
            console.error('EventSource error:', error);
            addOutput('');
            addOutput('✗ Connection error or process ended', 'error');
            spinner.style.display = 'none';
            completedActions.style.display = 'block';
            eventSource.close();
        };
        
        // Handle page unload
        window.addEventListener('beforeunload', function() {
            eventSource.close();
        });
    </script>
</body>
</html>