<?php
/**
 * database.php
 * Project-wide PDO helper that prefers a local SQLite DB (php/pet.db)
 * and falls back to MySQL when SQLite is not available.
 *
 * The existing MySQL login credentials in this file are preserved
 * (the user indicated they are correct) and will be used if
 * no local SQLite file is present.
 */

// Simple .env loader: if a `.env` file exists in project root, load its variables
if (!function_exists('load_dotenv_from_file')) {
    function load_dotenv_from_file($path)
    {
        if (!file_exists($path) || !is_readable($path)) return;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (strpos($line, '=') === false) continue;
            list($name, $value) = array_map('trim', explode('=', $line, 2));
            // remove surrounding quotes
            if (strlen($value) > 1 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
                $value = substr($value, 1, -1);
            }
            // set env vars if not already set
            if (getenv($name) === false) {
                putenv("$name=$value");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

// attempt to load `.env` in project root (safe to edit locally). Use `.env.example` as template.
load_dotenv_from_file(__DIR__ . DIRECTORY_SEPARATOR . '.env');


// MySQL credentials (preserved)
define('DB_HOST', 'localhost');
define('DB_NAME', 'u163647p152138_pet');
define('DB_USER', 'u163647p152138_support');
define('DB_PASS', 'p6R3HhFBPAsF4Pxtp2Cm');
define('DB_CHARSET', 'utf8mb4');

// Path to local SQLite DB used by project if present
define('LOCAL_SQLITE_PATH', __DIR__ . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'pet.db');

// Allow overriding DB driver via env: 'auto' (default), 'sqlite', 'mysql'
if (getenv('DB_DRIVER') === false) putenv('DB_DRIVER=auto');

// Allow overriding sqlite path via env
if (getenv('DB_SQLITE_PATH') === false) putenv('DB_SQLITE_PATH=' . LOCAL_SQLITE_PATH);

// DB_ENV selects which credential set to use: 'local' (default) or 'remote' (or any tag like 'staging')
if (getenv('DB_ENV') === false) putenv('DB_ENV=local');

// Helper: read env key with DB_ENV prefix fallback. E.g. if DB_ENV=REMOTE and key=DB_HOST,
// will try REMOTE_DB_HOST then DB_HOST.
function env_for($key){
    $env = getenv('DB_ENV') ?: 'local';
    $tag = strtoupper($env);
    $prefKey = $tag . '_' . $key;
    $v = getenv($prefKey);
    if ($v !== false) return $v;
    return getenv($key);
}

/**
 * Return a PDO instance. Uses SQLite file at `php/pet.db` if present,
 * otherwise connects to MySQL using the constants above.
 *
 * @return PDO
 * @throws Exception on failure
 */
function getDatabaseConnection()
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    // Determine driver preference: auto / sqlite / mysql
    $driverPref = strtolower(getenv('DB_DRIVER') ?: 'auto');
    $sqlitePath = getenv('DB_SQLITE_PATH') ?: LOCAL_SQLITE_PATH;

    if ($driverPref === 'sqlite' || ($driverPref === 'auto' && file_exists($sqlitePath))) {
        try {
            $dsn = 'sqlite:' . $sqlitePath;
            $pdo = new PDO($dsn);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $pdo;
        } catch (PDOException $e) {
            error_log('Failed to open SQLite DB (' . $sqlitePath . '): ' . $e->getMessage());
            if ($driverPref === 'sqlite') {
                throw $e; // explicit sqlite preference, surface error
            }
            // else fall through to MySQL attempt when auto and sqlite failed
        }
    }

    // Fall back to MySQL using provided credentials or environment overrides
    try {
        // Pick credentials using env_for() which respects DB_ENV (e.g. LOCAL or REMOTE prefixes)
        $host = env_for('DB_HOST') ?: DB_HOST;
        $user = env_for('DB_USER') ?: DB_USER;
        $pass = env_for('DB_PASS') ?: DB_PASS;
        $charset = env_for('DB_CHARSET') ?: DB_CHARSET;
        // Allow specifying the port either via DB_PORT or appended to DB_HOST as host:port
        $port = env_for('DB_PORT') ?: null;
        if ($port === null && is_string($host) && $host !== '') {
            // handle IPv6 in brackets: [::1]:3306
            if ($host[0] === '[') {
                if (preg_match('/^\[(.+?)\](?::(\d+))?$/', $host, $m)) {
                    $host = $m[1];
                    if (!empty($m[2])) $port = $m[2];
                }
            } elseif (strpos($host, ':') !== false) {
                // split on last colon to support hostnames with colons in other contexts
                $pos = strrpos($host, ':');
                $maybePort = substr($host, $pos + 1);
                if (ctype_digit($maybePort)) {
                    $port = $maybePort;
                    $host = substr($host, 0, $pos);
                }
            }
        }

        // Detect MAMP on Windows (typical install path) or macOS and override credentials.
        // When MAMP is detected we also use the local database name 'pet' by default.
        $dbname = DB_NAME;
        if (is_dir('C:\\MAMP') || is_dir('/Applications/MAMP')) {
            $host = '127.0.0.1';
            $user = 'root';
            $pass = 'root';
            // MAMP commonly exposes MySQL on port 8889 on macOS; on some Windows setups it may be 8889 or 3306.
            // Use 8889 which is the usual MAMP default. Adjust if your MAMP uses a different port.
            $port = 8889;
            $dbname = 'pet';
        }

        // Build DSN including port when provided
        $dsn = 'mysql:host=' . $host . ($port ? ';port=' . $port : '') . ';dbname=' . $dbname . ';charset=' . $charset;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        // Use persistent connections only when explicitly requested via environment variable
        $usePersistent = getenv('DB_PERSISTENT');
        if ($usePersistent) $options[PDO::ATTR_PERSISTENT] = true;

        $pdo = new PDO($dsn, $user, $pass, $options);
        return $pdo;
    } catch (PDOException $e) {
        error_log('MySQL connection failed: ' . $e->getMessage());
        throw new Exception('Database connection failed.');
    }
}

/**
 * Execute a prepared query and return all rows.
 * @param string $sql
 * @param array $params
 * @return array
 */
function dbQueryAll($sql, $params = [])
{
    $pdo = getDatabaseConnection();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Execute a prepared query and return a single row.
 */
function dbQueryOne($sql, $params = [])
{
    $pdo = getDatabaseConnection();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

/**
 * Execute a statement (INSERT/UPDATE/DELETE) and return affected rows.
 */
function dbExecute($sql, $params = [])
{
    $pdo = getDatabaseConnection();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * Test connection availability. Returns true on success, false on failure.
 */
function testDatabaseConnection()
{
    try {
        $pdo = getDatabaseConnection();
        if ($pdo instanceof PDO) {
            // lightweight test
            $pdo->query('SELECT 1');
            return true;
        }
    } catch (Exception $e) {
        error_log('Database test failed: ' . $e->getMessage());
    }
    return false;
}

/**
 * Check for presence of expected tables. Works for both SQLite and MySQL.
 * @param array $expectedTables
 * @return array ['exists' => bool, 'missing' => array, 'existing' => array]
 */
function checkDatabaseTables($expectedTables = ['recipes', 'outputs', 'inputs', 'categories'])
{
    try {
        $pdo = getDatabaseConnection();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $existing = [];

        if ($driver === 'sqlite') {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $t) $existing[] = $t;
        } else {
            $stmt = $pdo->query('SHOW TABLES');
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            foreach ($rows as $r) $existing[] = $r[0];
        }

        $found = array_intersect($expectedTables, $existing);
        $missing = array_values(array_diff($expectedTables, $found));
        return ['exists' => count($missing) === 0, 'missing' => $missing, 'existing' => array_values($found)];
    } catch (Exception $e) {
        error_log('Table check failed: ' . $e->getMessage());
        return ['exists' => false, 'missing' => $expectedTables, 'existing' => []];
    }
}

?>