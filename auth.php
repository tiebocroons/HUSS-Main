<?php
// Harden session cookie parameters before starting session
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$cookieParams = session_get_cookie_params();
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        'path' => $cookieParams['path'],
        'domain' => $cookieParams['domain'],
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
} else {
    // older PHP: cannot set samesite via array, set secure and httponly
    session_set_cookie_params($cookieParams['lifetime'], $cookieParams['path'], $cookieParams['domain'], $secure, true);
}
session_start();

require_once __DIR__ . '/database.php';

// CSRF helpers
function generate_csrf_token(){
    if (empty($_SESSION['csrf_token'])){
        try { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } catch(Exception $e) { $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32)); }
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token){
    return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

// Simple login throttling: limit failed attempts per username in session
function auth_record_failed_attempt($discord){
    if (!isset($_SESSION['failed_login'])) $_SESSION['failed_login'] = [];
    $now = time();
    if (!isset($_SESSION['failed_login'][$discord])) $_SESSION['failed_login'][$discord] = ['count'=>0,'last'=>$now];
    $_SESSION['failed_login'][$discord]['count'] += 1;
    $_SESSION['failed_login'][$discord]['last'] = $now;
}

function auth_clear_failed_attempts($discord){
    if (isset($_SESSION['failed_login'][$discord])) unset($_SESSION['failed_login'][$discord]);
}

function auth_too_many_attempts($discord){
    if (!isset($_SESSION['failed_login'][$discord])) return false;
    $rec = $_SESSION['failed_login'][$discord];
    $now = time();
    // block if >=5 attempts within 900 seconds (15 minutes)
    if ($rec['count'] >= 5 && ($now - $rec['last']) < 900) return true;
    // if last attempt older than window, reset
    if (($now - $rec['last']) >= 900) { unset($_SESSION['failed_login'][$discord]); return false; }
    return false;
}

function find_user_by_name($discord){
    $sql = 'SELECT * FROM users WHERE discord = ? LIMIT 1';
    try{
        $row = dbQueryOne($sql, [$discord]);
        return $row ? $row : null;
    } catch (Exception $e){
        // if DB isn't available fall back gracefully
        error_log('DB lookup failed: ' . $e->getMessage());
        return null;
    }
}

function register_user($discord, $password, $role, &$error=null){
    $discord = trim($discord);
    // basic input validation
    if($discord === '' || $password === ''){ $error = 'Discord name and password are required.'; return false; }
    if (strlen($discord) < 3 || strlen($discord) > 64){ $error = 'Discord name must be between 3 and 64 characters.'; return false; }
    if (strlen($password) < 8){ $error = 'Password must be at least 8 characters.'; return false; }
    // For security, registrations through the public form are always given the 'Member' role.
    $role = 'Member';
    try{
        $existing = find_user_by_name($discord);
        if($existing) { $error = 'User already exists.'; return false; }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $sql = 'INSERT INTO users (discord, password, role, created_at) VALUES (?, ?, ?, NOW())';
        dbExecute($sql, [$discord, $hash, $role]);
        return true;
    } catch (Exception $e){
        $error = 'Registration failed (database error).';
        error_log('Registration error: ' . $e->getMessage());
        return false;
    }
}

function login_user($discord, $password, &$error=null){
    try{
        // load user record early so we can audit events against an existing user id when possible
        $u = find_user_by_name($discord);
        if(!$u){
            // Unknown user — cannot reliably audit to user_audit due to FK constraints. Skip DB audit.
            $error = 'User not found.'; return false; }
        if(!password_verify($password, $u['password'])){
            // record failed login attempt for existing user
            try {
                $details = json_encode(['user_id'=>isset($u['id'])?$u['id']:null, 'discord'=>$discord, 'remote_ip'=>isset($_SERVER['REMOTE_ADDR'])?$_SERVER['REMOTE_ADDR']:null, 'user_agent'=>isset($_SERVER['HTTP_USER_AGENT'])?substr($_SERVER['HTTP_USER_AGENT'],0,200):null]);
                dbExecute('INSERT INTO user_audit (user_id, changed_by, action, details) VALUES (?,?,?,?)', [isset($u['id'])?$u['id']:null, null, 'login_failed', $details]);
            } catch (Exception $e) { /* ignore audit failures */ }
            $error = 'Invalid credentials.'; return false; }
        // login success
        // Note: login throttling/failed-attempt tracking has been removed.
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => isset($u['id']) ? $u['id'] : null,
            'discord' => $u['discord'],
            'role' => $u['role']
        ];
        return true;
    } catch (Exception $e){
        $error = 'Login failed (database error).';
        error_log('Login error: ' . $e->getMessage());
        return false;
    }
}

function require_login(){
    if(empty($_SESSION['user'])){
        header('Location: login.php');
        exit;
    }
}

function current_user(){
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

function logout(){
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

?>
