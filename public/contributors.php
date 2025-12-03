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
$config = require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'conphig.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '/db.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'estimation_functions.php';

$statsColumnsMap = require __DIR__ . DIRECTORY_SEPARATOR . 'stats_columns_map.php';
$selectedMetric = isset($_GET['metric']) ? $_GET['metric'] : 'total_lines';
if (!isset($statsColumnsMap[$selectedMetric]))
    $selectedMetric = 'total_lines';
$metricConfig = $statsColumnsMap[$selectedMetric];
$metricColumn = $metricConfig['column'];
if (!preg_match('/^[a-z_]+$/', $metricColumn))
    die('Invalid metric');
try
{
    $pdo = getDatabase($config);
    $selectedContributor = isset($_GET['contributor']) ? $_GET['contributor'] : 'ALL';
    
    $contributorStmt = $pdo->query("
        SELECT DISTINCT commit_user
        FROM {$config['tables']['commits']}
        WHERE commit_user IS NOT NULL AND commit_user != '' AND processing_state = 'done'
        ORDER BY commit_user
    ");
    $allContributors = $contributorStmt->fetchAll(PDO::FETCH_COLUMN);
    $contributors = [];
    $allProjects = [];
    foreach ($allContributors as $user)
    {
        if ($selectedContributor != 'ALL' && $selectedContributor != $user)
            continue;
        $contributors[$user] = [
            'contributor' => $user,
            'commits' => 0,
            'projects' => [],
            'total_lines_delta' => 0,
            'code_lines_delta' => 0,
            'metric_value_delta' => 0,
            'first_commit' => null,
            'last_commit' => null
        ];
        $stmt = $pdo->prepare("
            SELECT
                c.id,
                c.project_id,
                c.commit_timestamp,
                s.language,
                s.total_lines,
                s.code_lines,
                s.{$metricColumn} as metric_value
            FROM {$config['tables']['commits']} c
            INNER JOIN {$config['tables']['statistics']} s ON c.id = s.commit_id
            WHERE c.commit_user = ? AND c.processing_state = 'done'
            ORDER BY c.project_id, s.language, c.commit_timestamp ASC
        ");
        $stmt->execute([$user]);
        $previousState = [];
        
        while ($commit = $stmt->fetch(PDO::FETCH_ASSOC))
        {
            $projectId = $commit['project_id'];
            $language = $commit['language'];
            $key = $projectId . '_' . $language;
            
            $allProjects[$projectId] = true;
            $contributors[$user]['commits']++;
            $contributors[$user]['projects'][$projectId] = true;
            
            if ($contributors[$user]['first_commit'] === null || $commit['commit_timestamp'] < $contributors[$user]['first_commit'])
                $contributors[$user]['first_commit'] = $commit['commit_timestamp'];
            if ($contributors[$user]['last_commit'] === null || $commit['commit_timestamp'] > $contributors[$user]['last_commit'])
                $contributors[$user]['last_commit'] = $commit['commit_timestamp'];
                    
            if (isset($previousState[$key]))
            {
                $contributors[$user]['total_lines_delta'] += ($commit['total_lines'] - $previousState[$key]['total_lines']);
                $contributors[$user]['code_lines_delta'] += ($commit['code_lines'] - $previousState[$key]['code_lines']);
                $contributors[$user]['metric_value_delta'] += ($commit['metric_value'] - $previousState[$key]['metric_value']);
            }
            else
            {
                $contributors[$user]['total_lines_delta'] += $commit['total_lines'];
                $contributors[$user]['code_lines_delta'] += $commit['code_lines'];
                $contributors[$user]['metric_value_delta'] += $commit['metric_value'];
            }
            
            $previousState[$key] = [
                'total_lines' => $commit['total_lines'],
                'code_lines' => $commit['code_lines'],
                'metric_value' => $commit['metric_value']
            ];
        }
        $contributors[$user]['commit_count'] = $contributors[$user]['commits'];
        $contributors[$user]['project_count'] = count($contributors[$user]['projects']);
        unset($contributors[$user]['commits']);
        unset($contributors[$user]['projects']);
    }
    
    $contributors = array_values($contributors);
    
    if ($selectedMetric === 'total_lines')
    {
        usort($contributors, function($a, $b) {
            return $b['total_lines_delta'] - $a['total_lines_delta'];
        });
    }
    else
    {
        usort($contributors, function($a, $b) {
            return $b['metric_value_delta'] - $a['metric_value_delta'];
        });
    }
}
catch (PDOException $e)
{
    die("Database error: " . $e->getMessage());
}
$totals = [
    'total_lines_delta' => 0,
    'code_lines_delta' => 0,
    'metric_value_delta' => 0,
    'commit_count' => 0,
    'project_count' => 0
];

foreach ($contributors as $contrib)
{
    $totals['commit_count'] += $contrib['commit_count'];
    $totals['total_lines_delta'] += $contrib['total_lines_delta'];
    $totals['code_lines_delta'] += $contrib['code_lines_delta'];
    $totals['metric_value_delta'] += $contrib['metric_value_delta'];
}

$totals['project_count'] = count($allProjects);

$primaryLanguage = 'PHP';

if ($selectedContributor === 'ALL')
    $estimateBase = $selectedMetric === 'total_lines' ? $totals['code_lines_delta'] : $totals['metric_value_delta'];
else 
{
    $estimateBase = 0;
    foreach ($contributors as $contrib)
    {
        if ($contrib['contributor'] === $selectedContributor)
        {
            $estimateBase = $selectedMetric === 'total_lines' ? $contrib['code_lines_delta'] : $contrib['metric_value_delta'];
            break;
        }
    }
}

$estimates = $estimateBase > 0 ? generateEstimates($estimateBase, $primaryLanguage) : null;
$hourlyRate = 75;
$hoursPerMonth = 160;
$topContributors = array_slice($contributors, 0, 10); // Get top 10
$isComplexityMetric = in_array($selectedMetric, ['cyclomatic_complexity', 'cognitive_complexity']);

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

$contributorChartData = [];
foreach ($topContributors as $contributor)
{
    $contributorName = $contributor['contributor'];
    
    // Query across ALL projects for this contributor (not a single project)
    if ($isComplexityMetric) {
        $stmt = $pdo->prepare("
            SELECT
                c.project_id,
                c.commit_timestamp,
                CASE
                    WHEN SUM(s.code_lines) > 0 THEN
                        (SUM(s.{$metricColumn}) / (SUM(s.code_lines) / 1000.0))
                    ELSE 0
                END as metric_value
            FROM {$config['tables']['commits']} c
            LEFT JOIN {$config['tables']['statistics']} s ON c.id = s.commit_id
            WHERE c.commit_user = ?
              AND c.processing_state = 'done'
            GROUP BY c.id, c.project_id, c.commit_timestamp
            ORDER BY c.project_id, c.commit_timestamp ASC
        ");
        $stmt->execute([$contributorName]);
    } else {
        $stmt = $pdo->prepare("
            SELECT
                c.project_id,
                c.commit_timestamp,
                COALESCE(SUM(s.{$metricColumn}), 0) as metric_value
            FROM {$config['tables']['commits']} c
            LEFT JOIN {$config['tables']['statistics']} s ON c.id = s.commit_id
            WHERE c.commit_user = ?
              AND c.processing_state = 'done'
            GROUP BY c.id, c.project_id, c.commit_timestamp
            ORDER BY c.project_id, c.commit_timestamp ASC
        ");
        $stmt->execute([$contributorName]);
    }
    
    $commits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($commits))
        continue;
    
    $chartLabels = [];
    $commitCounts = [];
    $metricDeltas = [];
    
    $previousValue = 0;
    $previousProjectId = null;  // ADD THIS
    $commitCountByBucket = [];
    $grouping = determineOptimalGrouping($commits);
    $intervalDays = $grouping['intervalDays'];
    foreach ($commits as $commit)
    {
        $timestamp = strtotime($commit['commit_timestamp']);
        $bucketKey = floor($timestamp / ($intervalDays * 86400)) * ($intervalDays * 86400);
        if (!isset($commitCountByBucket[$bucketKey])) 
        {
            $commitCountByBucket[$bucketKey] = [
                'count' => 0,
                'deltas' => []
            ];
        }
        $currentValue = (float)$commit['metric_value'];
        if ($previousProjectId !== $commit['project_id']) {
            $previousValue = 0;
            $previousProjectId = $commit['project_id'];
        }
        $delta = $currentValue - $previousValue;
        
        $commitCountByBucket[$bucketKey]['count']++;
        $commitCountByBucket[$bucketKey]['deltas'][] = $delta;
        
        $previousValue = $currentValue;
    }
    ksort($commitCountByBucket);
    foreach ($commitCountByBucket as $bucketKey => $data) 
    {
        $date = new DateTime();
        $date->setTimestamp($bucketKey);
        $chartLabels[] = $date->format('Y-m-d');
        $commitCounts[] = $data['count'];
        $metricDeltas[] = count($data['deltas']) > 0 ? array_sum($data['deltas']) / count($data['deltas']) : 0;
    }
    $contributorChartData[$contributorName] = [
        'labels' => $chartLabels,
        'commits' => $commitCounts,
        'deltas' => $metricDeltas,
        'interval' => $grouping['interval']
    ];
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Contributors - CodePhacts</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .estimate-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .estimate-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
        }
        .estimate-card h3 {
            margin-top: 0;
            color: #495057;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }
        .metric {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #dee2e6;
        }
        .metric:last-child {
            border-bottom: none;
        }
        .metric-label {
            font-weight: 500;
            color: #6c757d;
        }
        .metric-value {
            font-weight: bold;
            color: #212529;
        }
        .model-description {
            font-size: 0.85em;
            color: #6c757d;
            margin-top: 10px;
            font-style: italic;
        }
        .comparison-table {
            margin-top: 20px;
            overflow-x: auto;
        }
        .comparison-table table {
            width: 100%;
            border-collapse: collapse;
        }
        .comparison-table th {
            background: #007bff;
            color: white;
            padding: 12px;
            text-align: left;
        }
        .comparison-table td {
            padding: 10px 12px;
        }
        .highlight {
            background: #fff3cd;
            font-weight: bold;
        }
    </style>
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
                    <a href="contributors.php">Contributors</a>
                </nav>
            </div>
        </div>
    </header>

    <div class="container">
        <div class="card">
            <h2>📊 View Metrics</h2>
            <form method="GET" style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                <label for="contributor" style="margin: 0; font-weight: bold;">Contributor:</label>
                <select name="contributor" id="contributor" onchange="this.form.submit()" style="width: auto; margin: 0;">
                    <option value="ALL" <?= $selectedContributor === 'ALL' ? 'selected' : '' ?>>All Contributors</option>
                    <?php foreach ($allContributors as $name): ?>
                        <option value="<?= htmlspecialchars($name) ?>"
                                <?= $selectedContributor === $name ? 'selected' : '' ?>>
                            <?= htmlspecialchars($name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="metric" style="margin: 0; font-weight: bold;">Metric:</label>
                <select name="metric" id="metric" onchange="this.form.submit()" style="width: auto; margin: 0;">
                    <?php foreach ($statsColumnsMap as $key => $config): ?>
                        <option value="<?= $key ?>" <?= $selectedMetric === $key ? 'selected' : '' ?>>
                            <?= htmlspecialchars($config['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span style="color: #6c757d; font-size: 0.9em;">
                    <?= htmlspecialchars($metricConfig['description']) ?>
                </span>
            </form>

        <div class="card">
            <h2>Contributor Statistics</h2>
            <p>Code statistics across all projects, by contributor. Line counts show net changes (deltas) contributed.</p>

            <?php if (empty($contributors)): ?>
                <p>No contributor data available yet.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Contributor</th>
                            <th>Commits</th>
                            <th>Projects</th>
                            <th>Total Lines (Δ)</th>
                            <?php if ($selectedMetric === 'total_lines'): ?>
                                <th>Code Lines (Δ)</th>
                            <?php else: ?>
                                <th><?= htmlspecialchars($metricConfig['label']) ?> (Δ)</th>
                            <?php endif; ?>
                            <th>First Commit</th>
                            <th>Last Commit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contributors as $contrib): ?>
                            <?php if (($selectedContributor == 'ALL') || ($selectedContributor == $contrib['contributor'])): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($contrib['contributor']) ?></strong></td>
                                <td><?= number_format($contrib['commit_count']) ?></td>
                                <td><?= $contrib['project_count'] ?></td>
                                <td><?= number_format($contrib['total_lines_delta'] ?? 0) ?></td>
                                <?php if ($selectedMetric === 'total_lines'): ?>
                                    <td><?= number_format($contrib['code_lines_delta'] ?? 0) ?></td>
                                <?php else: ?>
                                    <td><?= number_format($contrib['metric_value_delta'] ?? 0) ?></td>
                                <?php endif; ?>
                                <td><?= htmlspecialchars(date('Y-m-d', strtotime($contrib['first_commit']))) ?></td>
                                <td><?= htmlspecialchars(date('Y-m-d', strtotime($contrib['last_commit']))) ?></td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <tr style="font-weight: bold; border-top: 2px solid #dee2e6;">
                            <td>Total</td>
                            <td><?= number_format($totals['commit_count']) ?></td>
                            <td><?= $totals['project_count'] ?> projects</td>
                            <td><?= number_format($totals['total_lines_delta']) ?></td>
                            <?php if ($selectedMetric === 'total_lines'): ?>
                                <td><?= number_format($totals['code_lines_delta']) ?></td>
                            <?php else: ?>
                                <td><?= number_format($totals['metric_value_delta']) ?></td>
                            <?php endif; ?>
                            <td colspan="2"></td>
                        </tr>
                    </tbody>
                </table>

                <div class="chart-container">
                    <canvas id="contributorChart"></canvas>
                </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($contributorChartData)): ?>
        <div style="margin-top: 40px;">
            <h2 style="margin-bottom: 20px;">📈 Top Contributor Activity</h2>
            
            <?php foreach ($contributorChartData as $name => $chartData): ?>
            <div class="card" style="margin-bottom: 30px;">
                <h3 style="margin-top: 0; color: #495057; border-bottom: 2px solid #007bff; padding-bottom: 10px;">
                    <?= htmlspecialchars($name) ?>
                </h3>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                    <!-- Commit Count Chart -->
                    <div>
                        <h4 style="text-align: center; color: #6c757d; margin-bottom: 10px;">
                            Commits Over Time
                        </h4>
                        <div class="chart-container" style="height: 200px;">
                            <canvas id="commits_<?= htmlspecialchars(preg_replace('/[^a-zA-Z0-9]/', '_', $name)) ?>"></canvas>
                        </div>
                    </div>
                    
                    <!-- Metric Delta Chart -->
                    <div>
                        <h4 style="text-align: center; color: #6c757d; margin-bottom: 10px;">
                            <?= htmlspecialchars($metricConfig['label']) ?> Contribution
                        </h4>
                        <div class="chart-container" style="height: 200px;">
                            <canvas id="delta_<?= htmlspecialchars(preg_replace('/[^a-zA-Z0-9]/', '_', $name)) ?>"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($estimates !== null): ?>
            <div class="comparison-table">
                <h3>Model Comparison Summary
                    <?php if ($selectedContributor !== 'ALL'): ?>
                        - <?= htmlspecialchars($selectedContributor) ?>
                    <?php else: ?>
                        - All Contributors
                    <?php endif; ?>
                </h3>
                <table>
                    <thead>
                        <tr>
                            <th>Model</th>
                            <th>Effort (PM)</th>
                            <th>Schedule (Months)</th>
                            <th>Team Size</th>
                            <th>Cost (@ $<?= $hourlyRate ?>/hr)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>COCOMO</strong></td>
                            <td><?= number_format($estimates['cocomo']['effort'], 1) ?></td>
                            <td><?= number_format($estimates['cocomo']['time'], 1) ?></td>
                            <td><?= number_format($estimates['cocomo']['people'], 1) ?></td>
                            <td>$<?= number_format($estimates['cocomo']['effort'] * $hoursPerMonth * $hourlyRate, 0) ?></td>
                        </tr>
                        <tr>
                            <td><strong>COCOMO II</strong></td>
                            <td><?= number_format($estimates['cocomo2']['effort'], 1) ?></td>
                            <td><?= number_format($estimates['cocomo2']['time'], 1) ?></td>
                            <td><?= number_format($estimates['cocomo2']['people'], 1) ?></td>
                            <td>$<?= number_format($estimates['cocomo2']['effort'] * $hoursPerMonth * $hourlyRate, 0) ?></td>
                        </tr>
                        <tr>
                            <td><strong>Function Points</strong></td>
                            <td><?= number_format($estimates['functionPoints']['effort'], 1) ?></td>
                            <td><?= number_format($estimates['functionPoints']['time'], 1) ?></td>
                            <td><?= number_format($estimates['functionPoints']['people'], 1) ?></td>
                            <td>$<?= number_format($estimates['functionPoints']['effort'] * $hoursPerMonth * $hourlyRate, 0) ?></td>
                        </tr>
                        <tr>
                            <td><strong>SLIM</strong></td>
                            <td><?= number_format($estimates['slim']['effort'], 1) ?></td>
                            <td><?= number_format($estimates['slim']['time'], 1) ?></td>
                            <td><?= number_format($estimates['slim']['people'], 1) ?></td>
                            <td>$<?= number_format($estimates['slim']['effort'] * $hoursPerMonth * $hourlyRate, 0) ?></td>
                        </tr>
                        <tr>
                            <td><strong>Putnam</strong></td>
                            <td><?= number_format($estimates['putnam']['effort'], 1) ?></td>
                            <td><?= number_format($estimates['putnam']['time'], 1) ?></td>
                            <td><?= number_format($estimates['putnam']['people'], 1) ?></td>
                            <td>$<?= number_format($estimates['putnam']['effort'] * $hoursPerMonth * $hourlyRate, 0) ?></td>
                        </tr>
                        <?php
                        $efforts = [
                            $estimates['cocomo']['effort'],
                            $estimates['cocomo2']['effort'],
                            $estimates['functionPoints']['effort'],
                            $estimates['slim']['effort'],
                            $estimates['putnam']['effort']
                        ];
                        $avgEffort = array_sum($efforts) / count($efforts);
                        $times = [
                            $estimates['cocomo']['time'],
                            $estimates['cocomo2']['time'],
                            $estimates['functionPoints']['time'],
                            $estimates['slim']['time'],
                            $estimates['putnam']['time']
                        ];
                        $avgTime = array_sum($times) / count($times);
                        $avgPeople = $avgEffort / $avgTime;
                        $avgCost = $avgEffort * $hoursPerMonth * $hourlyRate;
                        ?>
                        <tr class="highlight">
                            <td><strong>AVERAGE</strong></td>
                            <td><?= number_format($avgEffort, 1) ?></td>
                            <td><?= number_format($avgTime, 1) ?></td>
                            <td><?= number_format($avgPeople, 1) ?></td>
                            <td>$<?= number_format($avgCost, 0) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 20px; padding: 15px; background: #e7f3ff; border-left: 4px solid #007bff; border-radius: 4px;">
                <p style="margin: 0; font-size: 0.9em;">
                    <strong>Note:</strong> 
                    <?php if ($selectedContributor !== 'ALL'): ?>
                        These estimates are based solely on <?= htmlspecialchars($selectedContributor) ?>'s contributions (<?= number_format($estimateBase) ?> <?= $selectedMetric === 'total_lines' ? 'lines of code' : htmlspecialchars(strtolower($metricConfig['label'])) ?>). Individual contributor estimates may not reflect collaborative work or code review contributions.
                    <?php else: ?>
                        These portfolio-wide estimates represent the aggregate value of all contributors combined. Individual contributor statistics may vary based on specific roles and project involvement.
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    <?php if (!empty($contributors)): ?>
    const ctx = document.getElementById('contributorChart');
    new Chart(ctx,
    {
        type: 'pie',
        data: {
            labels: <?= json_encode(array_column($contributors, 'contributor')) ?>,
            datasets: [{
                label: '<?= htmlspecialchars($metricConfig['label']) ?> (Δ)',
                data: <?php
                    if ($selectedMetric === 'total_lines') {
                        echo json_encode(array_column($contributors, 'code_lines_delta'));
                    } else {
                        echo json_encode(array_column($contributors, 'metric_value_delta'));
                    }
                ?>,
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
    <?php foreach ($contributorChartData as $name => $chartData): ?>
        <?php $safeName = preg_replace('/[^a-zA-Z0-9]/', '_', $name); ?>
        (function() 
        {
            const ctx = document.getElementById('commits_<?= $safeName ?>');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($chartData['labels']) ?>,
                    datasets: [{
                        label: 'Commits per <?= $chartData['interval'] ?>',
                        data: <?= json_encode($chartData['commits']) ?>.map(v => Math.sqrt(v)),
                        backgroundColor: 'rgba(0, 123, 255, 0.7)',
                        borderColor: '#007bff',
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
                                stepSize: 1,
                                callback: function(value) {
                                    return Math.round(Math.pow(value, 2));
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const transformedValue = context.parsed.y;
                                    const realValue = Math.round(Math.pow(transformedValue, 2));
                                    return 'Commits: ' + realValue;
                                }
                            }
                        }
                    }
                }
            });
        })();
    
        // Metric delta chart (with sqrt scaling for absolute values)
        (function() 
        {
            const ctx = document.getElementById('delta_<?= $safeName ?>');
            const rawData = <?= json_encode($chartData['deltas']) ?>;
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($chartData['labels']) ?>,
                    datasets: [{
                        label: 'Avg <?= htmlspecialchars($metricConfig['label']) ?> Change',
                        data: rawData.map(v => v >= 0 ? Math.sqrt(v) : -Math.sqrt(Math.abs(v))),
                        backgroundColor: rawData.map(v => v >= 0 ? 'rgba(40, 167, 69, 0.7)' : 'rgba(220, 53, 69, 0.7)'),
                        borderColor: rawData.map(v => v >= 0 ? '#28a745' : '#dc3545'),
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
                                callback: function(value) {
                                    const realValue = value >= 0 
                                        ? Math.pow(value, 2) 
                                        : -Math.pow(Math.abs(value), 2);
                                    return (realValue >= 0 ? '+' : '') + Math.round(realValue);
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const transformedValue = context.parsed.y;
                                    const realValue = transformedValue >= 0 
                                        ? Math.pow(transformedValue, 2) 
                                        : -Math.pow(Math.abs(transformedValue), 2);
                                    return 'Avg change: ' + (realValue >= 0 ? '+' : '') + Math.round(realValue);
                                }
                            }
                        }
                    }
                }
            });
        })();
    <?php endforeach; ?>
</script>
    <footer style="text-align: center; padding: 20px; margin-top: 40px; font-size: 0.8em; color: #999;">
        <a href="../admin/login.php" style="color: #999; text-decoration: none;">admin</a>
    </footer>
</body>
</html>