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

$statsColumnsMap = require __DIR__ . DIRECTORY_SEPARATOR . 'stats_columns_map.php';

$results = null;
$error = null;

try
{
    $pdo = getDatabase($config);
    $stmt = $pdo->query("SELECT id, name FROM {$config['tables']['projects']} ORDER BY name ASC");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->query("SELECT DISTINCT language FROM {$config['tables']['statistics']} ORDER BY language ASC");
    $languages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($_SERVER['REQUEST_METHOD'] === 'POST')
    {
        $selectedProjects = isset($_POST['projects']) ? $_POST['projects'] : [];
        $selectedLanguages = isset($_POST['languages']) ? $_POST['languages'] : [];
        $dateFrom = isset($_POST['date_from']) ? $_POST['date_from'] : null;
        $dateTo = isset($_POST['date_to']) ? $_POST['date_to'] : null;
        $viewMode = isset($_POST['view_mode']) ? $_POST['view_mode'] : 'detail';
        $selectedMetric = isset($_POST['metric']) ? $_POST['metric'] : 'code_lines';
        
        if (!isset($statsColumnsMap[$selectedMetric]))
            $selectedMetric = 'code_lines';
        $metricConfig = $statsColumnsMap[$selectedMetric];
        $metricColumn = $metricConfig['column'];
        if (!preg_match('/^[a-z_]+$/', $metricColumn))
            die('Invalid metric');
        $isComplexityMetric = in_array($selectedMetric, ['cyclomatic_complexity', 'cognitive_complexity']);
        if (empty($selectedProjects))
            $error = "Please select at least one project";
        else
        {
            $isDeltaCalculation = ($dateFrom !== null || $dateTo !== null);
            if ($isDeltaCalculation) 
            {
                $baselineSubquery = "
                    SELECT c.project_id, s.language, MAX(c.id) as baseline_commit_id
                    FROM {$config['tables']['commits']} c
                    INNER JOIN {$config['tables']['statistics']} s ON c.id = s.commit_id
                    WHERE c.project_id IN (" . implode(',', array_fill(0, count($selectedProjects), '?')) . ")
                      AND c.processing_state = 'done'
                ";
                $baselineParams = $selectedProjects;
                if (!empty($selectedLanguages)) 
                {
                    $baselineSubquery .= " AND s.language IN (" . implode(',', array_fill(0, count($selectedLanguages), '?')) . ")";
                    $baselineParams = array_merge($baselineParams, $selectedLanguages);
                }
                if ($dateFrom) 
                {
                    $baselineSubquery .= " AND c.commit_timestamp < ?";
                    $baselineParams[] = $dateFrom . ' 00:00:00';
                }
                else
                    $baselineSubquery .= " AND 1=0";  // No baseline
                $baselineSubquery .= " GROUP BY c.project_id, s.language";
                $endSubquery = "
                    SELECT c.project_id, s.language, MAX(c.id) as end_commit_id
                    FROM {$config['tables']['commits']} c
                    INNER JOIN {$config['tables']['statistics']} s ON c.id = s.commit_id
                    WHERE c.project_id IN (" . implode(',', array_fill(0, count($selectedProjects), '?')) . ")
                      AND c.processing_state = 'done'
                ";
                $endParams = $selectedProjects;
                if (!empty($selectedLanguages)) 
                {
                    $endSubquery .= " AND s.language IN (" . implode(',', array_fill(0, count($selectedLanguages), '?')) . ")";
                    $endParams = array_merge($endParams, $selectedLanguages);
                }
                if ($dateFrom) 
                {
                    $endSubquery .= " AND c.commit_timestamp >= ?";
                    $endParams[] = $dateFrom . ' 00:00:00';
                }
                if ($dateTo) 
                {
                    $endSubquery .= " AND c.commit_timestamp <= ?";
                    $endParams[] = $dateTo . ' 23:59:59';
                }
                
                $endSubquery .= " GROUP BY c.project_id, s.language";
                $params = array_merge($endParams, $baselineParams);
                if ($viewMode === 'summary') 
                {
                    if ($isComplexityMetric) 
                    {
                        $sql = "
                            SELECT
                                s_end.language,
                                SUM(s_end.code_lines - COALESCE(s_base.code_lines, 0)) as total_code_lines,
                                SUM(s_end.total_lines - COALESCE(s_base.total_lines, 0)) as total_lines,
                                SUM(s_end.comment_lines - COALESCE(s_base.comment_lines, 0)) as total_comment_lines,
                                SUM(s_end.blank_lines - COALESCE(s_base.blank_lines, 0)) as total_blank_lines,
                                -- Delta in complexity: end rate minus baseline rate
                                CASE
                                    WHEN SUM(s_end.code_lines) > 0 THEN
                                        (SUM(s_end.{$metricColumn}) / (SUM(s_end.code_lines) / 1000.0))
                                    ELSE 0
                                END -
                                CASE
                                    WHEN SUM(COALESCE(s_base.code_lines, 0)) > 0 THEN
                                        (SUM(COALESCE(s_base.{$metricColumn}, 0)) / (SUM(COALESCE(s_base.code_lines, 0)) / 1000.0))
                                    ELSE 0
                                END as metric_value,
                                COUNT(DISTINCT p_end.id) as project_count
                            FROM ({$endSubquery}) end_commits
                            INNER JOIN {$config['tables']['statistics']} s_end
                                ON end_commits.end_commit_id = s_end.commit_id
                                AND end_commits.language = s_end.language
                            INNER JOIN {$config['tables']['commits']} c_end ON s_end.commit_id = c_end.id
                            INNER JOIN {$config['tables']['projects']} p_end ON c_end.project_id = p_end.id
                            LEFT JOIN ({$baselineSubquery}) base_commits
                                ON end_commits.project_id = base_commits.project_id
                                AND end_commits.language = base_commits.language
                            LEFT JOIN {$config['tables']['statistics']} s_base
                                ON base_commits.baseline_commit_id = s_base.commit_id
                                AND base_commits.language = s_base.language
                            GROUP BY s_end.language
                            ORDER BY ABS(metric_value) DESC
                        ";
                    } 
                    else 
                    {
                        $sql = "
                            SELECT
                                s_end.language,
                                SUM(s_end.code_lines - COALESCE(s_base.code_lines, 0)) as total_code_lines,
                                SUM(s_end.total_lines - COALESCE(s_base.total_lines, 0)) as total_lines,
                                SUM(s_end.comment_lines - COALESCE(s_base.comment_lines, 0)) as total_comment_lines,
                                SUM(s_end.blank_lines - COALESCE(s_base.blank_lines, 0)) as total_blank_lines,
                                SUM(s_end.{$metricColumn} - COALESCE(s_base.{$metricColumn}, 0)) as metric_value,
                                COUNT(DISTINCT p_end.id) as project_count
                            FROM ({$endSubquery}) end_commits
                            INNER JOIN {$config['tables']['statistics']} s_end
                                ON end_commits.end_commit_id = s_end.commit_id
                                AND end_commits.language = s_end.language
                            INNER JOIN {$config['tables']['commits']} c_end ON s_end.commit_id = c_end.id
                            INNER JOIN {$config['tables']['projects']} p_end ON c_end.project_id = p_end.id
                            LEFT JOIN ({$baselineSubquery}) base_commits
                                ON end_commits.project_id = base_commits.project_id
                                AND end_commits.language = base_commits.language
                            LEFT JOIN {$config['tables']['statistics']} s_base
                                ON base_commits.baseline_commit_id = s_base.commit_id
                                AND base_commits.language = s_base.language
                            GROUP BY s_end.language
                            ORDER BY ABS(metric_value) DESC
                        ";
                    }
                }
                else
                {
                    if ($isComplexityMetric) 
                    {
                        $sql = "
                            SELECT
                                p_end.name as project_name,
                                s_end.language,
                                s_end.code_lines - COALESCE(s_base.code_lines, 0) as total_code_lines,
                                s_end.total_lines - COALESCE(s_base.total_lines, 0) as total_lines,
                                s_end.comment_lines - COALESCE(s_base.comment_lines, 0) as total_comment_lines,
                                s_end.blank_lines - COALESCE(s_base.blank_lines, 0) as total_blank_lines,
                                -- Delta in complexity rate
                                CASE
                                    WHEN s_end.code_lines > 0 THEN
                                        (s_end.{$metricColumn} / (s_end.code_lines / 1000.0))
                                    ELSE 0
                                END -
                                CASE
                                    WHEN COALESCE(s_base.code_lines, 0) > 0 THEN
                                        (COALESCE(s_base.{$metricColumn}, 0) / (COALESCE(s_base.code_lines, 0) / 1000.0))
                                    ELSE 0
                                END as metric_value,
                                (SELECT COUNT(*)
                                 FROM {$config['tables']['commits']} c_range
                                 WHERE c_range.project_id = p_end.id
                                   AND c_range.processing_state = 'done'
                                   " . ($dateFrom ? "AND c_range.commit_timestamp >= '{$dateFrom} 00:00:00' " : "") . "
                                   " . ($dateTo ? "AND c_range.commit_timestamp <= '{$dateTo} 23:59:59' " : "") . "
                                ) as commit_count
                            FROM ({$endSubquery}) end_commits
                            INNER JOIN {$config['tables']['statistics']} s_end
                                ON end_commits.end_commit_id = s_end.commit_id
                                AND end_commits.language = s_end.language
                            INNER JOIN {$config['tables']['commits']} c_end ON s_end.commit_id = c_end.id
                            INNER JOIN {$config['tables']['projects']} p_end ON c_end.project_id = p_end.id
                            LEFT JOIN ({$baselineSubquery}) base_commits
                                ON end_commits.project_id = base_commits.project_id
                                AND end_commits.language = base_commits.language
                            LEFT JOIN {$config['tables']['statistics']} s_base
                                ON base_commits.baseline_commit_id = s_base.commit_id
                                AND base_commits.language = s_base.language
                            ORDER BY p_end.name, s_end.language
                        ";
                    }
                    else
                    {
                        $sql = "
                            SELECT
                                p_end.name as project_name,
                                s_end.language,
                                s_end.code_lines - COALESCE(s_base.code_lines, 0) as total_code_lines,
                                s_end.total_lines - COALESCE(s_base.total_lines, 0) as total_lines,
                                s_end.comment_lines - COALESCE(s_base.comment_lines, 0) as total_comment_lines,
                                s_end.blank_lines - COALESCE(s_base.blank_lines, 0) as total_blank_lines,
                                s_end.{$metricColumn} - COALESCE(s_base.{$metricColumn}, 0) as metric_value,
                                (SELECT COUNT(*)
                                 FROM {$config['tables']['commits']} c_range
                                 WHERE c_range.project_id = p_end.id
                                   AND c_range.processing_state = 'done'
                                   " . ($dateFrom ? "AND c_range.commit_timestamp >= '{$dateFrom} 00:00:00' " : "") . "
                                   " . ($dateTo ? "AND c_range.commit_timestamp <= '{$dateTo} 23:59:59' " : "") . "
                                ) as commit_count
                            FROM ({$endSubquery}) end_commits
                            INNER JOIN {$config['tables']['statistics']} s_end
                                ON end_commits.end_commit_id = s_end.commit_id
                                AND end_commits.language = s_end.language
                            INNER JOIN {$config['tables']['commits']} c_end ON s_end.commit_id = c_end.id
                            INNER JOIN {$config['tables']['projects']} p_end ON c_end.project_id = p_end.id
                            LEFT JOIN ({$baselineSubquery}) base_commits
                                ON end_commits.project_id = base_commits.project_id
                                AND end_commits.language = base_commits.language
                            LEFT JOIN {$config['tables']['statistics']} s_base
                                ON base_commits.baseline_commit_id = s_base.commit_id
                                AND base_commits.language = s_base.language
                            ORDER BY p_end.name, s_end.language
                        ";
                    }
                }
            }
            else
            {
                $latestCommitSubquery = "
                    SELECT c.project_id, s.language, MAX(c.id) as max_commit_id
                    FROM {$config['tables']['commits']} c
                    INNER JOIN {$config['tables']['statistics']} s ON c.id = s.commit_id
                    WHERE c.project_id IN (" . implode(',', array_fill(0, count($selectedProjects), '?')) . ")
                      AND c.processing_state = 'done'
                ";
                $params = $selectedProjects;
                if (!empty($selectedLanguages))
                {
                    $latestCommitSubquery .= " AND s.language IN (" . implode(',', array_fill(0, count($selectedLanguages), '?')) . ")";
                    $params = array_merge($params, $selectedLanguages);
                }
                $latestCommitSubquery .= " GROUP BY c.project_id, s.language";
                $latestCommitSubquery .= " GROUP BY c.project_id, s.language";
                if ($viewMode === 'summary')
                {
                    if ($isComplexityMetric) 
                    {
                        $sql = "
                            SELECT
                                s.language,
                                SUM(s.total_lines) as total_lines,
                                SUM(s.code_lines) as total_code_lines,
                                CASE
                                    WHEN SUM(s.code_lines) > 0 THEN
                                        (SUM(s.{$metricColumn}) / (SUM(s.code_lines) / 1000.0))
                                    ELSE 0
                                END as metric_value,
                                SUM(s.comment_lines) as total_comment_lines,
                                SUM(s.blank_lines) as total_blank_lines,
                                COUNT(DISTINCT p.id) as project_count
                            FROM {$config['tables']['statistics']} s
                            INNER JOIN (
                                $latestCommitSubquery
                            ) latest ON s.commit_id = latest.max_commit_id
                                     AND s.language = latest.language
                            JOIN {$config['tables']['commits']} c ON s.commit_id = c.id
                            JOIN {$config['tables']['projects']} p ON c.project_id = p.id
                            GROUP BY s.language
                            ORDER BY metric_value DESC
                        ";
                    }
                    else
                    {
                        $sql = "
                            SELECT
                                s.language,
                                SUM(s.total_lines) as total_lines,
                                SUM(s.code_lines) as total_code_lines,
                                SUM(s.{$metricColumn}) as metric_value,
                                SUM(s.comment_lines) as total_comment_lines,
                                SUM(s.blank_lines) as total_blank_lines,
                                COUNT(DISTINCT p.id) as project_count
                            FROM {$config['tables']['statistics']} s
                            INNER JOIN (
                                $latestCommitSubquery
                            ) latest ON s.commit_id = latest.max_commit_id
                                     AND s.language = latest.language
                            JOIN {$config['tables']['commits']} c ON s.commit_id = c.id
                            JOIN {$config['tables']['projects']} p ON c.project_id = p.id
                            GROUP BY s.language
                            ORDER BY metric_value DESC
                        ";
                    }
                }
                else
                {
                    if ($isComplexityMetric) 
                    {
                        $sql = "
                            SELECT
                                p.name as project_name,
                                s.language,
                                s.total_lines as total_lines,
                                s.code_lines as total_code_lines,
                                CASE
                                    WHEN s.code_lines > 0 THEN
                                        (s.{$metricColumn} / (s.code_lines / 1000.0))
                                    ELSE 0
                                END as metric_value,
                                s.comment_lines as total_comment_lines,
                                s.blank_lines as total_blank_lines,
                                1 as commit_count
                            FROM {$config['tables']['statistics']} s
                            INNER JOIN (
                                $latestCommitSubquery
                            ) latest ON s.commit_id = latest.max_commit_id
                                     AND s.language = latest.language
                            JOIN {$config['tables']['commits']} c ON s.commit_id = c.id
                            JOIN {$config['tables']['projects']} p ON c.project_id = p.id
                            ORDER BY p.name, s.language
                        ";
                    }
                    else
                    {
                        $sql = "
                            SELECT
                                p.name as project_name,
                                s.language,
                                s.total_lines as total_lines,
                                s.code_lines as total_code_lines,
                                s.{$metricColumn} as metric_value,
                                s.comment_lines as total_comment_lines,
                                s.blank_lines as total_blank_lines,
                                1 as commit_count
                            FROM {$config['tables']['statistics']} s
                            INNER JOIN (
                                $latestCommitSubquery
                            ) latest ON s.commit_id = latest.max_commit_id
                                     AND s.language = latest.language
                            JOIN {$config['tables']['commits']} c ON s.commit_id = c.id
                            JOIN {$config['tables']['projects']} p ON c.project_id = p.id
                            ORDER BY p.name, s.language
                        ";
                    }
                }
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}
catch (PDOException $e)
{
    $error = "Database error: " . $e->getMessage();
}

$totals = null;
if ($results !== null && !empty($results))
{
    $totals = [
        'total_code_lines' => 0,
        'total_comment_lines' => 0,
        'total_commits' => 0,
        'total_lines' => 0,
        'total_blank_lines' => 0
    ];
    
    foreach ($results as $row)
    {
        $totals['total_code_lines'] += $row['total_code_lines'] ?? 0;
        $totals['total_comment_lines'] += $row['total_comment_lines'] ?? 0;
        $totals['total_lines'] += $row['total_lines'] ?? 0;
        $totals['total_blank_lines'] += $row['total_blank_lines'] ?? 0;
        if (isset($row['commit_count']))
            $totals['total_commits'] += $row['commit_count'];
    }
    
    if ($viewMode === 'summary') 
    {
        try 
        {
            $commitCountSql = "
                SELECT COUNT(DISTINCT c.id) as total_commits
                FROM {$config['tables']['commits']} c
                WHERE c.project_id IN (" . implode(',', array_fill(0, count($selectedProjects), '?')) . ")
                  AND c.processing_state = 'done'
            ";
            $commitCountParams = $selectedProjects;
            if ($isDeltaCalculation) 
            {
                if ($dateFrom) 
                {
                    $commitCountSql .= " AND c.commit_timestamp >= ?";
                    $commitCountParams[] = $dateFrom . ' 00:00:00';
                }
                if ($dateTo) 
                {
                    $commitCountSql .= " AND c.commit_timestamp <= ?";
                    $commitCountParams[] = $dateTo . ' 23:59:59';
                }
            }
            
            $stmt = $pdo->prepare($commitCountSql);
            $stmt->execute($commitCountParams);
            $commitResult = $stmt->fetch(PDO::FETCH_ASSOC);
            $totals['total_commits'] = $commitResult['total_commits'] ?? 0;
        }
        catch (PDOException $e)
        {
            $totals['total_commits'] = 0;
        }
    }
}

function formatDeltaNumber($value, $isDelta, $decimals = 0)
{
    if (!$isDelta)
        return number_format($value, $decimals);
    $formatted = number_format(abs($value), $decimals);
    if ($value > 0)
        return '<span style="color: #28a745;">+' . $formatted . '</span>';
    elseif ($value < 0)
        return '<span style="color: #dc3545;">-' . $formatted . '</span>';
    else
        return $formatted;
}

$viewMode = isset($_POST['view_mode']) ? $_POST['view_mode'] : 'detail';
$isDeltaMode = isset($isDeltaCalculation) ? $isDeltaCalculation : false;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Query Data - CodePhacts</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .view-mode-selector {
            display: flex;
            gap: 15px;
            align-items: center;
            margin-bottom: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        .view-mode-selector label {
            font-weight: normal;
            margin: 0;
            cursor: pointer;
        }
        .filter-summary {
            background: #e7f3ff;
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 0.9em;
        }
        .filter-summary strong {
            color: #007bff;
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
            <h2>Query Code Statistics</h2>
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="view-mode-selector">
                    <strong>View Mode:</strong>
                    <label>
                        <input type="radio" name="view_mode" value="detail" <?= (!isset($_POST['view_mode']) || $_POST['view_mode'] === 'detail') ? 'checked' : '' ?>>
                        Detail (per-project breakdown)
                    </label>
                    <label>
                        <input type="radio" name="view_mode" value="summary" <?= (isset($_POST['view_mode']) && $_POST['view_mode'] === 'summary') ? 'checked' : '' ?>>
                        Summary (aggregated by language)
                    </label>
                </div>
                
                <label>Select Metric</label>
                <select name="metric" id="metric" style="margin-bottom: 15px;">
                    <?php 
                    $selectedMetricValue = isset($_POST['metric']) ? $_POST['metric'] : 'code_lines';
                    foreach ($statsColumnsMap as $key => $metricInfo): 
                    ?>
                        <option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" 
                            <?php echo ($selectedMetricValue === $key) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($metricInfo['label'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <label>Select Projects *</label>
                <div style="margin-bottom: 15px; max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 4px;">
                    <?php foreach ($projects as $project): ?>
                        <label style="display: block; font-weight: normal; margin-bottom: 5px;">
                            <input type="checkbox" name="projects[]" value="<?= $project['id'] ?>" 
                                <?= (isset($_POST['projects']) && in_array($project['id'], $_POST['projects'])) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($project['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                
                <label>Filter by Languages (optional)</label>
                <div style="margin-bottom: 15px; max-height: 150px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 4px;">
                    <?php foreach ($languages as $lang): ?>
                        <label style="display: block; font-weight: normal; margin-bottom: 5px;">
                            <input type="checkbox" name="languages[]" value="<?= $lang['language'] ?>"
                                <?= (isset($_POST['languages']) && in_array($lang['language'], $_POST['languages'])) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($lang['language']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                
                <label>Date From (optional)</label>
                <input type="date" name="date_from" value="<?= isset($_POST['date_from']) ? htmlspecialchars($_POST['date_from']) : '' ?>">
                
                <label>Date To (optional)</label>
                <input type="date" name="date_to" value="<?= isset($_POST['date_to']) ? htmlspecialchars($_POST['date_to']) : '' ?>">
                
                <button type="submit">Run Query</button>
            </form>
        </div>
        
        <?php if ($results !== null): ?>
            <?php if (!empty($selectedProjects) || !empty($selectedLanguages) || $dateFrom || $dateTo): ?>
                <div class="filter-summary">
                    <strong>Active Filters:</strong>
                    Metric: <strong><?= htmlspecialchars($metricConfig['label']) ?></strong>
                    <?php if (!empty($selectedProjects)): ?>
                        • <?= count($selectedProjects) ?> project(s) selected
                    <?php endif; ?>
                    <?php if (!empty($selectedLanguages)): ?>
                        • <?= count($selectedLanguages) ?> language(s) selected
                    <?php endif; ?>
                    <?php if ($dateFrom || $dateTo): ?>
                        • <strong>DELTA MODE:</strong> Date range: <?= $dateFrom ?: 'beginning' ?> to <?= $dateTo ?: 'present' ?>
                        <br>
                        <em style="font-size: 0.9em;">Showing changes (delta) between baseline (before <?= $dateFrom ?: 'beginning' ?>) and end of range. Negative values indicate decreases.</em>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <div class="card">
                <h2>Results</h2>
                <?php if (empty($results)): ?>
                    <p>No data found matching your criteria.</p>
                <?php else: ?>
                    <?php if ($totals): ?>
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 20px;">
                            <div style="text-align: center; padding: 20px; background: #e7f3ff; border-radius: 8px;">
                                <h3 style="font-size: 2em; margin: 0; color: <?= $isDeltaMode && $totals['total_code_lines'] < 0 ? '#dc3545' : '#007bff' ?>;">
                                    <?= $isDeltaMode && $totals['total_code_lines'] > 0 ? '+' : '' ?><?= number_format($totals['total_code_lines']) ?>
                                </h3>
                                <p><?= $isDeltaMode ? 'Code Lines Change' : 'Lines of Code' ?></p>
                            </div>
                            <div style="text-align: center; padding: 20px; background: #e7f3ff; border-radius: 8px;">
                                <h3 style="font-size: 2em; margin: 0; color: <?= $isDeltaMode && $totals['total_comment_lines'] < 0 ? '#dc3545' : '#007bff' ?>;">
                                    <?= $isDeltaMode && $totals['total_comment_lines'] > 0 ? '+' : '' ?><?= number_format($totals['total_comment_lines']) ?>
                                </h3>
                                <p><?= $isDeltaMode ? 'Comment Lines Change' : 'Comment Lines' ?></p>
                            </div>
                            <div style="text-align: center; padding: 20px; background: #e7f3ff; border-radius: 8px;">
                                <h3 style="font-size: 2em; margin: 0; color: <?= $isDeltaMode && $totals['total_commits'] < 0 ? '#dc3545' : '#007bff' ?>;">
                                    <?= $isDeltaMode && $totals['total_commits'] > 0 ? '+' : '' ?><?= number_format($totals['total_commits']) ?>
                                </h3>
                                <p><?= $isDeltaMode ? 'Commits in Range' : 'Commits' ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($viewMode === 'summary'): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Language</th>
                                    <th>Projects</th>
                                    <th><?= htmlspecialchars($metricConfig['label']) ?></th>
                                    <th>Code Lines</th>
                                    <th>Comment Lines</th>
                                    <th>Blank Lines</th>
                                    <th>Percentage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $row): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($row['language']) ?></strong></td>
                                        <td><?= $row['project_count'] ?></td>
                                        <td><?= formatDeltaNumber($row['metric_value'], $isDeltaMode, $isComplexityMetric ? 2 : 0) ?></td>
                                        <td><?= formatDeltaNumber($row['total_code_lines'], $isDeltaMode) ?></td>
                                        <td><?= formatDeltaNumber($row['total_comment_lines'], $isDeltaMode) ?></td>
                                        <td><?= formatDeltaNumber($row['total_blank_lines'], $isDeltaMode) ?></td>
                                        <td>
                                            <?php 
                                            if ($totals['total_code_lines'] != 0) {
                                                $percentage = ($row['total_code_lines'] / $totals['total_code_lines']) * 100;
                                                echo number_format(abs($percentage), 1) . '%';
                                            } else {
                                                echo '0.0%';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <div class="chart-container" style="height: 400px; margin-top: 30px;">
                            <canvas id="languageChart"></canvas>
                        </div>
                        
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Project</th>
                                    <th>Language</th>
                                    <th><?= htmlspecialchars($metricConfig['label']) ?></th>
                                    <th>Code Lines</th>
                                    <th>Comment Lines</th>
                                    <th>Blank Lines</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['project_name']) ?></td>
                                        <td><strong><?= htmlspecialchars($row['language']) ?></strong></td>
                                        <td><?= formatDeltaNumber($row['metric_value'], $isDeltaMode, $isComplexityMetric ? 2 : 0) ?></td>
                                        <td><?= formatDeltaNumber($row['total_code_lines'], $isDeltaMode) ?></td>
                                        <td><?= formatDeltaNumber($row['total_comment_lines'], $isDeltaMode) ?></td>
                                        <td><?= formatDeltaNumber($row['total_blank_lines'], $isDeltaMode) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <?php if ($viewMode === 'summary' && !empty($results)): ?>
    <script>
        const ctx = document.getElementById('languageChart');
        const metricLabel = <?= json_encode($metricConfig['label']) ?>;
        const isComplexity = <?= json_encode($isComplexityMetric) ?>;
        
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: <?= json_encode(array_column($results, 'language')) ?>,
                datasets: [{
                    label: metricLabel,
                    data: <?= json_encode(array_column($results, 'metric_value')) ?>,
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
                plugins: {
                    legend: {
                        position: 'right'
                    },
                    title: {
                        display: true,
                        text: metricLabel + ' by Language (Selected Projects)'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) 
                            {
                                let label = context.label || '';
                                if (label)
                                    label += ': ';
                                if (isComplexity)
                                    label += context.parsed.toFixed(2);
                                else
                                    label += context.parsed.toLocaleString();
                                return label;
                            }
                        }
                    }
                }
            }
        });
    </script>
    <?php endif; ?>
</body>
<footer style="text-align: center; padding: 20px; margin-top: 40px; font-size: 0.8em; color: #999;">
    <a href="../admin/login.php" style="color: #999; text-decoration: none;">admin</a>
</footer>
</html>