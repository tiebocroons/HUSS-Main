<?php
// Simulate adding a liquid structure via officer.php
ini_set('display_errors',1); error_reporting(E_ALL);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
  'action' => 'add_structure',
  'facility_id' => 1,
  'structure_name' => 'Test Structure Liquid',
  'types' => json_encode(['liquid']),
  'capacity' => 500,
  'liquid_separate_capacity' => 500,
  'icon' => ''
];
include __DIR__ . '/officer.php';
?>