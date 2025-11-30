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

try
{
    $pdo = getDatabase($config);
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) 
    {
        $stmt = $pdo->prepare("DELETE FROM {$config['tables']['projects']} WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $message = "Project deleted successfully";
    }
    $stmt = $pdo->query("SELECT * FROM {$config['tables']['projects']} ORDER BY name ASC");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
    <title>Manage Projects - CodePhacts</title>
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
            <h2>Projects</h2>
            <div style="margin-bottom: 20px;">
                <a href="edit-project.php" class="button">Add New Project</a>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>URL</th>
                        <th>Last Commit</th>
                        <th>Excluded Dirs</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($projects)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: #6c757d;">
                                No projects found. <a href="edit-project.php">Add your first project</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($projects as $project): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($project['name']) ?></strong></td>
                                <td><?= htmlspecialchars($project['source_type']) ?></td>
                                <td style="font-size: 0.85em; color: #6c757d;"><?= htmlspecialchars($project['source_url']) ?></td>
                                <td><?= htmlspecialchars($project['last_commit'] ? $project['last_commit'] : 'None') ?></td>
                                <td style="font-size: 0.85em;"><?= htmlspecialchars($project['excluded_dirs'] ? $project['excluded_dirs'] : 'None') ?></td>
                                <td class="actions">
                                    <a href="edit-project.php?id=<?= $project['id'] ?>" class="button">Edit</a>
                                    <button onclick="if(confirm('Delete this project and all its data?')) window.location.href='projects.php?action=delete&id=<?= $project['id'] ?>'" class="button danger">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>