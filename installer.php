<?php
/**
 * installer.php - Standalone visual Setup Wizard
 */

require_once 'config.php';

// If already installed, redirect to entrypoint
if (FileManagerDB::isInstalled()) {
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminUser = trim($_POST['username'] ?? '');
    $adminPassword = $_POST['password'] ?? '';
    $adminPasswordConfirm = $_POST['password_confirm'] ?? '';
    $realName = trim($_POST['real_name'] ?? '');
    $petName = trim($_POST['pet_name'] ?? '');

    if (empty($adminUser) || empty($adminPassword) || empty($realName) || empty($petName)) {
        $error = "All fields are required.";
    } elseif ($adminPassword !== $adminPasswordConfirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($adminPassword) < 8) {
        $error = "Password must be at least 8 characters long.";
    } else {
        try {
            // Generate the secure encryption key first so we can encrypt/decrypt DB strings
            $key = generate_and_save_key();

            // Initialize SQLite DB
            $db = new SQLite3(DB_FILE);
            $db->enableExceptions(true);

            // Compile schemas
            $db->exec("CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                real_name TEXT NOT NULL,
                pet_name_hash TEXT NOT NULL,
                role TEXT NOT NULL,
                storage_root TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");

            $db->exec("CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )");

            $db->exec("CREATE TABLE IF NOT EXISTS shares (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_path TEXT NOT NULL,
                share_token TEXT UNIQUE NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");

            // Encrypt sensitive details
            $encryptedRealName = encrypt_data($realName);
            // Lowercase and trim pet name for normalized recovery validation, then hash it
            $normalizedPetName = strtolower(trim($petName));
            $petNameHash = password_hash($normalizedPetName, PASSWORD_DEFAULT);

            $adminHash = password_hash($adminPassword, PASSWORD_DEFAULT);
            $adminStorage = DEFAULT_STORAGE_ROOT . DIRECTORY_SEPARATOR . 'admin';
            if (!file_exists($adminStorage)) {
                mkdir($adminStorage, 0755, true);
            }

            // Insert Super Admin
            $stmt = $db->prepare("INSERT INTO users (username, password_hash, real_name, pet_name_hash, role, storage_root) VALUES (:username, :password_hash, :real_name, :pet_name_hash, :role, :storage_root)");
            $stmt->bindValue(':username', $adminUser, SQLITE3_TEXT);
            $stmt->bindValue(':password_hash', $adminHash, SQLITE3_TEXT);
            $stmt->bindValue(':real_name', $encryptedRealName, SQLITE3_TEXT);
            $stmt->bindValue(':pet_name_hash', $petNameHash, SQLITE3_TEXT);
            $stmt->bindValue(':role', 'owner', SQLITE3_TEXT);
            $stmt->bindValue(':storage_root', $adminStorage, SQLITE3_TEXT);
            $stmt->execute();

            // Save basic settings
            $stmtSet = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (:key, :value)");
            $stmtSet->bindValue(':key', 'app_name', SQLITE3_TEXT);
            $stmtSet->bindValue(':value', 'PHP File Manager', SQLITE3_TEXT);
            $stmtSet->execute();

            $success = "Installation completed successfully! Redirecting in 3 seconds...";
            header("refresh:3;url=index.php");
        } catch (Exception $e) {
            $error = "Installation failed: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-950 text-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP File Manager - Setup Wizard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Outfit', sans-serif; }
    </style>
</head>
<body class="h-full flex items-center justify-center p-4 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-indigo-900 via-slate-950 to-black">
    <div class="w-full max-w-md bg-slate-900/80 backdrop-blur-xl border border-slate-800 rounded-2xl shadow-2xl p-8 transition-all duration-300 hover:border-indigo-500/50">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-indigo-600/10 text-indigo-400 mb-4 border border-indigo-500/20">
                <!-- Lucide Database Icon replacement -->
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="animate-pulse"><path d="M3 5c0 1.66 4 3 9 3s9-1.34 9-3k3 5c0 1.66-4 3-9 3S3 6.66 3 5z"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/></svg>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-white">System Installation</h1>
            <p class="text-slate-400 text-sm mt-1">Configure your secure admin account and offline recovery questions.</p>
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

        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Admin Username</label>
                <input type="text" name="username" required autocomplete="off"
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition"
                       placeholder="e.g. admin">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Password</label>
                <input type="password" name="password" required
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition"
                       placeholder="Minimum 8 characters">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Confirm Password</label>
                <input type="password" name="password_confirm" required
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition"
                       placeholder="Re-enter password">
            </div>

            <div class="pt-4 border-t border-slate-800">
                <h3 class="text-sm font-semibold text-indigo-400 mb-3">Offline Recovery Setup</h3>
                
                <div class="space-y-4">
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
                </div>
            </div>

            <button type="submit"
                    class="w-full mt-6 bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white font-semibold rounded-xl py-3 shadow-lg shadow-indigo-500/20 transition-all duration-300 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-slate-900">
                Complete Setup
            </button>
        </form>
    </div>
</body>
</html>
