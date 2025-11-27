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
/**
 * project processing
 */
$config = require 'config.php';

function outputProgress($type, $message, $data = []) 
{
    $progress = [
        'type' => $type,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    echo json_encode($progress) . "\n";
    /*
    $progressFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phactor_progress.json';
    $allProgress = [];
    if (file_exists($progressFile)) {
        $content = file_get_contents($progressFile);
        if ($content) {
            $allProgress = json_decode($content, true);
            if (!is_array($allProgress)) {
                $allProgress = [];
            }
        }
    }
    $allProgress[] = $progress;
    file_put_contents($progressFile, json_encode($allProgress));
    */
}

function canAccessRepo($sourceType, $sourceUrl)
{
    if ($sourceType === 'git')
    {
        $gitVersion = trim(shell_exec('git --version 2>&1'));
        if (stripos($gitVersion, 'git version') === false)
            return false;
        $cmd = "git ls-remote " . escapeshellarg($sourceUrl) . " HEAD 2>&1";
        $output = trim(shell_exec($cmd));
        if (empty($output) || stripos($output, 'fatal') !== false)
            return false;
        return true;
    }
    elseif ($sourceType === 'svn')
    {
        $svnVersion = trim(shell_exec('svn --version 2>&1'));
        if (stripos($svnVersion, 'svn, version') === false)
            return false;
        $cmd = "svn info " . escapeshellarg($sourceUrl) . " 2>&1";
        $output = trim(shell_exec($cmd));
        if (empty($output) || stripos($output, 'E170001') !== false || stripos($output, 'Unable to connect') !== false)
            return false;
        return true;
    }
    else
        throw new InvalidArgumentException("Unsupported source type: $sourceType");
}

function fetchCommits($sourceType, $sourceUrl, $lastCommit, &$cache = [])
{
    $cacheKey = $sourceUrl;
    if (isset($cache[$cacheKey]) && !empty($cache[$cacheKey]))
        return $cache[$cacheKey];
    $commits = [];
    if ($sourceType === 'git')
    {
        $tempRepo = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'git_log_' . md5($sourceUrl . microtime());
        $cloneCmd = "git clone --bare --quiet " . escapeshellarg($sourceUrl) . " " . escapeshellarg($tempRepo) . " 2>&1";
        $output = shell_exec($cloneCmd);
        if (!is_dir($tempRepo))
        {
            error_log("Failed to bare clone git repo: $sourceUrl - $output");
            return false;
        }
        try
        {
            $logCmd = "git --git-dir=" . escapeshellarg($tempRepo) . " log --format='%H|%ct'";
            if ($lastCommit)
                $logCmd .= " " . escapeshellarg($lastCommit) . "..HEAD";
            $logOutput = shell_exec($logCmd . " 2>&1");
            if (!$logOutput || empty(trim($logOutput)))
                return [];
                
            $lines = explode("\n", trim($logOutput));
            foreach ($lines as $line)
            {
                if (empty($line))
                    continue;
                $parts = explode('|', $line);
                if (count($parts) != 2)
                    continue;
                list($hash, $timestamp) = $parts;
                $commits[] = ['commit' => trim($hash), 'timestamp' => (int)$timestamp];
            }
            
            $result = array_reverse($commits);
            $cache[$cacheKey] = $result;
            return $result;
        }
        finally
        {
            rrmdir($tempRepo);
        }
    }
    elseif ($sourceType === 'svn')
    {
        $startRev = $lastCommit ? ((int)$lastCommit + 1) : 1;
        $cmd = "svn log " . escapeshellarg($sourceUrl) . " -r {$startRev}:HEAD --limit 100 --xml --quiet 2>&1";
        $output = shell_exec($cmd);
        if (stripos($output, 'E160006') !== false || stripos($output, 'No such revision') !== false)
        {
            $cmd = "svn log " . escapeshellarg($sourceUrl) . " --limit 100 --xml --quiet 2>&1";
            $output = shell_exec($cmd);
        }
        if (!$output || (stripos($output, 'E') === 0 && stripos($output, 'E160006') === false))
        {
            error_log("Failed to fetch SVN log: $output");
            return false;
        }
        $xml = @simplexml_load_string($output);
        if ($xml === false)
        {
            error_log("Failed to parse SVN XML output");
            error_log("SVN XML output: " . substr($output, 0, 500));
            return false;
        }
        
        $allRevisions = [];
        foreach ($xml->logentry as $entry)
        {
            $revision = (string)$entry['revision'];
            $timestamp = strtotime((string)$entry->date);
            if ($lastCommit && (int)$revision <= (int)$lastCommit)
                continue;
            $allRevisions[] = ['commit' => $revision, 'timestamp' => $timestamp];
        }
        usort($allRevisions, function($a, $b) {
            return (int)$a['commit'] - (int)$b['commit'];
        });
        $cache[$cacheKey] = $allRevisions;
        return $allRevisions;
    }
    return false;
}

function getNextUnprocessedCommit($projectId, $sourceType, $sourceUrl, $includeCommitsOlderThan, &$cache = []) 
{
    global $pdo, $config;
    
    $commitsTable = $config['tables']['commits'] ?? 'commits';
    $statsTable = $config['tables']['statistics'] ?? 'statistics';
    $stmt = $pdo->prepare("
        SELECT c.commit_hash
        FROM `$commitsTable` c
        INNER JOIN `$statsTable` s ON s.commit_id = c.id
        WHERE c.project_id = ?
        ORDER BY c.commit_timestamp DESC
        LIMIT 1
    ");
    $stmt->execute([$projectId]);
    $lastCompleted = $stmt->fetchColumn();

    $newCommits = fetchCommits($sourceType, $sourceUrl, $lastCompleted, $cache);
    if ($newCommits === false || empty($newCommits))
        return null;
    $stmt = $pdo->prepare("
        SELECT c.commit_hash,
               UNIX_TIMESTAMP(c.processed_at) as processed_timestamp,
               (SELECT COUNT(*) FROM `$statsTable` s WHERE s.commit_id = c.id) as has_stats
        FROM `$commitsTable` c
        WHERE c.project_id = ?
    ");
    $stmt->execute([$projectId]);
    $tracked = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) 
    {
        $tracked[$row['commit_hash']] = [
            'processed_at' => $row['processed_timestamp'],
            'has_stats' => $row['has_stats'] > 0
        ];
    }
    $now = time();
    $staleThreshold = $now - $includeCommitsOlderThan;
    foreach ($newCommits as $commit) 
    {
        $commitHash = $commit['commit'];
        $cacheKey = $sourceUrl;
        if (isset($cache[$cacheKey]))
            array_shift($cache[$cacheKey]);
        if (!isset($tracked[$commitHash]))
            return $commit;
        $info = $tracked[$commitHash];
        if (!$info['has_stats'])
            return $commit;

        if ($info['processed_at']
        && $info['processed_at'] < $staleThreshold)
            return $commit;
    }
    return null;
}

function fetchCommitCode($commit, $sourceType, $sourceUrl, $tempDir)
{
    if ($sourceType === 'git')
    {
        $cloneCmd = "git clone --quiet " . escapeshellarg($sourceUrl) . " " . escapeshellarg($tempDir) . " 2>&1";
        $output = shell_exec($cloneCmd);
        if (!is_dir($tempDir . DIRECTORY_SEPARATOR . '.git'))
        {
            error_log("Failed to clone git repo to temp dir: $output");
            return false;
        }
        $checkoutCmd = "cd " . escapeshellarg($tempDir) . " && git checkout --quiet " . escapeshellarg($commit['commit']) . " 2>&1";
        $output = shell_exec($checkoutCmd);
        if ($output && stripos($output, 'error:') !== false)
        {
            error_log("Failed to checkout commit: $output");
            return false;
        }
        return true;
    }
    elseif ($sourceType === 'svn')
    {
        $revision = $commit['commit'];
        $checkoutCmd = "svn checkout " . escapeshellarg($sourceUrl) . " " . escapeshellarg($tempDir) . " -r " . escapeshellarg($revision) . " --quiet 2>&1";
        $output = shell_exec($checkoutCmd);
        if ($output && (stripos($output, 'E') === 0 || stripos($output, 'svn:') !== false))
        {
            error_log("Failed to checkout SVN revision: $output");
            return false;
        }
        return true;
    }
    return false;
}

function processProject($tempDir, $excludedDirs)
{
    global $rules;
    $report = [];
    $excludedDirs = array_map(function($dir)
    {
        return DIRECTORY_SEPARATOR . trim($dir, DIRECTORY_SEPARATOR);
    }, $excludedDirs);

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($files as $file)
    {
        if (!$file->isFile())
            continue;
        $pathname = $file->getPathname();
        if (strpos($pathname, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR ) !== false 
        || strpos($pathname, DIRECTORY_SEPARATOR . '.svn' . DIRECTORY_SEPARATOR ) !== false)
            continue;
        $relativePath = str_replace($tempDir, '', $pathname);
        $shouldSkip = false;
        foreach ($excludedDirs as $excludedDir)
        {
            // Check if the relative path starts with the excluded directory
            if (strpos($relativePath, $excludedDir . DIRECTORY_SEPARATOR) === 0)
            {
                $shouldSkip = true;
                break;
            }
        }
        if ($shouldSkip)
            continue;
        $ext = strtolower($file->getExtension());
        if (!isset($rules[$ext]))
            continue;
        processFile($file->getPathname(), $ext, $report);
    }
    return $report;
}

function processFile($filePath, $ext, &$report)
{
    global $rules;
    $content = file_get_contents($filePath);
    if ($content === false)
        return false;
    $lines = explode("\n", $content);
    $rule = $rules[$ext];
    $candidates = [
        [ "ext" => $ext, "rule" => $rule, "lines" => $lines]
    ];
    $analyzers = [];
    while (count($candidates) > 0)
    {
        $candidate = array_pop($candidates);
        $rule = $candidate['rule'];
        $lines = $candidate['lines'];
        $detector = isset($rule['detector']) ? $rule['detector'] : null;
        if ($detector)
        {
            $detections = $detector($lines); // might alter lines if it wants to?
            if ($detections)
            {
                foreach($detections as $detect)
                {
                    if ($detect['ext'] && $detect['lines'] && $rules[$detect['ext']])
                    {
                        $ext = $detect['ext'];
                        $newRule = $rules[$ext];
                        $lines = $detect['lines'];
                        $candidates[] = [ "ext" => $ext, "rule" => $newRule, "lines" => $lines];
                    }
                }
            }
            else
                $analyzers[] = $candidate;
        }
        else 
            $analyzers[] = $candidate;
        
    }
    foreach ($analyzers as $analyzer)
    {
        $lang = $analyzer['ext'];
        $lines = $analyzer['lines'];
        $rule = $analyzer['rule'];
        $stats = analyzeFile($rule, $lines);
        if (!isset($report[$lang]))
            $report[$lang] = [
                'total_lines' => 0,
                'code_lines' => 0,
                'code_statements' => 0,
                'weighted_code_statements' => 0,
                'weighted_code_lines' => 0,
                'blank_lines' => 0,
                'comment_lines' => 0,
                'ncloc' => 0,
            ];
        foreach ($stats as $key => $value)
            $report[$lang][$key] += $value;
    }
}

function analyzeFile($ruleInfo, $lines)
{
    $stats = [
        'total_lines' => count($lines),
        'code_lines' => 0,
        'code_statements' => 0,
        'weighted_code_statements' => 0,
        'weighted_code_lines' => 0,
        'blank_lines' => 0,
        'comment_lines' => 0,
        'ncloc' => 0
    ];
    $ruleInfo['analyzer']($stats, $lines);
    return $stats;
}

function updateStatistics($projectId, $commitId, $report)
{
    global $config, $pdo;
    
    $statsTable = isset($config['tables']['statistics']) ? $config['tables']['statistics'] : 'statistics';
    
    foreach ($report as $lang => $stats)
    {
        $stmt = $pdo->prepare("
            INSERT INTO `$statsTable`
            (project_id, commit_id, language, total_lines, code_lines, code_statements,
             weighted_code_statements, weighted_code_lines, blank_lines, comment_lines, updated_at, ncloc)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE
            total_lines = VALUES(total_lines),
            code_lines = VALUES(code_lines),
            code_statements = VALUES(code_statements),
            weighted_code_statements = VALUES(weighted_code_statements),
            weighted_code_lines = VALUES(weighted_code_lines),
            blank_lines = VALUES(blank_lines),
            comment_lines = VALUES(comment_lines),
            updated_at = NOW(),
            ncloc = VALUES(ncloc)
        ");
        
        $stmt->execute([
            $projectId,
            $commitId,
            $lang,
            $stats['total_lines'],
            $stats['code_lines'],
            $stats['code_statements'],
            $stats['weighted_code_statements'],
            $stats['weighted_code_lines'],
            $stats['blank_lines'],
            $stats['comment_lines'],
            $stats['ncloc']
        ]);
    }
}

function updateProjectLastCommit($projectId, $latestCommit)
{
    global $config, $pdo;
    
    $projectsTable = $config['tables']['projects'];
    
    $stmt = $pdo->prepare("
        UPDATE `$projectsTable`
        SET last_commit = ?, last_updated = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$latestCommit, $projectId]);
}

function tryClaimCommit($projectId, $commit) 
{
    global $pdo, $config;
    $commitsTable = $config['tables']['commits'] ?? 'commits';
    try 
    {
        $commitHash = $commit['commit'];
        $timestamp = $commit['timestamp'];
        $stmt = $pdo->prepare("
            INSERT INTO `$commitsTable`
            (project_id, commit_hash, commit_timestamp, processed_at)
            VALUES (?, ?, FROM_UNIXTIME(?), NOW())
        ");
        $stmt->execute([$projectId, $commitHash, $timestamp]);
        return $pdo->lastInsertId();
    } 
    catch (PDOException $e) 
    {
        if ($e->getCode() == 23000)
            return false;
        throw $e; // Some other error
    }
}

function rrmdir($dir)
{
    if (!is_dir($dir))
        return;
        
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file)
        {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            
            if (is_dir($path))
            {
                rrmdir($path);
            }
            else
            {
                @chmod($path, 0777);
                @unlink($path);
            }
        }
        
        @chmod($dir, 0777);
        @rmdir($dir);
}

$rules = [];
$ruleFiles = glob(__DIR__ . DIRECTORY_SEPARATOR . 'rules' . DIRECTORY_SEPARATOR . '*.php');
if ($ruleFiles === false)
    $ruleFiles = [];
foreach ($ruleFiles as $ruleFile)
{
    $basename = basename($ruleFile);
    if (strpos($basename, 'c_style_') === 0 || strpos($basename, '_') === 0)
        continue;
    $rule = require $ruleFile;
    foreach ($rule['extensions'] as $ext) 
    {
        $rules[$ext] = [
            'language' => $rule['language'],
            'analyzer' => $rule['analyzer'],
            'detector' => isset($rule['detector']) ? $rule['detector'] : null
        ];
    }
}

function processCommitForProject($project, $commit, $commitId) 
{
    outputProgress('commit_start', "Processing commit: {$commit['commit']}", [
        'project' => $project['name'],
        'commit' => $commit['commit']
    ]);
    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cp_' . getmypid() . '_' . $project['id'] . '_' . substr($commit['commit'], 0, 8);
    mkdir($tempDir);
    try 
    {
        $chk = fetchCommitCode( $commit, $project['source_type'], $project['source_url'], $tempDir );
        if ($chk === false)
        {
            error_log("Failed to checkout commit {$commit['commit']} for {$project['name']}");
            outputProgress('error', "Failed to checkout commit");
            return false;
        }
        $excludedDirs = [];
        if (!empty($project['excluded_dirs']))
        {
            $excludedDirs = is_array($project['excluded_dirs'])
            ? $project['excluded_dirs']
            : explode(',', $project['excluded_dirs']);
        }
        $rpt = processProject($tempDir, $excludedDirs);
        if ($rpt === false) 
        {
            error_log("Failed to process files for {$project['name']}");
            outputProgress('error', "Failed to process files");
            return false;
        }
        // update statistics and mark as complete
        updateStatistics($project['id'], $commitId, $rpt);
        updateProjectLastCommit($project['id'], $commit['commit']);
        outputProgress('commit_complete', "Completed commit", [
            'project' => $project['name'],
            'commit' => $commit['commit'],
            'languages' => array_keys($rpt)
        ]);
        return true;
    }
    finally 
    {
        rrmdir($tempDir);
    }
}

function findCommitWithoutStats($projectId)
{
    global $pdo, $config;
    $commitsTable = $config['tables']['commits'] ?? 'commits';
    $statsTable = $config['tables']['statistics'] ?? 'statistics';
    $stmt = $pdo->prepare("
        SELECT c.id, c.commit_hash as commit, UNIX_TIMESTAMP(c.commit_timestamp) as timestamp
        FROM `$commitsTable` c
        LEFT JOIN `$statsTable` s ON s.commit_id = c.id
        WHERE c.project_id = ?
        AND s.id IS NULL
        ORDER BY c.commit_timestamp ASC
        LIMIT 1
    ");
    $stmt->execute([$projectId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row)
        return null;
    return [
        'id' => $row['id'],
        'commit' => ['commit' => $row['commit'], 'timestamp' => $row['timestamp']]
    ];
}

function tryProcessNextCommitForProject($project, $stale_timeout, &$cache = [])
{
    if (!canAccessRepo($project['source_type'], $project['source_url']))
    {
        outputProgress('project_skip', "Cannot access repository for {$project['name']}");
        return false;
    }
    $commit = getNextUnprocessedCommit($project['id'], $project['source_type'], $project['source_url'], $stale_timeout, $cache );
    if (!$commit)
    {
        $orphanedCommit = findCommitWithoutStats($project['id']);
        if ($orphanedCommit)
            return processCommitForProject($project, $orphanedCommit['commit'], $orphanedCommit['id']);
        return false;
    }
    $commitId = tryClaimCommit($project['id'], $commit);
    if ($commitId === false) 
    {
        outputProgress('commit_race', "Commit {$commit['commit']} claimed by another process");
        array_shift($cache[$project['source_url']]);
        return true; // There was work, just not for us - try again
    }
    return processCommitForProject($project, $commit, $commitId);
}

// Main processing loop
try 
{
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}",
        $config['db']['user'],
        $config['db']['pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $projectsTable = $config['tables']['projects'];
    $completedProjects = [];
    $workFound = true;
    $loopCount = 0;
    $cache = [];
    while ($workFound) 
    {
        $loopCount++;
        $workFound = false;
        outputProgress('loop_start', "Starting processing loop #{$loopCount}");
        $stmt = $pdo->prepare("
            SELECT id, name, source_type, source_url, last_commit, excluded_dirs
            FROM `$projectsTable`
            ORDER BY id ASC
        ");
        $stmt->execute();
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($projects as $project) 
        {
            if (in_array($project['id'], $completedProjects))
                continue;
            try
            {
                if (tryProcessNextCommitForProject($project, $config['stale_timeout'], $cache))
                    $workFound = true;
                else
                    $completedProjects[] = $project['id'];
            }
            catch (Exception $e) 
            {
                error_log("Error processing {$project['name']}: {$e->getMessage()}");
                outputProgress('error', "Error: {$e->getMessage()}");
            }
        }
        if (!$workFound)
            outputProgress('all_complete', "No more work available. Processed {$loopCount} loops.");
    }
} 
catch (PDOException $e) 
{
    die("Database error: " . $e->getMessage());
}
outputProgress('all_complete', "All projects processed.");