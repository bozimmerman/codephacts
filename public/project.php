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
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '/db.php';

$projectId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

try
{
    $pdo = getDatabase($config);
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
    $commitPage = isset($_GET['commit_page']) ? max(1, (int)$_GET['commit_page']) : 1;
    $commitsPerPage = 20;
    $commitOffset = ($commitPage - 1) * $commitsPerPage;
    
    // Get total commit count for pagination
    $commitCountStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM {$config['tables']['commits']} c
    INNER JOIN {$config['tables']['statistics']} s ON c.id = s.commit_id
    WHERE c.project_id = ?
    ");
    $commitCountStmt->execute([$projectId]);
    $totalCommits = $commitCountStmt->fetchColumn();
    $totalCommitPages = ceil($totalCommits / $commitsPerPage);
    $stmt = $pdo->prepare("
    SELECT
        c.commit_hash,
        c.commit_timestamp,
        c.commit_user,
        SUM(s.code_lines) as total_code_lines
    FROM {$config['tables']['commits']} c
    LEFT JOIN {$config['tables']['statistics']} s ON c.id = s.commit_id
    WHERE c.project_id = ?
    GROUP BY c.id, c.commit_hash, c.commit_timestamp
    ORDER BY c.commit_timestamp DESC
    LIMIT {$commitsPerPage} OFFSET {$commitOffset}
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

// ============================================================================
// ESTIMATION MODELS
// ============================================================================

/**
 * Calculate Basic COCOMO estimates
 * @param int $kloc Thousands of lines of code
 * @param string $mode Project mode: 'organic', 'semi-detached', or 'embedded'
 * @return array Effort (person-months), Time (months), People
 */
function calculateCOCOMO($kloc, $mode = 'semi-detached')
{
    $coefficients = [
        'organic' => ['a' => 2.4, 'b' => 1.05, 'c' => 2.5, 'd' => 0.38],
        'semi-detached' => ['a' => 3.0, 'b' => 1.12, 'c' => 2.5, 'd' => 0.35],
        'embedded' => ['a' => 3.6, 'b' => 1.20, 'c' => 2.5, 'd' => 0.32]
    ];
    
    if (!isset($coefficients[$mode]))
        $mode = 'semi-detached';
        
        $coef = $coefficients[$mode];
        $effort = $coef['a'] * pow($kloc, $coef['b']);
        $time = $coef['c'] * pow($effort, $coef['d']);
        $people = $effort / $time;
        
        return [
            'effort' => $effort,
            'time' => $time,
            'people' => $people,
            'mode' => $mode
        ];
}

/**
 * Calculate COCOMO II estimates (Post-Architecture model)
 * @param int $kloc Thousands of lines of code
 * @param array $scaleFactors Scale factors (PREC, FLEX, RESL, TEAM, PMAT) each 0-5
 * @param array $effortMultipliers 17 cost drivers, each 0.5-2.0
 * @return array Effort and time estimates
 */
function calculateCOCOMO2($kloc, $scaleFactors = null, $effortMultipliers = null)
{
    // Default scale factors (nominal = 3.0 each)
    if ($scaleFactors === null) {
        $scaleFactors = [
            'PREC' => 3.0,  // Precedentedness
            'FLEX' => 3.0,  // Development Flexibility
            'RESL' => 3.0,  // Architecture/Risk Resolution
            'TEAM' => 3.0,  // Team Cohesion
            'PMAT' => 3.0   // Process Maturity
        ];
    }
    
    // Calculate exponent (B)
    $B = 0.91 + 0.01 * array_sum($scaleFactors);
    
    // Base constant
    $A = 2.94;
    
    // Calculate base effort
    $effort = $A * pow($kloc, $B);
    
    // Apply effort multipliers if provided (all nominal = 1.0)
    if ($effortMultipliers !== null) {
        foreach ($effortMultipliers as $em) {
            $effort *= $em;
        }
    }
    
    // Calculate time
    $C = 3.67;
    $D = 0.28 + 0.2 * ($B - 0.91);
    $time = $C * pow($effort, $D);
    
    $people = $effort / $time;
    
    return [
        'effort' => $effort,
        'time' => $time,
        'people' => $people,
        'exponent' => $B
    ];
}

/**
 * Estimate Function Points from LOC
 * Simple conversion based on language
 * @param int $loc Lines of code
 * @param string $language Programming language
 * @return array Function points and estimates
 */
function calculateFunctionPoints($loc, $language = 'PHP')
{
    // Average LOC per function point by language (industry averages)
    $locPerFP = [
        'Assembly' => 320,
        'C' => 128,
        'C++' => 55,
        'Java' => 55,
        'JavaScript' => 47,
        'PHP' => 60,
        'Python' => 38,
        'Ruby' => 38,
        'C#' => 55,
        'SQL' => 13,
        'HTML' => 15,
        'CSS' => 30,
        'Default' => 60
    ];
    
    $ratio = isset($locPerFP[$language]) ? $locPerFP[$language] : $locPerFP['Default'];
    $functionPoints = $loc / $ratio;
    
    // Estimate effort: typically 5-10 hours per function point
    $hoursPerFP = 7.5; // median
    $effort = ($functionPoints * $hoursPerFP) / 160; // Convert to person-months
    
    // Estimate time using simplified model
    $time = 2.5 * pow($effort, 0.38);
    $people = $effort / $time;
    
    return [
        'functionPoints' => $functionPoints,
        'effort' => $effort,
        'time' => $time,
        'people' => $people,
        'language' => $language,
        'locPerFP' => $ratio
    ];
}

/**
 * Calculate SLIM (Putnam) model estimates
 * @param int $loc Lines of code
 * @param int $productivity Productivity parameter (typically 2000-12000)
 * @return array Effort and time estimates
 */
function calculateSLIM($loc, $productivity = 5000)
{
    $kloc = $loc / 1000;
    $effort = 3.0 * pow($kloc, 1.12); // Initial COCOMO estimate
    
    // Iterate to solve the implicit equation
    for ($i = 0; $i < 10; $i++) {
        $time = pow($loc / ($productivity * pow($effort, 1/3)), 0.75);
        $newEffort = pow($loc / ($productivity * pow($time, 4/3)), 3);
        
        if (abs($newEffort - $effort) < 0.01) {
            $effort = $newEffort;
            break;
        }
        
        $effort = ($effort + $newEffort) / 2;
    }
    
    // Final time calculation
    $time = pow($loc / ($productivity * pow($effort, 1/3)), 0.75);
    $people = $effort / $time;
    
    return [
        'effort' => $effort,
        'time' => $time,
        'people' => $people,
        'productivity' => $productivity
    ];
}

/**
 * Calculate Putnam model estimates (alternative formulation)
 * @param int $loc Lines of code
 * @param float $technology Technology constant (2000-12000, default 8000)
 * @return array Effort and time estimates
 */
function calculatePutnam($loc, $technology = 8000)
{
    // Putnam model: Size = C_k × K^(1/3) × t_d^(4/3)
    // Where: Size = KLOC, K = effort (person-years), t_d = time (years), C_k = technology constant
    
    $kloc = $loc / 1000;
    
    // Minimum development time (years): t_min = k_1 × KLOC^k_2
    $k1 = 0.15; // typical value
    $k2 = 0.4;  // typical value
    $tMin = $k1 * pow($kloc, $k2);
    
    // Use 1.2x minimum time for realistic schedule
    $time = $tMin * 1.2;
    
    // Calculate effort: K = (Size / (C_k × t_d^(4/3)))^3
    $effort = pow($kloc / ($technology / 1000 * pow($time, 4/3)), 3);
    
    // Convert years to months
    $timeMonths = $time * 12;
    $effortMonths = $effort * 12;
    
    $people = $effortMonths / $timeMonths;
    
    return [
        'effort' => $effortMonths,
        'time' => $timeMonths,
        'people' => $people,
        'technology' => $technology,
        'minTime' => $tMin * 12
    ];
}

/**
 * Generate comprehensive project estimates
 * @param int $loc Lines of code
 * @param string $primaryLanguage Primary programming language
 * @param string $cocomoMode COCOMO mode
 * @return array All estimation results
 */
function generateEstimates($loc, $primaryLanguage = 'PHP', $cocomoMode = 'semi-detached')
{
    $kloc = $loc / 1000;
    
    return [
        'cocomo' => calculateCOCOMO($kloc, $cocomoMode),
        'cocomo2' => calculateCOCOMO2($kloc),
        'functionPoints' => calculateFunctionPoints($loc, $primaryLanguage),
        'slim' => calculateSLIM($loc),
        'putnam' => calculatePutnam($loc)
    ];
}

// Generate estimates for this project
$primaryLanguage = !empty($languages) ? $languages[0]['language'] : 'PHP';
$estimates = generateEstimates($totals['code_lines'], $primaryLanguage);

// Calculate costs (configurable hourly rate)
$hourlyRate = 75; // USD per hour
$hoursPerMonth = 160; // Standard work month

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
                </nav>
            </div>
        </div>
    </header>

    <div class="container">
        <div class="card">
            <h2><?= htmlspecialchars($project['name']) ?></h2>
            <p><strong>Manager Name:</strong> <?= htmlspecialchars($project['manager'] ?? 'None') ?></p>
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
            <h2>Total Lines of Code Growth</h2>
            <div class="chart-container" style="height: 300px;">
                <canvas id="totalLinesChart"></canvas>
            </div>
        </div>
        <div class="card">
            <h2>Code Composition Analysis</h2>
            <div class="chart-container" style="height: 300px;">
                <canvas id="compositionChart"></canvas>
            </div>
        </div>
        
        <div class="card">
            <h2>Commit Activity Over Time</h2>
            <div class="chart-container" style="height: 300px;">
                <canvas id="commitActivityChart"></canvas>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($totals['code_lines'] > 0): ?>
        <div class="card">
            <h2>📊 Project Cost Estimation Models</h2>
            <p>Based on <strong><?= number_format($totals['code_lines']) ?></strong> lines of code (<?= number_format($totals['code_lines'] / 1000, 1) ?> KLOC)</p>
            
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
                        <span class="metric-label">Language:</span>
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
                    <strong>Note:</strong> These are theoretical estimates based on historical models. Actual project costs vary significantly based on team experience, requirements clarity, technology choices, and project management practices. Use these as rough guidelines for planning and budgeting, not as precise predictions.
                </p>
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
                            <th>User</th>
                            <th>Date</th>
                            <th>Total Code Lines</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($commits as $commit): ?>
                            <tr>
                                <td><?= htmlspecialchars(substr($commit['commit_hash'], 0, 10)) ?></td>
                                <td><?= htmlspecialchars($commit['commit_user'] ?? 'None') ?></td>
                                <td><?= htmlspecialchars($commit['commit_timestamp']) ?></td>
                                <td><?= number_format($commit['total_code_lines'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($totalCommitPages > 1): ?>
                    <div style="margin-top: 20px; text-align: center;">
                        <?php if ($commitPage > 1): ?>
                            <a href="?id=<?= $projectId ?>&commit_page=<?= $commitPage - 1 ?>" class="button">← Previous</a>
                        <?php endif; ?>
                        
                        <span style="margin: 0 15px; color: #6c757d;">
                            Page <?= $commitPage ?> of <?= $totalCommitPages ?> (<?= number_format($totalCommits) ?> total commits)
                        </span>
                        
                        <?php if ($commitPage < $totalCommitPages): ?>
                            <a href="?id=<?= $projectId ?>&commit_page=<?= $commitPage + 1 ?>" class="button">Next →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
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
                data: commitData.changes.map(v => v >= 0 ? Math.sqrt(v) : -Math.sqrt(Math.abs(v))),
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
                        text: 'Average Lines Changed (sqrt scale)'
                    },
                    ticks: {
                        callback: function(value) {
                            const realValue = value >= 0 
                                ? Math.pow(value, 2) 
                                : -Math.pow(Math.abs(value), 2);
                            return (realValue >= 0 ? '+' : '') + Math.round(realValue);
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
                            const transformedValue = context.parsed.y;
                            const realValue = transformedValue >= 0 
                                ? Math.pow(transformedValue, 2) 
                                : -Math.pow(Math.abs(transformedValue), 2);
                            return 'Avg change: ' + (realValue >= 0 ? '+' + Math.round(realValue) : Math.round(realValue)) + ' lines';
                        }
                    }
                }
            }
        }
    });

 // Commit Activity Chart (using the same grouping as code history)
    function groupCommitActivity(commits, intervalDays) {
        const grouped = {};
        commits.forEach(c => {
            const date = new Date(c.commit_timestamp);
            const timestamp = Math.floor(date.getTime() / 1000);
            const bucketKey = Math.floor(timestamp / (intervalDays * 86400)) * (intervalDays * 86400);
            grouped[bucketKey] = (grouped[bucketKey] || 0) + 1;
        });
        
        const labels = [];
        const counts = [];
        Object.keys(grouped).sort().forEach(key => {
            const date = new Date(parseInt(key) * 1000);
            labels.push(date.toLocaleDateString());
            counts.push(grouped[key]);
        });
        
        return { labels, counts };
    }

    const activityData = groupCommitActivity(commits, <?= $grouping['intervalDays'] ?>);
    const activityCtx = document.getElementById('commitActivityChart');
    new Chart(activityCtx, {
        type: 'bar',
        data: {
            labels: activityData.labels,
            datasets: [{
                label: 'Number of Commits',
                data: activityData.counts.map(v => Math.sqrt(v)),
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
                        callback: function(value) {
                            return Math.round(Math.pow(value, 2));
                        }
                    },
                    title: {
                        display: true,
                        text: 'Number of Commits'
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
                            const transformedValue = context.parsed.y;
                            const realValue = Math.round(Math.pow(transformedValue, 2));
                            return 'Commits: ' + realValue;
                        }
                    }
                }
            }
        }
    });
    // Total Lines of Code Growth Chart
    const totalLinesData = [];
    const totalLinesLabels = [];
    commits.forEach(c => 
    {
        if (c.total_code_lines && c.total_code_lines > 0) 
        {
            totalLinesLabels.push(new Date(c.commit_timestamp).toLocaleDateString());
            totalLinesData.push(parseInt(c.total_code_lines));
        }
    });
    const totalLinesCtx = document.getElementById('totalLinesChart');
    new Chart(totalLinesCtx, 
    {
        type: 'line',
        data: {
            labels: totalLinesLabels,
            datasets: [{
                label: 'Total Lines of Code',
                data: totalLinesData,
                borderColor: '#007bff',
                backgroundColor: 'rgba(0, 123, 255, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.1,
                pointRadius: 0,
                pointHoverRadius: 4
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
                        text: 'Lines of Code'
                    },
                    ticks: {
                        callback: function(value) {
                            return value.toLocaleString();
                        }
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Commit Date'
                    },
                    ticks: {
                        maxTicksLimit: 20,
                        maxRotation: 45,
                        minRotation: 45
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
                            return 'Total LOC: ' + context.parsed.y.toLocaleString();
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>
    <?php if (!empty($languages)): ?>
    // Code Composition Chart
    const compositionCtx = document.getElementById('compositionChart');
    new Chart(compositionCtx, {
        type: 'bar',
        data: {
            labels: ['Code Lines', 'Comment Lines', 'Blank Lines'],
            datasets: [{
                label: 'Percentage',
                data: [
                    <?= $totals['total_lines'] > 0 ? number_format(($totals['code_lines'] / $totals['total_lines']) * 100, 1) : 0 ?>,
                    <?= $totals['total_lines'] > 0 ? number_format(($totals['comment_lines'] / $totals['total_lines']) * 100, 1) : 0 ?>,
                    <?= $totals['total_lines'] > 0 ? number_format(($totals['blank_lines'] / $totals['total_lines']) * 100, 1) : 0 ?>
                ],
                backgroundColor: [
                    'rgba(40, 167, 69, 0.7)',
                    'rgba(0, 123, 255, 0.7)',
                    'rgba(108, 117, 125, 0.7)'
                ],
                borderColor: [
                    '#28a745',
                    '#007bff',
                    '#6c757d'
                ],
                borderWidth: 1
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    beginAtZero: true,
                    max: 100,
                    title: {
                        display: true,
                        text: 'Percentage (%)'
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
                            return context.parsed.x.toFixed(1) + '%';
                        }
                    }
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