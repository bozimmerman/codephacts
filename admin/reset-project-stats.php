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
$config = require_once 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'db.php';

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$projectId = isset($input['project_id']) ? (int)$input['project_id'] : 0;

if ($projectId <= 0) 
{
    echo json_encode([
        'success' => false,
        'error' => 'Invalid project ID'
    ]);
    exit;
}

try {
    $pdo = getDatabase($config);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT name FROM {$config['tables']['projects']} WHERE id = ?");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'error' => 'Project not found'
        ]);
        exit;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM {$config['tables']['commits']} WHERE project_id = ?");
    $stmt->execute([$projectId]);
    $commitCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM {$config['tables']['statistics']} WHERE project_id = ?");
    $stmt->execute([$projectId]);
    $statsCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    $stmt = $pdo->prepare("DELETE FROM {$config['tables']['statistics']} WHERE project_id = ?");
    $stmt->execute([$projectId]);
    $stmt = $pdo->prepare("DELETE FROM {$config['tables']['commits']} WHERE project_id = ?");
    $stmt->execute([$projectId]);
    $stmt = $pdo->prepare("
        UPDATE {$config['tables']['projects']} 
        SET last_commit = NULL, last_updated = NULL 
        WHERE id = ?
    ");
    $stmt->execute([$projectId]);
    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => sprintf(
            'Successfully reset project "%s, Deleted %d commits and %d statistics records',
            $project['name'],
            $commitCount,
            $statsCount
        )
    ]);
    
} 
catch (PDOException $e)
{
    if (isset($pdo) && $pdo->inTransaction())
        $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} 
catch (Exception $e) 
{
    if (isset($pdo) && $pdo->inTransaction())
        $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}