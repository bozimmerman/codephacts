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
$config = require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config.php';

try 
{
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}",
        $config['db']['user'],
        $config['db']['pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->query("
        SELECT 
            p.id,
            p.name,
            p.source_type,
            COUNT(DISTINCT c.id) as commit_count,
            MAX(c.commit_timestamp) as last_commit_date
        FROM {$config['tables']['projects']} p
        LEFT JOIN {$config['tables']['commits']} c ON p.id = c.project_id
        GROUP BY p.id, p.name, p.source_type
        ORDER BY p.name ASC
    ");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->query("
        SELECT 
            SUM(s.code_lines) as total_code_lines,
            SUM(s.comment_lines) as total_comment_lines,
            COUNT(DISTINCT s.language) as language_count
        FROM {$config['tables']['statistics']} s
        INNER JOIN (
            SELECT project_id, MAX(commit_id) as max_commit_id
            FROM {$config['tables']['statistics']}
            GROUP BY project_id
        ) latest ON s.project_id = latest.project_id AND s.commit_id = latest.max_commit_id
    ");
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);
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
    <title>CodePhacts - Code Statistics Tracker</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header>
        <div class="container">
            <h1>CodePhacts</h1>
            <nav>
                <a href="index.php">Projects</a>
                <a href="query.php">Query Data</a>
                <a href="languages.php">Languages</a>
            </nav>
        </div>
    </header>
    
    <div class="container">
        <div class="card">
            <h2>Overview</h2>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin: 20px 0;">
                <div style="text-align: center; padding: 20px; background: #e7f3ff; border-radius: 8px;">
                    <h3 style="font-size: 2.5em; margin: 0; color: #007bff;"><?= count($projects) ?></h3>
                    <p>Projects</p>
                </div>
                <div style="text-align: center; padding: 20px; background: #e7f3ff; border-radius: 8px;">
                    <h3 style="font-size: 2.5em; margin: 0; color: #007bff;"><?= number_format($totals['total_code_lines']) ?></h3>
                    <p>Lines of Code</p>
                </div>
                <div style="text-align: center; padding: 20px; background: #e7f3ff; border-radius: 8px;">
                    <h3 style="font-size: 2.5em; margin: 0; color: #007bff;"><?= $totals['language_count'] ?></h3>
                    <p>Languages</p>
                </div>
            </div>
        </div>
        
        <div class="card">
            <h2>Projects</h2>
            <?php if (empty($projects)): ?>
                <p style="text-align: center; padding: 40px; color: #6c757d;">
                    No projects found. An administrator needs to add projects.
                </p>
            <?php else: ?>
                <div class="project-list">
                    <?php foreach ($projects as $project): ?>
                        <div class="project-card">
                            <h3><?= htmlspecialchars($project['name']) ?></h3>
                            <div class="meta">
                                <strong>Type:</strong> <?= htmlspecialchars($project['source_type']) ?><br>
                                <strong>Commits:</strong> <?= $project['commit_count'] ?><br>
                                <strong>Last Updated:</strong> <?= $project['last_commit_date'] ? htmlspecialchars($project['last_commit_date']) : 'Never' ?>
                            </div>
                            <div class="actions">
                                <a href="project.php?id=<?= $project['id'] ?>" class="button">View Details</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <footer style="text-align: center; padding: 20px; margin-top: 40px; font-size: 0.8em; color: #999;">
        <a href="../admin/login.php" style="color: #999; text-decoration: none;">admin</a>
    </footer>
</body>
</html>