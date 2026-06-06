<?php
/**
 * auth.php - Authentication Logic, Login Interface, and Offline Recovery
 */

require_once 'config.php';
safe_session_start();

// Redirect to installer if not installed
if (!FileManagerDB::isInstalled()) {
    header("Location: index.php");
    exit;
}

// Handle Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header("Location: auth.php");
    exit;
}

// Redirect to dashboard if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';
$mode = $_GET['action'] ?? 'login'; // 'login', 'recover', 'reset'

$db = FileManagerDB::connect();

// 1. Process Login
if ($mode === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        $stmt = $db->prepare("SELECT * FROM users WHERE username = :username");
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $res = $stmt->execute();
        $user = $res->fetchArray(SQLITE3_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['storage_root'] = $user['storage_root'];
            
            header("Location: index.php");
            exit;
        } else {
            $error = "Invalid username or password.";
        }
    }
}

// 2. Process Recovery Questions
if ($mode === 'recover' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $realName = trim($_POST['real_name'] ?? '');
    $petName = trim($_POST['pet_name'] ?? '');

    if (empty($username) || empty($realName) || empty($petName)) {
        $error = "All fields are required.";
    } else {
        $stmt = $db->prepare("SELECT * FROM users WHERE username = :username");
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $res = $stmt->execute();
        $user = $res->fetchArray(SQLITE3_ASSOC);

        if ($user) {
            try {
                $dbRealName = decrypt_data($user['real_name']);
                $normalizedPetName = strtolower(trim($petName));
                
                if (strcasecmp($dbRealName, $realName) === 0 && password_verify($normalizedPetName, $user['pet_name_hash'])) {
                    // Verification success: Store verified ID in session and route to reset password
                    $_SESSION['recovery_user_id'] = $user['id'];
                    header("Location: auth.php?action=reset");
                    exit;
                } else {
                    $error = "Recovery answers do not match our records.";
                }
            } catch (Exception $e) {
                $error = "Recovery verification error: " . $e->getMessage();
            }
        } else {
            $error = "Username not found.";
        }
    }
}

// 3. Process Password Reset
if ($mode === 'reset') {
    if (!isset($_SESSION['recovery_user_id'])) {
        header("Location: auth.php?action=recover");
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $newPassword = $_POST['new_password'] ?? '';
        $newPasswordConfirm = $_POST['new_password_confirm'] ?? '';

        if (empty($newPassword) || strlen($newPassword) < 8) {
            $error = "Password must be at least 8 characters long.";
        } elseif ($newPassword !== $newPasswordConfirm) {
            $error = "Passwords do not match.";
        } else {
            $userId = $_SESSION['recovery_user_id'];
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

            $stmt = $db->prepare("UPDATE users SET password_hash = :password_hash WHERE id = :id");
            $stmt->bindValue(':password_hash', $newHash, SQLITE3_TEXT);
            $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
            $stmt->execute();

            unset($_SESSION['recovery_user_id']);
            $success = "Password successfully reset. You can now login.";
            $mode = 'login';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-950 text-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP File Manager - Access Control</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Outfit', sans-serif; }
    </style>
</head>
<body class="h-full flex items-center justify-center p-4 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-indigo-900 via-slate-950 to-black">
    <div class="w-full max-w-md bg-slate-900/80 backdrop-blur-xl border border-slate-800 rounded-2xl shadow-2xl p-8 transition-all duration-300 hover:border-indigo-500/50">
        
        <!-- Tab Headers -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-indigo-600/10 text-indigo-400 mb-4 border border-indigo-500/20">
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            
            <?php if ($mode === 'login'): ?>
                <h1 class="text-2xl font-bold tracking-tight text-white">Sign In</h1>
                <p class="text-slate-400 text-sm mt-1">Access your secure files</p>
            <?php elseif ($mode === 'recover'): ?>
                <h1 class="text-2xl font-bold tracking-tight text-white">Password Recovery</h1>
                <p class="text-slate-400 text-sm mt-1">Verify offline security questions</p>
            <?php elseif ($mode === 'reset'): ?>
                <h1 class="text-2xl font-bold tracking-tight text-white">Set New Password</h1>
                <p class="text-slate-400 text-sm mt-1">Provide a strong, new password</p>
            <?php endif; ?>
        </div>

        <?php if (!empty($error)): ?>
            <div class="mb-6 p-4 rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-400 text-sm">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="mb-6 p-4 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- LOGIN MODE -->
        <?php if ($mode === 'login'): ?>
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Username</label>
                    <input type="text" name="username" required autocomplete="username"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition"
                           placeholder="Enter your username">
                </div>

                <div>
                    <div class="flex justify-between items-center mb-2">
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider">Password</label>
                        <a href="auth.php?action=recover" class="text-xs font-medium text-indigo-400 hover:text-indigo-300">Forgot?</a>
                    </div>
                    <input type="password" name="password" required autocomplete="current-password"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition"
                           placeholder="Enter your password">
                </div>

                <button type="submit"
                        class="w-full mt-6 bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white font-semibold rounded-xl py-3 shadow-lg shadow-indigo-500/20 transition duration-300">
                    Sign In
                </button>
            </form>
        <?php endif; ?>

        <!-- RECOVER MODE -->
        <?php if ($mode === 'recover'): ?>
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Username</label>
                    <input type="text" name="username" required autocomplete="off"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition"
                           placeholder="Username to recover">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Account Owner's Real Name</label>
                    <input type="text" name="real_name" required autocomplete="off"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition"
                           placeholder="e.g. John Doe">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Secret Pet Name</label>
                    <input type="text" name="pet_name" required autocomplete="off"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition"
                           placeholder="e.g. Fluffy">
                </div>

                <div class="flex items-center justify-between pt-4">
                    <a href="auth.php?action=login" class="text-sm font-medium text-slate-400 hover:text-slate-300">&larr; Back to login</a>
                    <button type="submit"
                            class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold rounded-xl px-6 py-3 shadow-lg shadow-indigo-500/10 transition duration-300">
                        Verify Answers
                    </button>
                </div>
            </form>
        <?php endif; ?>

        <!-- RESET MODE -->
        <?php if ($mode === 'reset'): ?>
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">New Password</label>
                    <input type="password" name="new_password" required
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition"
                           placeholder="Minimum 8 characters">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Confirm New Password</label>
                    <input type="password" name="new_password_confirm" required
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition"
                           placeholder="Re-enter new password">
                </div>

                <button type="submit"
                        class="w-full mt-6 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white font-semibold rounded-xl py-3 shadow-lg shadow-emerald-500/20 transition duration-300">
                    Update Password
                </button>
            </form>
        <?php endif; ?>

    </div>
</body>
</html>
