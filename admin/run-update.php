<?php
$config = require_once 'auth.php';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Running Update - CodePhacts</title>
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
        <div class="card">
            <h2>Running Update</h2>
            <p>Processing projects... This may take a while.</p>
            <pre style="background: #f8f9fa; padding: 15px; border-radius: 4px; overflow: auto; max-height: 500px;">
<?php
flush();
ob_flush();

$phactorPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'phactor.php';
$output = [];
$returnVar = 0;

exec("php " . escapeshellarg($phactorPath) . " 2>&1", $output, $returnVar);

foreach ($output as $line) {
    echo htmlspecialchars($line) . "\n";
    flush();
    ob_flush();
}

if ($returnVar === 0) {
    echo "\n! Update completed successfully!";
} else {
    echo "\nX Update failed with exit code: $returnVar";
}
?>
            </pre>
            <div style="margin-top: 20px;">
                <a href="index.php" class="button">Back to Dashboard</a>
            </div>
        </div>
    </div>
</body>
</html>