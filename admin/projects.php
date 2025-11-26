<?php
$config = require_once 'auth.php';

$message = null;
$error = null;

try
{
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}",
        $config['db']['user'],
        $config['db']['pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) 
    {
        $stmt = $pdo->prepare("DELETE FROM {$config['tables']['projects']} WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $message = "Project deleted successfully";
    }
    $stmt = $pdo->query("SELECT * FROM {$config['tables']['projects']} ORDER BY name ASC");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
    <title>Manage Projects - CodePhacts</title>
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
            <h2>Projects</h2>
            <div style="margin-bottom: 20px;">
                <a href="edit-project.php" class="button">Add New Project</a>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>URL</th>
                        <th>Last Commit</th>
                        <th>Excluded Dirs</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($projects)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: #6c757d;">
                                No projects found. <a href="edit-project.php">Add your first project</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($projects as $project): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($project['name']) ?></strong></td>
                                <td><?= htmlspecialchars($project['source_type']) ?></td>
                                <td style="font-size: 0.85em; color: #6c757d;"><?= htmlspecialchars($project['source_url']) ?></td>
                                <td><?= htmlspecialchars($project['last_commit'] ? $project['last_commit'] : 'None') ?></td>
                                <td style="font-size: 0.85em;"><?= htmlspecialchars($project['excluded_dirs'] ? $project['excluded_dirs'] : 'None') ?></td>
                                <td class="actions">
                                    <a href="edit-project.php?id=<?= $project['id'] ?>" class="button">Edit</a>
                                    <button onclick="if(confirm('Delete this project and all its data?')) window.location.href='projects.php?action=delete&id=<?= $project['id'] ?>'" class="button danger">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>