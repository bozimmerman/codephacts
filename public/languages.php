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
            s.language,
            SUM(s.code_lines) as total_code_lines,
            SUM(s.comment_lines) as total_comment_lines,
            SUM(s.blank_lines) as total_blank_lines,
            COUNT(DISTINCT s.project_id) as project_count
        FROM {$config['tables']['statistics']} s
        INNER JOIN (
            SELECT project_id, language, MAX(commit_id) as max_commit_id
            FROM {$config['tables']['statistics']}
            GROUP BY project_id, language
        ) latest ON s.project_id = latest.project_id 
                 AND s.language = latest.language 
                 AND s.commit_id = latest.max_commit_id
        GROUP BY s.language
        ORDER BY total_code_lines DESC
    ");
    $languages = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    <title>Languages - CodePhacts</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <header>
        <div class="container">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <a href="index.php" style="text-decoration: none;">
                    <img src="../images/codephacts.png" alt="CodePhacts" style="height: 100px; display: block; margin: -30px 0;">
                </a>
                <nav>
                    <a href="index.php">Projects</a>
                    <a href="query.php">Query Data</a>
                    <a href="languages.php">Languages</a>
                </nav>
            </div>
        </div>
    </header>
    
    <div class="container">
        <div class="card">
            <h2>Language Statistics</h2>
            <p>Code statistics across all projects, by programming language.</p>
            
            <?php if (empty($languages)): ?>
                <p>No language data available yet.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Language</th>
                            <th>Projects</th>
                            <th>Code Lines</th>
                            <th>Comment Lines</th>
                            <th>Blank Lines</th>
                            <th>Total Lines</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($languages as $lang): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($lang['language']) ?></strong></td>
                                <td><?= $lang['project_count'] ?></td>
                                <td><?= number_format($lang['total_code_lines'] ?? 0) ?></td>
                                <td><?= number_format($lang['total_comment_lines'] ?? 0) ?></td>
                                <td><?= number_format($lang['total_blank_lines'] ?? 0) ?></td>
                                <td><?= number_format($lang['total_code_lines'] + $lang['total_comment_lines'] + $lang['total_blank_lines']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="chart-container">
                    <canvas id="languageChart"></canvas>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
    <?php if (!empty($languages)): ?>
    const ctx = document.getElementById('languageChart');
    new Chart(ctx, 
    {
        type: 'pie',
        data: {
            labels: <?= json_encode(array_column($languages, 'language')) ?>,
            datasets: [{
                label: 'Lines of Code',
                data: <?= json_encode(array_column($languages, 'total_code_lines')) ?>,
                backgroundColor: [
                    '#007bff',
                    '#28a745',
                    '#dc3545',
                    '#ffc107',
                    '#17a2b8',
                    '#6c757d',
                    '#e83e8c',
                    '#20c997',
                    '#fd7e14',
                    '#6f42c1'
                ]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: 
            {
                legend: 
                {
                    position: 'right'
                }
            }
        }
    });
    <?php endif; ?>
    </script>
    <footer style="text-align: center; padding: 20px; margin-top: 40px; font-size: 0.8em; color: #999;">
        <a href="../admin/login.php" style="color: #999; text-decoration: none;">admin</a>
    </footer>
</body>
</html>