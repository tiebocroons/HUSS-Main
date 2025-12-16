<?php
require_once __DIR__ . '/auth.php';
// Log the logout action in audit if possible
$current = current_user();
try{
  if ($current && isset($current['id'])){
    $details = json_encode(['remote_ip'=>isset($_SERVER['REMOTE_ADDR'])?$_SERVER['REMOTE_ADDR']:null,'user_agent'=>isset($_SERVER['HTTP_USER_AGENT'])?substr($_SERVER['HTTP_USER_AGENT'],0,200):null]);
    dbExecute('INSERT INTO user_audit (user_id, changed_by, action, details) VALUES (?,?,?,?)', [$current['id'], null, 'logout', $details]);
  }
}catch(Exception $e){ /* ignore */ }
logout();
header('Location: login.php');
exit;
?>