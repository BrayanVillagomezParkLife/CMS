<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();
logout();
header('Location: login.php'); exit;
