<?php
require_once __DIR__ . '/database.php';
$rows = dbQueryAll("SELECT id,structure_id,storage_type,allowed_item,kind,slots,capacity FROM facility_storage ORDER BY structure_id, id");
header('Content-Type: application/json');
echo json_encode($rows, JSON_PRETTY_PRINT);
