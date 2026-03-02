<?php
require 'includes/config.php';
require 'includes/db.php';

try {
    $pdo = db();
    echo "✅ Conexión exitosa a: " . DB_NAME;
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}