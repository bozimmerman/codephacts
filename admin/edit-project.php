<?php
$config = require_once 'auth.php';

$message = null;
$error = null;
$project = [
    'id' => null,
    'name' => '',
    'source_type' => 'git',
    'source_url' => '',
    'excluded_dirs' => ''
];

try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}",
        $config['db']['user'],
        $config['db']['pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Load existing project if editing
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM {$config['tables']['projects']} WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $loaded = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($loaded) {
            $project = $loaded;
        }
    }
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim($_POST['name']);
        $source_type = $_POST['source_type'];
        $source_url = trim($_POST['source_url']);
        $excluded_dirs = trim($_POST['excluded_dirs']);
        
        if (empty($name) || empty($source_url)) {
            $error = "Name and URL are required";
        } else {
            if ($project['id']) {
                // Update
                $stmt = $pdo->prepare("
                    UPDATE {$config['tables']['projects']} 
                    SET name = ?, source_type = ?, source_url = ?, excluded_dirs = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $source_type, $source_url, $excluded_dirs, $project['id']]);
                $message = "Project updated successfully";
            } else {
                // Insert
                $stmt = $pdo->prepare("
                    INSERT INTO {$config['tables']['projects']} (name, source_type, source_url, excluded_dirs)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$name, $source_type, $source_url, $excluded_dirs]);
                $message = "Project added successfully";
            }
            
            // Reload project data
            if (!$project['id']) {
                $project['id'] = $pdo->lastInsertId();
            }
            $stmt = $pdo->prepare("SELECT * FROM {$config['tables']['projects']} WHERE id = ?");
            $stmt->execute([$project['id']]);
            $project = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title><?= $project['id'] ? 'Edit' : 'Add' ?> Project - CodePhacts</title>
    <link rel="stylesheet" href="../public/style.css">
</head>
<body>
    <header>
        <div class="container">
            <h1>CodePhacts Admin</h1>
            <nav>
                <a href="index.php">Dashboard</a>
                <a href="projects.php">Manage Projects</a>
                <a href="../public/index.php">View Public Site</a>
                <a href="logout.php">Logout</a>
            </nav>
        </div>
    </header>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <div class="card">
            <h2><?= $project['id'] ? 'Edit' : 'Add New' ?> Project</h2>
            
            <form method="POST">
                <label>Project Name *</label>
                <input type="text" name="name" value="<?= htmlspecialchars($project['name']) ?>" required>
                
                <label>Source Type *</label>
                <select name="source_type" required>
                    <option value="git" <?= $project['source_type'] === 'git' ? 'selected' : '' ?>>Git</option>
                    <option value="svn" <?= $project['source_type'] === 'svn' ? 'selected' : '' ?>>SVN</option>
                </select>
                
                <label>Repository URL *</label>
                <input type="url" name="source_url" value="<?= htmlspecialchars($project['source_url']) ?>" required placeholder="https://github.com/user/repo.git">
                
                <label>Excluded Directories</label>
                <input type="text" name="excluded_dirs" value="<?= htmlspecialchars($project['excluded_dirs']) ?>" placeholder="vendor,node_modules,build">
                <small style="color: #6c757d;">Comma-separated list of directories to exclude (e.g., vendor,node_modules,build)</small>
                
                <div style="margin-top: 20px;">
                    <button type="submit"><?= $project['id'] ? 'Update' : 'Add' ?> Project</button>
                    <a href="projects.php" class="button secondary">Cancel</a>
                </div>
            </form>
        </div>
        
        <?php if ($project['id']): ?>
            <div class="card">
                <h2>Project Statistics</h2>
                <?php
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as commit_count 
                    FROM {$config['tables']['commits']} 
                    WHERE project_id = ?
                ");
                $stmt->execute([$project['id']]);
                $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                ?>
                <p><strong>Commits Tracked:</strong> <?= $stats['commit_count'] ?></p>
                <p><strong>Last Updated:</strong> <?= htmlspecialchars($project['last_updated'] ? $project['last_updated'] : 'Never') ?></p>
                <p><strong>Last Commit:</strong> <?= htmlspecialchars($project['last_commit'] ? $project['last_commit'] : 'None') ?></p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>