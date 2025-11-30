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
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'db.php';

$message = null;
$error = null;
$project = [
    'id' => null,
    'name' => '',
    'source_type' => 'git',
    'source_url' => '',
    'excluded_dirs' => '',
    'manager' => '',
    'auth_type' => 'none',
    'auth_username' => '',
    'auth_password' => '',
    'auth_ssh_key_path' => ''
];

try
{
    $pdo = getDatabase($config);$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if (isset($_GET['id']))
    {
        $stmt = $pdo->prepare("SELECT * FROM {$config['tables']['projects']} WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $loaded = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($loaded)
            $project = $loaded;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST')
    {
        $name = trim($_POST['name']);
        $source_type = $_POST['source_type'];
        $source_url = trim($_POST['source_url']);
        $excluded_dirs = trim($_POST['excluded_dirs']);
        $manager = trim($_POST['manager']);
        $auth_type = $_POST['auth_type'];
        $auth_username = trim($_POST['auth_username']);
        $auth_password = ($auth_type === 'basic') ? trim($_POST['auth_basic_password'] ?? '') : trim($_POST['auth_ssh_passphrase'] ?? '');
        $auth_ssh_key_path = trim($_POST['auth_ssh_key_path']);
        
        if (empty($name) || empty($source_url))
            $error = "Name and URL are required";
        else 
        {
            if ($project['id']) 
            {
                $stmt = $pdo->prepare("
                    UPDATE {$config['tables']['projects']} 
                    SET name = ?, source_type = ?, source_url = ?, excluded_dirs = ?, manager = ?,
                        auth_type = ?, auth_username = ?, auth_password = ?, auth_ssh_key_path = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $source_type, $source_url, $excluded_dirs, $manager,
                    $auth_type, $auth_username, $auth_password, $auth_ssh_key_path, $project['id']]);
                $message = "Project updated successfully";
            } 
            else 
            {
                $stmt = $pdo->prepare("
                    INSERT INTO {$config['tables']['projects']} (name, source_type, source_url, excluded_dirs, manager,
                                auth_type, auth_username, auth_password, auth_ssh_key_path) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $source_type, $source_url, $excluded_dirs, $manager,
                    $auth_type, $auth_username, $auth_password, $auth_ssh_key_path]);
                $message = "Project added successfully";
            }
            header('Location: projects.php');
            exit;
        }
    }
    
} 
catch (PDOException $e) 
{
    $error = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title><?= $project['id'] ? 'Edit' : 'Add' ?> Project - CodePhacts</title>
    <link rel="stylesheet" href="../public/style.css">
</head>
<body>
    <header>
        <div class="container">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <a href="index.php" style="text-decoration: none;">
                    <img src="../images/codephactsa.png" alt="CodePhacts Admin" style="height: 100px; display: block; margin: -30px 0;">
                </a>
                <nav>
                    <a href="index.php">Dashboard</a>
                    <a href="projects.php">Manage Projects</a>
                    <a href="../public/index.php">View Public Site</a>
                    <a href="logout.php">Logout</a>
                </nav>
            </div>
        </div>
    </header>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <div class="card">
            <h2><?= $project['id'] ? 'Edit' : 'Add New' ?> Project</h2>
            
            <form method="POST">
                <label>Project Name *</label>
                <input type="text" name="name" value="<?= htmlspecialchars($project['name']) ?>" required>
                
                <label>Source Type *</label>
                <select name="source_type" required>
                    <option value="git" <?= $project['source_type'] === 'git' ? 'selected' : '' ?>>Git</option>
                    <option value="svn" <?= $project['source_type'] === 'svn' ? 'selected' : '' ?>>SVN</option>
                </select>
                
                <label>Repository URL *</label>
                <input type="url" name="source_url" value="<?= htmlspecialchars($project['source_url']) ?>" required placeholder="https://github.com/user/repo.git">
                
                <label>Excluded Directories</label>
                <input type="text" name="excluded_dirs" value="<?= htmlspecialchars($project['excluded_dirs']) ?>" placeholder="/vendor,/extra/pages">
                <small style="color: #6c757d;">Comma-separated dir paths exclude (e.g., /vendor,/extra/pages)</small>

                <label>Manager</label>
                <input type="text" name="manager" value="<?= htmlspecialchars($project['manager'] ?? '') ?>" placeholder="John Doe">
                
               <label>Repository Authentication Type</label>
                <select name="auth_type" id="auth_type" onchange="toggleAuthFields()">
                    <option value="none" <?= $project['auth_type'] === 'none' ? 'selected' : '' ?>>None (Public Repository)</option>
                    <option value="basic" <?= $project['auth_type'] === 'basic' ? 'selected' : '' ?>>Username/Password (HTTPS)</option>
                    <option value="ssh" <?= $project['auth_type'] === 'ssh' ? 'selected' : '' ?>>SSH Key</option>
                </select>
                
                <div id="basic_auth_fields" style="display: none;">
                    <label>Username</label>
                    <input type="text" name="auth_username" id="auth_username" value="<?= htmlspecialchars($project['auth_username'] ?? '') ?>" placeholder="username">
                    
                    <label>Password / Personal Access Token</label>
                    <input type="password" name="auth_basic_password" id="auth_password" value="<?= htmlspecialchars($project['auth_password'] ?? '') ?>" placeholder="password or token">
                    <small style="color: #6c757d;">Note: Stored in plaintext. Use personal access tokens when possible.</small>
                </div>
                
                <div id="ssh_auth_fields" style="display: none;">
                    <label>SSH Private Key Path</label>
                    <input type="text" name="auth_ssh_key_path" id="auth_ssh_key_path" value="<?= htmlspecialchars($project['auth_ssh_key_path'] ?? '') ?>" placeholder="/home/user/.ssh/id_rsa">
                    <small style="color: #6c757d;">Full path to SSH private key file on server.</small>
                    
                    <label>SSH Key Passphrase (optional)</label>
                    <input type="password" name="auth_ssh_passphrase" id="ssh_passphrase" value="<?= htmlspecialchars($project['auth_password'] ?? '') ?>" placeholder="passphrase if key is encrypted">
                </div>

                <div style="margin-top: 20px;">
                    <button type="submit"><?= $project['id'] ? 'Update' : 'Add' ?> Project</button>
                    <a href="projects.php" class="button secondary">Cancel</a>
                </div>
            </form>
        </div>
        
        <?php if ($project['id']): ?>
            <div class="card">
                <h2>Project Statistics</h2>
                <?php
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as commit_count 
                    FROM {$config['tables']['commits']} 
                    WHERE project_id = ?
                ");
                $stmt->execute([$project['id']]);
                $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                ?>
                <p><strong>Commits Tracked:</strong> <?= $stats['commit_count'] ?></p>
                <p><strong>Last Updated:</strong> <?= htmlspecialchars($project['last_updated'] ? $project['last_updated'] : 'Never') ?></p>
                <p><strong>Last Commit:</strong> <?= htmlspecialchars($project['last_commit'] ? $project['last_commit'] : 'None') ?></p>
                
                <div style="margin-top: 20px; padding-top: 20px; border-top: 2px solid #dee2e6;">
                    <p style="color: #6c757d; font-size: 0.9em;">Reset all statistics and commits for this project. This will allow all data to be regenerated on the next update.</p>
                    <button onclick="resetProjectStats(<?= $project['id'] ?>)" class="button danger">
                        🔄 Reset Statistics & Commits
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <script>
        function toggleAuthFields() {
            var authType = document.getElementById('auth_type').value;
            document.getElementById('basic_auth_fields').style.display = (authType === 'basic') ? 'block' : 'none';
            document.getElementById('ssh_auth_fields').style.display = (authType === 'ssh') ? 'block' : 'none';
        }
        toggleAuthFields();
        
        function resetProjectStats(projectId) 
        {
            if (!confirm('⚠️ WARNING: This will delete ALL statistics and commit data for this project!\n\nThis action cannot be undone. The data will need to be regenerated by running the update process.\n\nAre you absolutely sure you want to continue?'))
                return;
            if (!confirm('This is your final warning!\n\nClick OK to permanently delete all statistics and commits for this project.'))
                return;
            
            const button = event.target;
            button.disabled = true;
            button.textContent = 'Resetting...';
            fetch('reset-project-stats.php', 
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    project_id: projectId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✓ Success!\n\n' + data.message + '\n\nThe page will now reload.');
                    window.location.reload();
                } else {
                    alert('✗ Error: ' + data.error);
                    button.disabled = false;
                    button.textContent = '🔄 Reset Statistics & Commits';
                }
            })
            .catch(error => {
                alert('✗ Error: ' + error);
                button.disabled = false;
                button.textContent = '🔄 Reset Statistics & Commits';
            });
        }
        </script>
    </body>
</html>