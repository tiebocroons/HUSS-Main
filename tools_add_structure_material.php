<?php
// Simulate adding a materials-only structure via officer.php
ini_set('display_errors',1); error_reporting(E_ALL);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
  'action' => 'add_structure',
  'facility_id' => 1,
  'structure_name' => 'Test Structure Materials',
  'types' => json_encode(['materials']),
  'capacity' => 1000,
  'icon' => ''
];
include __DIR__ . '/officer.php';
?>