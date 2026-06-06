<?php
/**
 * config.php - Core Constants, Database Connection, and Security Utilities
 */

// Enable strict error reporting
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Security Headers
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");

// Define paths
define('DATA_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'data');
define('DB_FILE', DATA_DIR . DIRECTORY_SEPARATOR . 'filemanager.db');
define('KEY_FILE', DATA_DIR . DIRECTORY_SEPARATOR . 'config_key.php');
define('DEFAULT_STORAGE_ROOT', __DIR__ . DIRECTORY_SEPARATOR . 'storage');

// Create directories if they do not exist
if (!file_exists(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}
if (!file_exists(DEFAULT_STORAGE_ROOT)) {
    mkdir(DEFAULT_STORAGE_ROOT, 0755, true);
}

// Write-protect the DATA_DIR via .htaccess
$htaccessFile = DATA_DIR . DIRECTORY_SEPARATOR . '.htaccess';
if (!file_exists($htaccessFile)) {
    $htaccessContent = "<Files \"*\">\n"
                     . "    <IfModule mod_authz_core.c>\n"
                     . "        Require all denied\n"
                     . "    </IfModule>\n"
                     . "    <IfModule !mod_authz_core.c>\n"
                     . "        Order Deny,Allow\n"
                     . "        Deny from all\n"
                     . "    </IfModule>\n"
                     . "</Files>\n";
    file_put_contents($htaccessFile, $htaccessContent);
}

/**
 * Secure session initialization
 */
function safe_session_start() {
    if (session_status() === PHP_SESSION_NONE) {
        $cookieParams = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => 86400,
            'path' => '/',
            'domain' => '',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_start();
    }
    // Generate CSRF token if not set
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

/**
 * CSRF token verification
 */
function validate_csrf_token($token) {
    safe_session_start();
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Fetch encryption key from KEY_FILE
 */
function get_encryption_key() {
    if (!file_exists(KEY_FILE)) {
        return null;
    }
    return require KEY_FILE;
}

/**
 * Generate and save encryption key
 */
function generate_and_save_key() {
    if (file_exists(KEY_FILE)) {
        return require KEY_FILE;
    }
    $key = bin2hex(random_bytes(32));
    $content = "<?php\nreturn '" . $key . "';\n";
    file_put_contents(KEY_FILE, $content, LOCK_EX);
    return $key;
}

/**
 * Encrypt data using AES-256-CBC
 */
function encrypt_data($data) {
    $key = get_encryption_key();
    if (!$key) {
        throw new Exception("Encryption key not found. Run setup first.");
    }
    $rawKey = hex2bin($key);
    $ivLength = openssl_cipher_iv_length('aes-256-cbc');
    $iv = openssl_random_pseudo_bytes($ivLength);
    $ciphertext = openssl_encrypt($data, 'aes-256-cbc', $rawKey, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $ciphertext);
}

/**
 * Decrypt data using AES-256-CBC
 */
function decrypt_data($encryptedBase64) {
    $key = get_encryption_key();
    if (!$key) {
        throw new Exception("Encryption key not found. Run setup first.");
    }
    $rawKey = hex2bin($key);
    $data = base64_decode($encryptedBase64);
    $ivLength = openssl_cipher_iv_length('aes-256-cbc');
    if (strlen($data) <= $ivLength) {
        return false;
    }
    $iv = substr($data, 0, $ivLength);
    $ciphertext = substr($data, $ivLength);
    return openssl_decrypt($ciphertext, 'aes-256-cbc', $rawKey, OPENSSL_RAW_DATA, $iv);
}

/**
 * Canonicalizes a path (resolves "." and "..", replaces backslashes)
 */
function get_canonical_path($path) {
    $path = str_replace('\\', '/', $path);
    $parts = explode('/', $path);
    $safeParts = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($safeParts);
        } else {
            $safeParts[] = $part;
        }
    }
    $prefix = (str_starts_with($path, '/') ? '/' : '');
    // For Windows absolute paths (e.g., C:/)
    if (isset($safeParts[0]) && preg_match('/^[a-zA-Z]:$/', $safeParts[0])) {
        $prefix = '';
    }
    return $prefix . implode('/', $safeParts);
}

/**
 * Verifies that a path resides strictly within the user's storage root directory.
 * Returns the resolved absolute path if safe, throws Exception otherwise.
 */
function verify_and_resolve_path($targetPath, $userStorageRoot) {
    $canonicalRoot = get_canonical_path(realpath($userStorageRoot));
    
    // Resolve absolute path
    $realTarget = realpath($targetPath);
    if ($realTarget !== false) {
        $canonicalTarget = get_canonical_path($realTarget);
    } else {
        // Path does not exist yet; resolve it logically
        $canonicalTarget = get_canonical_path($targetPath);
    }

    // Strict path checks
    if (!str_starts_with($canonicalTarget . '/', $canonicalRoot . '/')) {
        throw new Exception("Access Denied: Path traversal detected.");
    }
    
    return str_replace('/', DIRECTORY_SEPARATOR, $canonicalTarget);
}

/**
 * Database connection wrapper class
 */
class FileManagerDB {
    private static $connection = null;

    public static function connect() {
        if (self::$connection === null) {
            if (!file_exists(DB_FILE)) {
                // Return null if database hasn't been set up yet
                return null;
            }
            try {
                self::$connection = new SQLite3(DB_FILE);
                self::$connection->enableExceptions(true);
                // Set busy timeout for smooth SQLite access
                self::$connection->busyTimeout(5000);
            } catch (Exception $e) {
                die("Database Connection Error: " . htmlspecialchars($e->getMessage()));
            }
        }
        return self::$connection;
    }

    public static function isInstalled() {
        if (!file_exists(DB_FILE) || !file_exists(KEY_FILE)) {
            return false;
        }
        $db = self::connect();
        if (!$db) {
            return false;
        }
        try {
            $result = $db->querySingle("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='users'");
            return $result > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}
