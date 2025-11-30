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

try 
{
    $pdo = getDatabase($config);
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM {$config['tables']['projects']}");
    $projectCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM {$config['tables']['commits']}");
    $commitCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    $stmt = $pdo->query("
        SELECT SUM(code_lines) as total 
        FROM {$config['tables']['statistics']} s
        INNER JOIN (
            SELECT project_id, MAX(id) as max_id
            FROM {$config['tables']['commits']}
            GROUP BY project_id
        ) latest ON s.commit_id = latest.max_id
    ");
    $totalLines = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    if (!$totalLines) $totalLines = 0;
    $stmt = $pdo->query("
        SELECT p.name, c.commit_hash, c.commit_timestamp 
        FROM {$config['tables']['commits']} c
        JOIN {$config['tables']['projects']} p ON c.project_id = p.id
        ORDER BY c.commit_timestamp DESC
        LIMIT 10
    ");
    $recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
catch (PDOException $e) 
{
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Admin Dashboard - CodePhacts</title>
    <link rel="stylesheet" href="../public/style.css">
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
            <h2>Dashboard Overview</h2>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin: 20px 0;">
                <div style="text-align: center; padding: 20px; background: #e7f3ff; border-radius: 8px;">
                    <h3 style="font-size: 2.5em; margin: 0; color: #007bff;"><?= $projectCount ?></h3>
                    <p>Projects</p>
                </div>
                <div style="text-align: center; padding: 20px; background: #e7f3ff; border-radius: 8px;">
                    <h3 style="font-size: 2.5em; margin: 0; color: #007bff;"><?= $commitCount ?></h3>
                    <p>Commits Tracked</p>
                </div>
                <div style="text-align: center; padding: 20px; background: #e7f3ff; border-radius: 8px;">
                    <h3 style="font-size: 2.5em; margin: 0; color: #007bff;"><?= number_format($totalLines ?? 0) ?></h3>
                    <p>Total Lines of Code</p>
                </div>
            </div>
        </div>
        
        <div class="card">
            <h2>Recent Activity</h2>
            <table>
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Commit</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentActivity as $activity): ?>
                        <tr>
                            <td><?= htmlspecialchars($activity['name']) ?></td>
                            <td><?= htmlspecialchars($activity['commit_hash']) ?></td>
                            <td><?= htmlspecialchars($activity['commit_timestamp']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="card">
            <h2>Quick Actions</h2>
            <a href="projects.php?action=add" class="button">Add New Project</a>
            <button onclick="if(confirm('Update all projects?')) window.location.href='run-update.php'" class="button secondary">Run Update</button>
        </div>
    </div>
</body>
</html>