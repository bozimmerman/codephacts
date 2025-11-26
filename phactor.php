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
 * offline processing
 */
$config = require 'config.php';

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
        if (!is_dir($tempDir . '/.git'))
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
        return '/' . trim($dir, '/');
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
        if (strpos($pathname, '/.git/') !== false || strpos($pathname, '/.svn/') !== false)
            continue;
        $relativePath = str_replace($tempDir, '', $pathname);
        $shouldSkip = false;
        foreach ($excludedDirs as $excludedDir)
        {
            // Check if the relative path starts with the excluded directory
            if (strpos($relativePath, $excludedDir . '/') === 0 ||
                strpos($relativePath, $excludedDir) === 0)
            {
                $shouldSkip = true;
                break;
            }
        }
        if ($shouldSkip)
            continue;
        $ext = strtolower($file->getExtension());
        $lang = $rules[$ext] ?? null;
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
        $detector = $rule['detector'] ?? null;
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

function updateStatistics($projectId, $report)
{
    global $config, $pdo;
    
    $statsTable = $config['tables']['statistics'] ?? 'project_statistics';
    
    foreach ($report as $lang => $stats)
    {
        $stmt = $pdo->prepare("
            INSERT INTO `$statsTable`
            (project_id, language, total_lines, code_lines, code_statements,
             weighted_code_statements, weighted_code_lines, blank_lines, comment_lines, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
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

function updateCommitStatistics($projectId, $newCommits)
{
    global $config, $pdo;
    $commitsTable = $config['tables']['commits'] ?? 'project_commits';
    foreach ($newCommits as $commit)
    {
        $commitHash = $commit['commit'];
        $timestamp = $commit['timestamp'];
        $stmt = $pdo->prepare("
            INSERT INTO `$commitsTable` (project_id, commit_hash, commit_timestamp, processed_at)
            VALUES (?, ?, FROM_UNIXTIME(?), NOW())
            ON DUPLICATE KEY UPDATE processed_at = NOW()
        ");
        
        $stmt->execute([$projectId, $commitHash, $timestamp]);
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

function rrmdir($dir)
{
    if (!is_dir($dir))
        return;
        
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file)
        {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? rrmdir($path) : unlink($path);
        }
        
        rmdir($dir);
}

$rules = [];
foreach (glob(__DIR__ . '/rules/*.php') as $ruleFile) {
    $rule = require $ruleFile; // require returns the value from the file
    foreach ($rule['extensions'] as $ext) {
        $rules[$ext] = [
            'language' => $rule['language'],
            'analyzer' => $rule['analyzer']
        ];
    }
}

try {
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
        try 
        {
            if (!canAccessRepo($project['source_type'], $project['source_url']))
                continue;
            if (!projectNeedsUpdate($project['source_type'], $project['source_url'], $project['last_commit']))
                continue;
                    
            $newCommits = fetchCommits($project['source_type'], $project['source_url'], $project['last_commit']);
                    
            if ($newCommits === false)
            {
                error_log("Failed to download commits for {$project['name']}");
                continue;
            }
                    
            foreach ($newCommits as $commit)
            {
                $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cp_' . md5($project['source_url'] . microtime());
                mkdir($tempDir);
                
                try
                {
                    $chk = fetchCommitCode($commit, $project['source_type'], $project['source_url'], $project['last_commit'], $tempDir);
                    if ($chk === false)
                    {
                        error_log("Failed to process commit for {$project['name']}");
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
                        continue;
                    }
                    
                    updateStatistics($project['id'], $rpt);
                }
                finally
                {
                    rrmdir($tempDir);
                }
            }
            updateCommitStatistics($project['id'], $newCommits);
            $latestCommit = end($newCommits)['commit'] ?? end($newCommits)['revision'];
            updateProjectLastCommit($project['id'], $latestCommit);
                    
        } 
        catch (Exception $e) 
        {
            error_log("Error processing {$project['name']}: {$e->getMessage()}");
        }
    }
    
} 
catch (PDOException $e) 
{
    die("Database error: " . $e->getMessage());
}