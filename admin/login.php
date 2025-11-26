<?php
session_start();

$config = require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config.php';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']))
{
    if ($_POST['password'] === $config['admin_password'])
    {
        $_SESSION['admin_logged_in'] = true;
        header('Location: index.php');
        exit;
    }
    $error = 'Invalid password';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Admin Login - CodePhacts</title>
    <link rel="stylesheet" href="../public/style.css">
</head>
<body>
    <div class="login-box">
        <h2>CodePhacts Admin</h2>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="password" name="password" placeholder="Admin Password" required autofocus>
            <button type="submit">Login</button>
        </form>
    </div>
</body>
</html>