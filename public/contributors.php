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
    $stmt = $pdo->query("
        SELECT
            c.id,
            c.project_id,
            c.commit_user,
            c.commit_timestamp,
            s.language,
            s.total_lines,
            s.code_lines,
            s.{$metricColumn} as metric_value
        FROM {$config['tables']['commits']} c
        INNER JOIN {$config['tables']['statistics']} s ON c.id = s.commit_id
        WHERE c.commit_user IS NOT NULL AND c.commit_user != ''
        ORDER BY c.project_id, s.language, c.commit_timestamp ASC
    ");
    $allCommits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $selectedContributor = isset($_GET['contributor']) ? $_GET['contributor'] : 'ALL';
    $contributors = [];
    $previousState = [];
    $allProjects = [];
    
    foreach ($allCommits as $commit)
    {
        $user = $commit['commit_user'];
        $projectId = $commit['project_id'];
        $language = $commit['language'];
        $commitId = $commit['id'];
        $key = $projectId . '_' . $language;
        
        if (!isset($contributors[$user]))
        {
            $contributors[$user] = [
                'contributor' => $user,
                'commits' => [],
                'projects' => [],
                'total_lines_delta' => 0,
                'code_lines_delta' => 0,
                'metric_value_delta' => 0,
                'first_commit' => $commit['commit_timestamp'],
                'last_commit' => $commit['commit_timestamp']
            ];
        }
        if (($selectedContributor == 'ALL') || ($selectedContributor == $user))
        {
            $allProjects[$projectId] = true;
            $contributors[$user]['commits'][$commitId] = true;
            $contributors[$user]['projects'][$projectId] = true;
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
        }
        $previousState[$key] = [
            'total_lines' => $commit['total_lines'],
            'code_lines' => $commit['code_lines'],
            'metric_value' => $commit['metric_value']
        ];
    }
    $contributors = array_values($contributors);
    foreach ($contributors as &$contrib)
    {
        $contrib['commit_count'] = count($contrib['commits']);
        $contrib['project_count'] = count($contrib['projects']);
        unset($contrib['commits']);
        unset($contrib['projects']);
    }
    unset($contrib);
    
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
$hoursPerMonth = 160;?>
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
                <label for="metric" style="margin: 0; font-weight: bold;">Select Metric:</label>
                <select name="metric" id="metric" onchange="this.form.submit()" style="width: auto; margin: 0;">
                    <?php foreach ($statsColumnsMap as $key => $config): ?>
                        <option value="<?= $key ?>" <?= $selectedMetric === $key ? 'selected' : '' ?>>
                            <?= htmlspecialchars($config['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <label for="contributor" style="margin: 0; font-weight: bold;">Contributor:</label>
                <select name="contributor" id="contributor" onchange="this.form.submit()" style="width: auto; margin: 0;">
                    <option value="ALL" <?= $selectedContributor === 'ALL' ? 'selected' : '' ?>>All Contributors</option>
                    <?php foreach ($contributors as $contrib): ?>
                        <option value="<?= htmlspecialchars($contrib['contributor']) ?>" 
                                <?= $selectedContributor === $contrib['contributor'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($contrib['contributor']) ?>
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

        <?php if ($estimates !== null): ?>
        <div class="card">
            <h2>📊 <?= $selectedContributor === 'ALL' ? 'Portfolio-Wide' : htmlspecialchars($selectedContributor) . '\'s' ?> Cost Estimation</h2>
            <p><?= $selectedContributor === 'ALL' ? 'Aggregate estimates across all projects' : 'Estimates for ' . htmlspecialchars($selectedContributor) ?> 
            	based on <strong><?= number_format($estimateBase) ?></strong> <?= $selectedMetric === 'total_lines' ? 'lines of code' : htmlspecialchars(strtolower($metricConfig['label'])) ?> (<?= number_format($estimateBase / 1000, 1) ?> K)</p>
            <div class="estimate-grid">
                <!-- COCOMO -->
                <div class="estimate-card">
                    <h3>COCOMO (1981)</h3>
                    <div class="metric">
                        <span class="metric-label">Effort:</span>
                        <span class="metric-value"><?= number_format($estimates['cocomo']['effort'], 1) ?> person-months</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Schedule:</span>
                        <span class="metric-value"><?= number_format($estimates['cocomo']['time'], 1) ?> months</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Team Size:</span>
                        <span class="metric-value"><?= number_format($estimates['cocomo']['people'], 1) ?> people</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Cost Estimate:</span>
                        <span class="metric-value">$<?= number_format($estimates['cocomo']['effort'] * $hoursPerMonth * $hourlyRate, 0) ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Mode:</span>
                        <span class="metric-value"><?= ucfirst($estimates['cocomo']['mode']) ?></span>
                    </div>
                    <div class="model-description">
                        Barry Boehm's foundational model using empirical coefficients based on project type.
                    </div>
                </div>

                <!-- COCOMO II -->
                <div class="estimate-card">
                    <h3>COCOMO II (2000)</h3>
                    <div class="metric">
                        <span class="metric-label">Effort:</span>
                        <span class="metric-value"><?= number_format($estimates['cocomo2']['effort'], 1) ?> person-months</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Schedule:</span>
                        <span class="metric-value"><?= number_format($estimates['cocomo2']['time'], 1) ?> months</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Team Size:</span>
                        <span class="metric-value"><?= number_format($estimates['cocomo2']['people'], 1) ?> people</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Cost Estimate:</span>
                        <span class="metric-value">$<?= number_format($estimates['cocomo2']['effort'] * $hoursPerMonth * $hourlyRate, 0) ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Exponent (B):</span>
                        <span class="metric-value"><?= number_format($estimates['cocomo2']['exponent'], 3) ?></span>
                    </div>
                    <div class="model-description">
                        Updated model with scale factors for modern development practices and reuse.
                    </div>
                </div>

                <!-- Function Point Analysis -->
                <div class="estimate-card">
                    <h3>Function Point Analysis</h3>
                    <div class="metric">
                        <span class="metric-label">Function Points:</span>
                        <span class="metric-value"><?= number_format($estimates['functionPoints']['functionPoints'], 0) ?> FP</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Effort:</span>
                        <span class="metric-value"><?= number_format($estimates['functionPoints']['effort'], 1) ?> person-months</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Schedule:</span>
                        <span class="metric-value"><?= number_format($estimates['functionPoints']['time'], 1) ?> months</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Team Size:</span>
                        <span class="metric-value"><?= number_format($estimates['functionPoints']['people'], 1) ?> people</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Cost Estimate:</span>
                        <span class="metric-value">$<?= number_format($estimates['functionPoints']['effort'] * $hoursPerMonth * $hourlyRate, 0) ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Primary Language:</span>
                        <span class="metric-value"><?= $estimates['functionPoints']['language'] ?></span>
                    </div>
                    <div class="model-description">
                        Measures functionality independent of technology using standardized function points (~<?= $estimates['functionPoints']['locPerFP'] ?> LOC/FP for <?= $estimates['functionPoints']['language'] ?>).
                    </div>
                </div>

                <!-- SLIM -->
                <div class="estimate-card">
                    <h3>SLIM Model</h3>
                    <div class="metric">
                        <span class="metric-label">Effort:</span>
                        <span class="metric-value"><?= number_format($estimates['slim']['effort'], 1) ?> person-months</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Schedule:</span>
                        <span class="metric-value"><?= number_format($estimates['slim']['time'], 1) ?> months</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Team Size:</span>
                        <span class="metric-value"><?= number_format($estimates['slim']['people'], 1) ?> people</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Cost Estimate:</span>
                        <span class="metric-value">$<?= number_format($estimates['slim']['effort'] * $hoursPerMonth * $hourlyRate, 0) ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Productivity Index:</span>
                        <span class="metric-value"><?= number_format($estimates['slim']['productivity']) ?></span>
                    </div>
                    <div class="model-description">
                        Uses Rayleigh curves and Putnam's software equation emphasizing optimal staffing over time.
                    </div>
                </div>

                <!-- Putnam Model -->
                <div class="estimate-card">
                    <h3>Putnam Model</h3>
                    <div class="metric">
                        <span class="metric-label">Effort:</span>
                        <span class="metric-value"><?= number_format($estimates['putnam']['effort'], 1) ?> person-months</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Schedule:</span>
                        <span class="metric-value"><?= number_format($estimates['putnam']['time'], 1) ?> months</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Min Schedule:</span>
                        <span class="metric-value"><?= number_format($estimates['putnam']['minTime'], 1) ?> months</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Team Size:</span>
                        <span class="metric-value"><?= number_format($estimates['putnam']['people'], 1) ?> people</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Cost Estimate:</span>
                        <span class="metric-value">$<?= number_format($estimates['putnam']['effort'] * $hoursPerMonth * $hourlyRate, 0) ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Technology Factor:</span>
                        <span class="metric-value"><?= number_format($estimates['putnam']['technology']) ?></span>
                    </div>
                    <div class="model-description">
                        Lawrence Putnam's lifecycle model based on productivity and minimum development time constraints.
                    </div>
                </div>
            </div>

            <div class="comparison-table">
                <h3>Model Comparison Summary</h3>
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
                    <strong>Note:</strong> These portfolio-wide estimates represent the aggregate value of all contributors combined. Individual contributor statistics may vary based on specific roles and project involvement.
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
    </script>
    <footer style="text-align: center; padding: 20px; margin-top: 40px; font-size: 0.8em; color: #999;">
        <a href="../admin/login.php" style="color: #999; text-decoration: none;">admin</a>
    </footer>
</body>
</html>