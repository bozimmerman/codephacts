<?php
session_start();

$config = require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config.php';

// Check if logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true)
{
    header('Location: login.php');
    exit;
}

// Make config available to admin pages
return $config;