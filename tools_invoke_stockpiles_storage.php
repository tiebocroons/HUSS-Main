<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// simulate POST for list_storage_for_facility
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action' => 'list_storage_for_facility', 'facility_id' => 1];

ob_start();
try{
  include __DIR__ . '/stockpiles.php';
} catch (Throwable $e){
  echo "EXCEPTION: " . $e->getMessage() . "\n";
}
$out = ob_get_clean();
file_put_contents(__DIR__ . '/last_stockpiles_storage_response.txt', $out);
echo "Wrote response to last_stockpiles_storage_response.txt (length: " . strlen($out) . ")\n";
if (strlen($out) < 1) echo "(empty response)\n";
?>