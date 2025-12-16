<?php
require_once __DIR__ . '/database.php';
$rows = dbQueryAll('SELECT id,name FROM facilities ORDER BY name');
echo json_encode(['facilities' => $rows], JSON_PRETTY_PRINT);
