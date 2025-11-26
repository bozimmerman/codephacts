<?php
$config = require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config.php';

$projectId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}",
        $config['db']['user'],
        $config['db']['pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get project info
    $stmt = $pdo->prepare("SELECT * FROM {$config['tables']['projects']} WHERE id = ?");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        die("Project not found");
    }
    
    // Get latest statistics by language
    $stmt = $pdo->prepare("
        SELECT 
            s.language,
            s.total_lines,
            s.code_lines,
            s.comment_lines,
            s.blank_lines
        FROM {$config['tables']['statistics']} s
        INNER JOIN (
            SELECT MAX(commit_id) as max_commit_id
            FROM {$config['tables']['statistics']}
            WHERE project_id = ?
        ) latest ON s.commit_id = latest.max_commit_id
        WHERE s.project_id = ?
        ORDER BY s.code_lines DESC
    ");
    $stmt->execute([$projectId, $projectId]);
    $languages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get commit history
    $stmt = $pdo->prepare("
        SELECT 
            c.commit_hash,
            c.commit_timestamp,
            SUM(s.code_lines) as total_code_lines
        FROM {$config['tables']['commits']} c
        LEFT JOIN {$config['tables']['statistics']} s ON c.id = s.commit_id
        WHERE c.project_id = ?
        GROUP BY c.id, c.commit_hash, c.commit_timestamp
        ORDER BY c.commit_timestamp DESC
        LIMIT 20
    ");
    $stmt->execute([$projectId]);
    $commits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($project['name']) ?> - CodePhacts</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <header>
        <div class="container">
            <h1>CodePhacts</h1>
            <nav>
                <a href="index.php">Projects</a>
                <a href="query.php">Query Data</a>
                <a href="languages.php">Languages</a>
                <a href="../admin/login.php">Admin</a>
            </nav>
        </div>
    </header>
    
    <div class="container">
        <div class="card">
            <h2><?= htmlspecialchars($project['name']) ?></h2>
            <p><strong>Repository Type:</strong> <?= htmlspecialchars($project['source_type']) ?></p>
            <p><strong>Last Commit:</strong> <?= htmlspecialchars($project['last_commit'] ? $project['last_commit'] : 'None') ?></p>
            <p><strong>Last Updated:</strong> <?= htmlspecialchars($project['last_updated'] ? $project['last_updated'] : 'Never') ?></p>
        </div>
        
        <div class="card">
            <h2>Language Breakdown</h2>
            <?php if (empty($languages)): ?>
                <p>No statistics available yet.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Language</th>
                            <th>Total Lines</th>
                            <th>Code Lines</th>
                            <th>Comment Lines</th>
                            <th>Blank Lines</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($languages as $lang): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($lang['language']) ?></strong></td>
                                <td><?= number_format($lang['total_lines']) ?></td>
                                <td><?= number_format($lang['code_lines']) ?></td>
                                <td><?= number_format($lang['comment_lines']) ?></td>
                                <td><?= number_format($lang['blank_lines']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="chart-container">
                    <canvas id="languageChart"></canvas>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h2>Recent Commits</h2>
            <?php if (empty($commits)): ?>
                <p>No commits tracked yet.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Commit</th>
                            <th>Date</th>
                            <th>Total Code Lines</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($commits as $commit): ?>
                            <tr>
                                <td><?= htmlspecialchars($commit['commit_hash']) ?></td>
                                <td><?= htmlspecialchars($commit['commit_timestamp']) ?></td>
                                <td><?= number_format($commit['total_code_lines']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <div style="margin-top: 20px;">
            <a href="index.php" class="button secondary">Back to Projects</a>
        </div>
    </div>
    
    <script>
    <?php if (!empty($languages)): ?>
    const ctx = document.getElementById('languageChart');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($languages, 'language')) ?>,
            datasets: [{
                label: 'Lines of Code',
                data: <?= json_encode(array_column($languages, 'code_lines')) ?>,
                backgroundColor: '#007bff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
    <?php endif; ?>
    </script>
</body>
</html>