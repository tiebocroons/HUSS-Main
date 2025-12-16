<?php
// Helper to invoke stockpiles.php handler from CLI with POST variables
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['action'] = 'list_storage_for_facility';
$_POST['facility_id'] = 1;
// include the endpoint (it will echo JSON and exit)
require __DIR__ . '/stockpiles.php';

echo "\n"; // in case include didn't exit
