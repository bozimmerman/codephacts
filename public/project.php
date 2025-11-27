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

$projectId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

try
{
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}",
        $config['db']['user'],
        $config['db']['pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->prepare("SELECT * FROM {$config['tables']['projects']} WHERE id = ?");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project)
        die("Project not found");
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
    $totals = [
        'total_lines' => 0,
        'code_lines' => 0,
        'comment_lines' => 0,
        'blank_lines' => 0
    ];
    foreach ($languages as $lang) {
        $totals['total_lines'] += $lang['total_lines'];
        $totals['code_lines'] += $lang['code_lines'];
        $totals['comment_lines'] += $lang['comment_lines'];
        $totals['blank_lines'] += $lang['blank_lines'];
    }
    $stmt = $pdo->prepare("
    SELECT
        c.commit_hash,
        c.commit_timestamp,
        SUM(s.code_lines) as total_code_lines
    FROM {$config['tables']['commits']} c
    INNER JOIN {$config['tables']['statistics']} s ON c.id = s.commit_id
    WHERE c.project_id = ?
    GROUP BY c.id, c.commit_hash, c.commit_timestamp
    ORDER BY c.commit_timestamp ASC
    ");
    $stmt->execute([$projectId]);
    $allCommits = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
}
catch (PDOException $e)
{
    die("Database error: " . $e->getMessage());
}

function determineOptimalGrouping($commits, $targetDataPoints = 100)
{
    if (empty($commits) || count($commits) <= 1)
        return ['interval' => 'day', 'intervalDays' => 1];
    $firstCommit = strtotime($commits[0]['commit_timestamp']);
    $lastCommit = strtotime($commits[count($commits) - 1]['commit_timestamp']);
    $totalDays = ceil(($lastCommit - $firstCommit) / 86400);
    if ($totalDays <= $targetDataPoints)
        return ['interval' => 'day', 'intervalDays' => 1];
    $intervals = [
        ['interval' => 'day', 'intervalDays' => 1],
        ['interval' => '3 days', 'intervalDays' => 3],
        ['interval' => 'week', 'intervalDays' => 7],
        ['interval' => '2 weeks', 'intervalDays' => 14],
        ['interval' => 'month', 'intervalDays' => 30],
        ['interval' => 'quarter', 'intervalDays' => 90],
        ['interval' => 'year', 'intervalDays' => 365]
    ];
    $bestInterval = $intervals[0];
    $bestDifference = abs($targetDataPoints - ($totalDays / $intervals[0]['intervalDays']));
    foreach ($intervals as $interval) 
    {
        $resultingPoints = $totalDays / $interval['intervalDays'];
        $difference = abs($targetDataPoints - $resultingPoints);
        if ($resultingPoints >= 50 && $resultingPoints <= 150) 
        {
            if ($difference < $bestDifference) 
            {
                $bestInterval = $interval;
                $bestDifference = $difference;
            }
        }
    }
    return $bestInterval;
}

function groupCommitsByInterval($commits, $intervalDays)
{
    if (empty($commits))
        return ['labels' => [], 'changes' => []];
    $groupedData = [];
    foreach ($commits as $commit)
    {
        if (!$commit['total_code_lines'] || $commit['total_code_lines'] === null)
            continue;
        $date = new DateTime($commit['commit_timestamp']);
        $timestamp = $date->getTimestamp();
        $bucketKey = floor($timestamp / ($intervalDays * 86400)) * ($intervalDays * 86400);
        $codeLines = (int)$commit['total_code_lines'];
        if (!isset($groupedData[$bucketKey])) 
        {
            $groupedData[$bucketKey] = [
                'commits' => [],
                'codeLines' => []
            ];
        }
        $groupedData[$bucketKey]['commits'][] = $commit;
        $groupedData[$bucketKey]['codeLines'][] = $codeLines;
    }
    ksort($groupedData);
    $labels = [];
    $changes = [];
    $previousAvg = null;
    
    foreach ($groupedData as $bucketKey => $data) 
    {
        $date = new DateTime();
        $date->setTimestamp($bucketKey);
        $labels[] = $date->format('Y-m-d');
        $currentAvg = array_sum($data['codeLines']) / count($data['codeLines']);
        if ($previousAvg === null)
            $changes[] = 0;
        else
            $changes[] = round($currentAvg - $previousAvg);
        $previousAvg = $currentAvg;
    }
    return ['labels' => $labels, 'changes' => $changes];
}
$grouping = determineOptimalGrouping($allCommits);
$groupedData = groupCommitsByInterval($allCommits, $grouping['intervalDays']);
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
                            <th>Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($languages as $lang): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($lang['language']) ?></strong></td>
                                <td><?= number_format($lang['total_lines'] ?? 0) ?></td>
                                <td><?= number_format($lang['code_lines'] ?? 0) ?></td>
                                <td><?= number_format($lang['comment_lines'] ?? 0) ?></td>
                                <td><?= number_format($lang['blank_lines'] ?? 0) ?></td>
                                <td><?= $totals['code_lines'] > 0 ? number_format(($lang['code_lines'] / $totals['code_lines']) * 100, 1) : '0.0' ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="font-weight: bold; border-top: 2px solid #dee2e6;">
                            <td>Total</td>
                            <td><?= number_format($totals['total_lines'] ?? 0) ?></td>
                            <td><?= number_format($totals['code_lines'] ?? 0) ?></td>
                            <td><?= number_format($totals['comment_lines'] ?? 0) ?></td>
                            <td><?= number_format($totals['blank_lines'] ?? 0) ?></td>
                            <td>100.0%</td>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <?php if (!empty($allCommits) && count($allCommits) > 1): ?>
        <div class="card">
            <h2>Lines of Code Over Time</h2>
            <div class="chart-container" style="height: 300px;">
                <canvas id="codeHistoryChart"></canvas>
            </div>
        </div>

        <div class="card">
            <h2>Commit Activity Over Time</h2>
            <div class="chart-container" style="height: 300px;">
                <canvas id="commitActivityChart"></canvas>
            </div>
        </div>
        <?php endif; ?>

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
                                <td><?= htmlspecialchars(substr($commit['commit_hash'], 0, 10)) ?></td>
                                <td><?= htmlspecialchars($commit['commit_timestamp']) ?></td>
                                <td><?= number_format($commit['total_code_lines'] ?? 0) ?></td>
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
    <?php if (!empty($allCommits) && count($allCommits) > 1): ?>
    const commits = <?= json_encode($allCommits) ?>;
    const commitData = {
        labels: <?= json_encode($groupedData['labels']) ?>,
        changes: <?= json_encode($groupedData['changes']) ?>,
        interval: '<?= $grouping['interval'] ?>'
    };
    const codeCtx = document.getElementById('codeHistoryChart');
    new Chart(codeCtx, {
        type: 'bar',
        data: {
            labels: commitData.labels.map(d => new Date(d).toLocaleDateString()),
            datasets: [{
                label: `Average Lines Added/Removed per ${commitData.interval}`,
                data: commitData.changes,
                backgroundColor: commitData.changes.map(v => v >= 0 ? 'rgba(40, 167, 69, 0.7)' : 'rgba(220, 53, 69, 0.7)'),
                borderColor: commitData.changes.map(v => v >= 0 ? '#28a745' : '#dc3545'),
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Average Lines Changed'
                    },
                    ticks: {
                        callback: function(value) {
                            return value >= 0 ? '+' + value : value;
                        }
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: `Time Period (${commitData.interval})`
                    }
                }
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const value = context.parsed.y;
                            return 'Avg change: ' + (value >= 0 ? '+' + value : value) + ' lines';
                        }
                    }
                }
            }
        }
    });

    const commitsByDate = {};
    commits.forEach(c => {
        const date = new Date(c.commit_timestamp).toLocaleDateString();
        commitsByDate[date] = (commitsByDate[date] || 0) + 1;
    });
    const activityLabels = Object.keys(commitsByDate);
    const activityCounts = Object.values(commitsByDate);
    const activityCtx = document.getElementById('commitActivityChart');
    new Chart(activityCtx, {
        type: 'bar',
        data: {
            labels: activityLabels,
            datasets: [{
                label: 'Number of Commits',
                data: activityCounts,
                backgroundColor: '#28a745',
                borderColor: '#28a745',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    },
                    title: {
                        display: true,
                        text: 'Number of Commits'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Date'
                    }
                }
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
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