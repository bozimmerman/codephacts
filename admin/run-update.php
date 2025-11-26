<?php
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
            display: none;
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
        
        setTimeout(function() 
        {
            updateProgress(10, '1%');
            updateStatus('Starting update...');
            addOutput('=== CodePhacts Update Process ===');
            addOutput('Started at: ' + new Date().toLocaleString());
            addOutput('');
            addOutput('The dashboard will return when the progress completes.');
            fetch('run-update-exec.php')
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    addOutput('ERROR: ' + data.error, 'error');
                    spinner.style.display = 'none';
                    completedActions.style.display = 'block';
                }
                header('Location: projects.php');
                //addOutput('Update process started');
            });
                        
            let lastProgressCount = 0;
            /*
            let pollInterval = setInterval(function() {
                fetch('get-progress.php')
                    .then(response => response.json())
                    .then(result => {
                        if (result.success && result.progress) {
                            for (let i = lastProgressCount; i < result.progress.length; i++) {
                                eventSource.onmessage({data: JSON.stringify(result.progress[i])});
                            }
                            lastProgressCount = result.progress.length;
                        }
                    });
            }, 1000);
            */
            let currentProgress = 10;
            
            let eventSource = { onmessage: function(event) {
                try {
                    const data = JSON.parse(event.data);
                    
                    switch(data.type) {
                        case 'project_start':
                            addOutput('! Starting: ' + data.message);
                            updateStatus(data.message);
                            break;
                            
                        case 'project_skip':
                            addOutput('  X ' + data.message);
                            break;
                            
                        case 'commits_found':
                            addOutput('  ! ' + data.message, 'success');
                            break;
                            
                        case 'commit_start':
                            addOutput('    Processing: ' + data.message);
                            if (data.data && data.data.total > 0) {
                                currentProgress = 10 + Math.floor((data.data.current / data.data.total) * 80);
                                updateProgress(currentProgress, currentProgress + '%');
                            }
                            updateStatus(data.message);
                            break;
                            
                        case 'commit_complete':
                            addOutput('    ! ' + data.message, 'success');
                            break;
                            
                        case 'project_complete':
                            addOutput('! ' + data.message, 'success');
                            addOutput('');
                            break;
                            
                        case 'error':
                            addOutput('X ERROR: ' + data.message, 'error');
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
                                addOutput('X Process exited with code: ' + data.return_code, 'error');
                                updateStatus('Update failed!');
                            }
                            spinner.style.display = 'none';
                            completedActions.style.display = 'block';
                            eventSource.close();
                            break;
                    }
                } catch (e) {
                    // Not JSON, treat as raw output
                    addOutput(event.data);
                }
            }};
        }, 500);
   </script>
</body>
</html>