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

function projectNeedsUpdate($sourceType, $sourceUrl, $lastCommit)
{
    if ($sourceType === 'git')
    {
        $cmd = "git ls-remote " . escapeshellarg($sourceUrl) . " HEAD";
        $output = trim(shell_exec($cmd));
        if (!$output)
            return true;
        $parts = preg_split('/\s+/', $output);
        $latestCommit = $parts[0];
        return ($latestCommit !== $lastCommit);
    }
    elseif ($sourceType === 'svn')
    {
        $cmd = "svn info " . escapeshellarg($sourceUrl) . " --show-item revision";
        $latestRevision = trim(shell_exec($cmd));
        if (!$latestRevision)
            return true;
        return ($latestRevision != $lastCommit);
    }
    else
        throw new InvalidArgumentException("Unsupported source type: $sourceType");
}

function fetchCommits($sourceType, $sourceUrl, $lastCommit)
{
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
            
            return array_reverse($commits);
        }
        finally
        {
            rrmdir($tempRepo);
        }
    }
    elseif ($sourceType === 'svn')
    {
        $startRev = $lastCommit ? ((int)$lastCommit + 1) : 1;
        $cmd = "svn log " . escapeshellarg($sourceUrl) . " -r {$startRev}:HEAD --xml --quiet 2>&1";
        $output = shell_exec($cmd);
        if (!$output || stripos($output, 'E') === 0)
        {
            error_log("Failed to fetch SVN log: $output");
            return false;
        }
        $xml = @simplexml_load_string($output);
        if ($xml === false)
        {
            error_log("Failed to parse SVN XML output");
            return false;
        }
        foreach ($xml->logentry as $entry)
        {
            $revision = (string)$entry['revision'];
            $timestamp = strtotime((string)$entry->date);
            $commits[] = ['commit' => $revision, 'commit' => $revision, 'timestamp' => $timestamp];
        }
        return $commits;
    }
    
    return false;
}

function fetchCommitCode($commit, $sourceType, $sourceUrl, $lastCommit, $tempDir)
{
    if ($sourceType === 'git')
    {
        $cloneCmd = "git clone --quiet " . escapeshellarg($sourceUrl) . " " . escapeshellarg($tempDir) . " 2>&1";
        $output = shell_exec($cloneCmd);
        if (!is_dir($tempDir . DIRECTORY_SEPARATOR  . '.git'))
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
        if (strpos($pathname, DIRECTORY_SEPARATOR  . '.git' . DIRECTORY_SEPARATOR ) !== false 
        || strpos($pathname, DIRECTORY_SEPARATOR  . '.svn' . DIRECTORY_SEPARATOR ) !== false)
            continue;
        $relativePath = str_replace($tempDir, '', $pathname);
        $shouldSkip = false;
        foreach ($excludedDirs as $excludedDir)
        {
            // Check if the relative path starts with the excluded directory
            if (strpos($relativePath, $excludedDir . DIRECTORY_SEPARATOR) === 0 ||
                strpos($relativePath, $excludedDir) === 0)
            {
                $shouldSkip = true;
                break;
            }
        }
        if ($shouldSkip)
            continue;
        $ext = strtolower($file->getExtension());
        $lang = isset($rules[$ext]) ? $rules[$ext] : null;
        if (!$lang)
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
                'comment_lines' => 0
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
        'comment_lines' => 0
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
             weighted_code_statements, weighted_code_lines, blank_lines, comment_lines, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
            total_lines = VALUES(total_lines),
            code_lines = VALUES(code_lines),
            code_statements = VALUES(code_statements),
            weighted_code_statements = VALUES(weighted_code_statements),
            weighted_code_lines = VALUES(weighted_code_lines),
            blank_lines = VALUES(blank_lines),
            comment_lines = VALUES(comment_lines),
            updated_at = NOW()
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
            $stats['comment_lines']
        ]);
    }
}

function updateCommitStatistics($projectId, $commit)
{
    global $config, $pdo;
    $commitsTable = $config['tables']['commits'] ?? 'project_commits';
    
    $commitHash = $commit['commit'];
    $timestamp = $commit['timestamp'];
    $stmt = $pdo->prepare("
        INSERT INTO `$commitsTable` (project_id, commit_hash, commit_timestamp, processed_at)
        VALUES (?, ?, FROM_UNIXTIME(?), NOW())
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), processed_at = NOW()
    ");
    
    $stmt->execute([$projectId, $commitHash, $timestamp]);
    return $pdo->lastInsertId();
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
            'analyzer' => $rule['analyzer']
        ];
    }
}

try
{
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}",
        $config['db']['user'],
        $config['db']['pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $projectsTable = $config['tables']['projects'];

    $stmt = $pdo->prepare("
        SELECT id, name, source_type, source_url, last_commit, excluded_dirs
        FROM `$projectsTable`
        ORDER BY id ASC
    ");
    $stmt->execute();
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($projects as $project) 
    {
        outputProgress('project_start', "Starting project: {$project['name']}", ['project_id' => $project['id']]);
        try 
        {
            if (!canAccessRepo($project['source_type'], $project['source_url']))
            {
                outputProgress('project_skip', "Cannot access repository for {$project['name']}");
                continue;
            }
            if (!projectNeedsUpdate($project['source_type'], $project['source_url'], $project['last_commit']))
            {
                outputProgress('project_skip', "No updates needed for {$project['name']}");
                continue;
            }
                    
            $newCommits = fetchCommits($project['source_type'], $project['source_url'], $project['last_commit']);
                    
            if ($newCommits === false)
            {
                outputProgress('error', "Failed to download commits for {$project['name']}");
                error_log("Failed to download commits for {$project['name']}");
                continue;
            }
            $commitCount = count($newCommits);
            outputProgress('commits_found', "Found {$commitCount} new commits for {$project['name']}", ['count' => $commitCount]);
            $processedCount = 0;
            foreach ($newCommits as $commit)
            {
                $processedCount++;
                outputProgress('commit_start', "Processing commit {$processedCount}/{$commitCount}: {$commit['commit']}", [
                    'current' => $processedCount,
                    'total' => $commitCount,
                    'commit' => $commit['commit']
                ]);
                $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cp_' . md5($project['source_url'] . microtime());
                mkdir($tempDir);
                
                try
                {
                    $chk = fetchCommitCode($commit, $project['source_type'], $project['source_url'], $project['last_commit'], $tempDir);
                    if ($chk === false)
                    {
                        error_log("Failed to process commit for {$project['name']}");
                        outputProgress('error', "Failed to checkout commit {$commit['commit']}");
                        continue;
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
                        outputProgress('error', "Failed to process files for commit {$commit['commit']}");
                        continue;
                    }
                    $commitId = updateCommitStatistics($project['id'], $commit);
                    updateStatistics($project['id'], $commitId, $rpt);
                    updateProjectLastCommit($project['id'], $commit['commit']);
                    
                    outputProgress('commit_complete', "Completed commit {$processedCount}/{$commitCount}", [
                        'current' => $processedCount,
                        'total' => $commitCount,
                        'languages' => array_keys($rpt)
                    ]);
                }
                finally
                {
                    rrmdir($tempDir);
                }
            }
            outputProgress('project_complete', "Completed project: {$project['name']}", [
                'commits_processed' => $commitCount
            ]);
        } 
        catch (Exception $e) 
        {
            error_log("Error processing {$project['name']}: {$e->getMessage()}");
            outputProgress('error', "Error processing {$project['name']}: {$e->getMessage()}");
        }
    }
    
} 
catch (PDOException $e) 
{
    die("Database error: " . $e->getMessage());
}
outputProgress('all_complete', "All projects processed.");