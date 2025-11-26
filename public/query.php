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

$results = null;
$error = null;

try
{
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}",
        $config['db']['user'],
        $config['db']['pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
        
        if (empty($selectedProjects))
            $error = "Please select at least one project";
        else 
        {
            $sql = "
                SELECT 
                    p.name as project_name,
                    s.language,
                    SUM(s.code_lines) as total_code_lines,
                    SUM(s.comment_lines) as total_comment_lines,
                    COUNT(DISTINCT c.id) as commit_count
                FROM {$config['tables']['statistics']} s
                JOIN {$config['tables']['commits']} c ON s.commit_id = c.id
                JOIN {$config['tables']['projects']} p ON c.project_id = p.id
                WHERE p.id IN (" . implode(',', array_fill(0, count($selectedProjects), '?')) . ")
            ";
            $params = $selectedProjects;
            if (!empty($selectedLanguages)) 
            {
                $sql .= " AND s.language IN (" . implode(',', array_fill(0, count($selectedLanguages), '?')) . ")";
                $params = array_merge($params, $selectedLanguages);
            }
            if ($dateFrom) 
            {
                $sql .= " AND c.commit_timestamp >= ?";
                $params[] = $dateFrom . ' 00:00:00';
            }
            if ($dateTo) 
            {
                $sql .= " AND c.commit_timestamp <= ?";
                $params[] = $dateTo . ' 23:59:59';
            }
            $sql .= " GROUP BY p.id, p.name, s.language ORDER BY p.name, s.language";
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
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Query Data - CodePhacts</title>
    <link rel="stylesheet" href="style.css">
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
            <h2>Query Code Statistics</h2>
            <p>Ask questions like: "How much Java have I written across these 3 projects in the last year?"</p>
            
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <form method="POST">
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
            <div class="card">
                <h2>Results</h2>
                <?php if (empty($results)): ?>
                    <p>No data found matching your criteria.</p>
                <?php else: ?>
                    <?php
                    $totalCode = array_sum(array_column($results, 'total_code_lines'));
                    $totalComments = array_sum(array_column($results, 'total_comment_lines'));
                    $totalCommits = array_sum(array_column($results, 'commit_count'));
                    ?>
                    
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 20px;">
                        <div style="text-align: center; padding: 20px; background: #e7f3ff; border-radius: 8px;">
                            <h3 style="font-size: 2em; margin: 0; color: #007bff;"><?= number_format($totalCode ?? 0) ?></h3>
                            <p>Lines of Code</p>
                        </div>
                        <div style="text-align: center; padding: 20px; background: #e7f3ff; border-radius: 8px;">
                            <h3 style="font-size: 2em; margin: 0; color: #007bff;"><?= number_format($totalComments ?? 0) ?></h3>
                            <p>Comment Lines</p>
                        </div>
                        <div style="text-align: center; padding: 20px; background: #e7f3ff; border-radius: 8px;">
                            <h3 style="font-size: 2em; margin: 0; color: #007bff;"><?= number_format($totalCommits ?? 0) ?></h3>
                            <p>Commits</p>
                        </div>
                    </div>
                    
                    <table>
                        <thead>
                            <tr>
                                <th>Project</th>
                                <th>Language</th>
                                <th>Code Lines</th>
                                <th>Comment Lines</th>
                                <th>Commits</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['project_name']) ?></td>
                                    <td><strong><?= htmlspecialchars($row['language']) ?></strong></td>
                                    <td><?= number_format($row['total_code_lines'] ?? 0) ?></td>
                                    <td><?= number_format($row['total_comment_lines'] ?? 0) ?></td>
                                    <td><?= number_format($row['commit_count'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
<footer style="text-align: center; padding: 20px; margin-top: 40px; font-size: 0.8em; color: #999;">
    <a href="../admin/login.php" style="color: #999; text-decoration: none;">admin</a>
</footer>
</html>