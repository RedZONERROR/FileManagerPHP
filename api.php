<?php
/**
 * api.php - Central Secure AJAX Controller for File Manager operations
 */

require_once 'config.php';
safe_session_start();

// Send JSON response headers by default (can be overridden by downloads/zip stream)
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$userStorageRoot = $_SESSION['storage_root'];
$userRole = $_SESSION['role'];

// Establish DB connection
$db = FileManagerDB::connect();

// CSRF Protection for state-changing requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token($token)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
        exit;
    }
}

// Helper to recursively delete files and directories
function recursive_delete($path, $userStorageRoot) {
    $resolved = verify_and_resolve_path($path, $userStorageRoot);
    if (is_dir($resolved)) {
        $files = array_diff(scandir($resolved), ['.', '..']);
        foreach ($files as $file) {
            recursive_delete($resolved . DIRECTORY_SEPARATOR . $file, $userStorageRoot);
        }
        return rmdir($resolved);
    } elseif (is_file($resolved)) {
        return unlink($resolved);
    }
    return false;
}

// Helper to recursively copy directories
function recursive_copy($src, $dst, $userStorageRoot) {
    $resolvedSrc = verify_and_resolve_path($src, $userStorageRoot);
    $resolvedDst = verify_and_resolve_path($dst, $userStorageRoot);

    if (is_dir($resolvedSrc)) {
        if (!file_exists($resolvedDst)) {
            mkdir($resolvedDst, 0755, true);
        }
        $files = array_diff(scandir($resolvedSrc), ['.', '..']);
        foreach ($files as $file) {
            recursive_copy($resolvedSrc . DIRECTORY_SEPARATOR . $file, $resolvedDst . DIRECTORY_SEPARATOR . $file, $userStorageRoot);
        }
        return true;
    } elseif (is_file($resolvedSrc)) {
        return copy($resolvedSrc, $resolvedDst);
    }
    return false;
}

// Helper to recursively zip a folder
function add_folder_to_zip($folder, $zipFile, $exclusiveLength) {
    $handle = opendir($folder);
    while (false !== ($f = readdir($handle))) {
        if ($f !== '.' && $f !== '..') {
            $filePath = $folder . DIRECTORY_SEPARATOR . $f;
            $localPath = substr($filePath, $exclusiveLength);
            if (is_file($filePath)) {
                $zipFile->addFile($filePath, $localPath);
            } elseif (is_dir($filePath)) {
                $zipFile->addEmptyDir($localPath);
                add_folder_to_zip($filePath, $zipFile, $exclusiveLength);
            }
        }
    }
    closedir($handle);
}

// Parse request action
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            $relPath = $_GET['path'] ?? '';
            $targetPath = $userStorageRoot . DIRECTORY_SEPARATOR . $relPath;
            $resolvedPath = verify_and_resolve_path($targetPath, $userStorageRoot);

            if (!is_dir($resolvedPath)) {
                throw new Exception("Directory does not exist.");
            }

            $items = [];
            $scan = array_diff(scandir($resolvedPath), ['.', '..']);
            
            // Collect existing shares for the folder items
            $sharesList = [];
            $res = $db->query("SELECT * FROM shares");
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                try {
                    $decryptedPath = decrypt_data($row['file_path']);
                    $sharesList[$decryptedPath] = $row['share_token'];
                } catch (Exception $e) {
                    // Skip malformed encryption keys/shares
                }
            }

            foreach ($scan as $name) {
                $fullPath = $resolvedPath . DIRECTORY_SEPARATOR . $name;
                $isDir = is_dir($fullPath);
                $relItemPath = ltrim($relPath . '/' . $name, '/');
                
                $items[] = [
                    'name' => $name,
                    'is_dir' => $isDir,
                    'size' => $isDir ? 0 : filesize($fullPath),
                    'mtime' => filemtime($fullPath),
                    'path' => $relItemPath,
                    'share_token' => isset($sharesList[$fullPath]) ? $sharesList[$fullPath] : null
                ];
            }

            // Sort dirs first, then files alphabetically
            usort($items, function($a, $b) {
                if ($a['is_dir'] !== $b['is_dir']) {
                    return $b['is_dir'] - $a['is_dir'];
                }
                return strcasecmp($a['name'], $b['name']);
            });

            echo json_encode(['status' => 'success', 'data' => $items]);
            break;

        case 'create_folder':
            $parent = $_POST['parent_dir'] ?? '';
            $folderName = trim($_POST['folder_name'] ?? '');
            
            if (empty($folderName) || preg_match('/[\/\\\\\:\*\?\"\<\>\|]/', $folderName)) {
                throw new Exception("Invalid folder name.");
            }

            $target = $userStorageRoot . DIRECTORY_SEPARATOR . $parent . DIRECTORY_SEPARATOR . $folderName;
            $resolved = verify_and_resolve_path($target, $userStorageRoot);

            if (file_exists($resolved)) {
                throw new Exception("Folder already exists.");
            }

            mkdir($resolved, 0755, true);
            echo json_encode(['status' => 'success', 'message' => 'Folder created successfully.']);
            break;

        case 'rename':
            $parent = $_POST['parent_dir'] ?? '';
            $oldName = trim($_POST['old_name'] ?? '');
            $newName = trim($_POST['new_name'] ?? '');

            if (empty($oldName) || empty($newName) || preg_match('/[\/\\\\\:\*\?\"\<\>\|]/', $newName)) {
                throw new Exception("Invalid names provided.");
            }

            $oldTarget = $userStorageRoot . DIRECTORY_SEPARATOR . $parent . DIRECTORY_SEPARATOR . $oldName;
            $newTarget = $userStorageRoot . DIRECTORY_SEPARATOR . $parent . DIRECTORY_SEPARATOR . $newName;

            $resolvedOld = verify_and_resolve_path($oldTarget, $userStorageRoot);
            $resolvedNew = verify_and_resolve_path($newTarget, $userStorageRoot);

            if (!file_exists($resolvedOld)) {
                throw new Exception("Source file/folder does not exist.");
            }
            if (file_exists($resolvedNew)) {
                throw new Exception("Destination name already exists.");
            }

            rename($resolvedOld, $resolvedNew);
            echo json_encode(['status' => 'success', 'message' => 'Item renamed successfully.']);
            break;

        case 'delete':
            $items = $_POST['items'] ?? [];
            if (!is_array($items) || empty($items)) {
                throw new Exception("No items specified for deletion.");
            }

            foreach ($items as $item) {
                $target = $userStorageRoot . DIRECTORY_SEPARATOR . $item;
                recursive_delete($target, $userStorageRoot);
            }

            echo json_encode(['status' => 'success', 'message' => 'Items deleted successfully.']);
            break;

        case 'move':
        case 'copy':
            $destination = $_POST['destination'] ?? '';
            $items = $_POST['items'] ?? [];
            if (!is_array($items) || empty($items)) {
                throw new Exception("No items specified.");
            }

            $destDir = $userStorageRoot . DIRECTORY_SEPARATOR . $destination;
            $resolvedDestDir = verify_and_resolve_path($destDir, $userStorageRoot);

            foreach ($items as $item) {
                $srcTarget = $userStorageRoot . DIRECTORY_SEPARATOR . $item;
                $resolvedSrc = verify_and_resolve_path($srcTarget, $userStorageRoot);
                $destTarget = $resolvedDestDir . DIRECTORY_SEPARATOR . basename($resolvedSrc);

                if ($action === 'move') {
                    $resolvedDest = verify_and_resolve_path($destTarget, $userStorageRoot);
                    if (file_exists($resolvedDest)) {
                        throw new Exception("Item '" . basename($resolvedSrc) . "' already exists in destination.");
                    }
                    rename($resolvedSrc, $resolvedDest);
                } else {
                    $resolvedDest = verify_and_resolve_path($destTarget, $userStorageRoot);
                    if (file_exists($resolvedDest)) {
                        throw new Exception("Item '" . basename($resolvedSrc) . "' already exists in destination.");
                    }
                    recursive_copy($resolvedSrc, $resolvedDest, $userStorageRoot);
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'Operation completed successfully.']);
            break;

        case 'upload':
            $destDir = $_POST['dest_dir'] ?? '';
            $overwrite = ($_POST['overwrite'] ?? 'false') === 'true';

            if (!isset($_FILES['file'])) {
                throw new Exception("No file uploaded.");
            }

            $uploadFile = $_FILES['file'];
            if ($uploadFile['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Upload error code: " . $uploadFile['error']);
            }

            $fileName = basename($uploadFile['name']);
            $target = $userStorageRoot . DIRECTORY_SEPARATOR . $destDir . DIRECTORY_SEPARATOR . $fileName;
            $resolved = verify_and_resolve_path($target, $userStorageRoot);

            if (file_exists($resolved) && !$overwrite) {
                throw new Exception("File already exists. Set overwrite to replace it.");
            }

            if (!move_uploaded_file($uploadFile['tmp_name'], $resolved)) {
                throw new Exception("Failed to save uploaded file.");
            }

            echo json_encode(['status' => 'success', 'message' => 'File uploaded successfully.']);
            break;

        case 'get_content':
            $path = $_GET['path'] ?? '';
            $target = $userStorageRoot . DIRECTORY_SEPARATOR . $path;
            $resolved = verify_and_resolve_path($target, $userStorageRoot);

            if (!is_file($resolved)) {
                throw new Exception("Target is not a valid file.");
            }

            // Check if text/editable extension
            $allowedExts = ['txt', 'html', 'css', 'js', 'json', 'php', 'xml', 'md', 'ini', 'htaccess', 'sh', 'bat'];
            $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts) && filesize($resolved) > 5 * 1024 * 1024) {
                throw new Exception("File is too large or not editable.");
            }

            $content = file_get_contents($resolved);
            echo json_encode(['status' => 'success', 'content' => $content]);
            break;

        case 'save_content':
            $path = $_POST['path'] ?? '';
            $content = $_POST['content'] ?? '';
            $target = $userStorageRoot . DIRECTORY_SEPARATOR . $path;
            $resolved = verify_and_resolve_path($target, $userStorageRoot);

            if (!is_file($resolved)) {
                throw new Exception("Target is not a valid file.");
            }

            file_put_contents($resolved, $content, LOCK_EX);
            echo json_encode(['status' => 'success', 'message' => 'File saved successfully.']);
            break;

        case 'share':
            $path = $_POST['path'] ?? '';
            $target = $userStorageRoot . DIRECTORY_SEPARATOR . $path;
            $resolved = verify_and_resolve_path($target, $userStorageRoot);

            if (!is_file($resolved)) {
                throw new Exception("Only files can be shared.");
            }

            // Check if share link already exists
            $encryptedPath = encrypt_data($resolved);
            $stmt = $db->prepare("SELECT share_token FROM shares WHERE file_path = :file_path");
            $stmt->bindValue(':file_path', $encryptedPath, SQLITE3_TEXT);
            $res = $stmt->execute();
            $existing = $res->fetchArray(SQLITE3_ASSOC);

            if ($existing) {
                $token = $existing['share_token'];
            } else {
                $token = bin2hex(random_bytes(16));
                $stmtIns = $db->prepare("INSERT INTO shares (file_path, share_token) VALUES (:file_path, :share_token)");
                $stmtIns->bindValue(':file_path', $encryptedPath, SQLITE3_TEXT);
                $stmtIns->bindValue(':share_token', $token, SQLITE3_TEXT);
                $stmtIns->execute();
            }

            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http');
            $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
            $shareUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . $scriptDir . '/index.php?share=' . $token;

            echo json_encode(['status' => 'success', 'share_url' => $shareUrl]);
            break;

        case 'unshare':
            $path = $_POST['path'] ?? '';
            $target = $userStorageRoot . DIRECTORY_SEPARATOR . $path;
            $resolved = verify_and_resolve_path($target, $userStorageRoot);

            $encryptedPath = encrypt_data($resolved);
            $stmt = $db->prepare("DELETE FROM shares WHERE file_path = :file_path");
            $stmt->bindValue(':file_path', $encryptedPath, SQLITE3_TEXT);
            $stmt->execute();

            echo json_encode(['status' => 'success', 'message' => 'Sharing disabled.']);
            break;

        case 'zip':
            $path = $_GET['path'] ?? '';
            $target = $userStorageRoot . DIRECTORY_SEPARATOR . $path;
            $resolved = verify_and_resolve_path($target, $userStorageRoot);

            if (!is_dir($resolved)) {
                throw new Exception("Only directories can be zipped.");
            }

            // Create dynamic zip file in system temp dir
            $tempZip = tempnam(sys_get_temp_dir(), 'mgrzip');
            $zip = new ZipArchive();
            if ($zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new Exception("Failed to create temporary ZIP archive.");
            }

            $folder = realpath($resolved);
            add_folder_to_zip($folder, $zip, strlen($folder) + 1);
            $zip->close();

            // Clear buffer to ensure ZIP binary streams perfectly
            if (ob_get_level()) {
                ob_end_clean();
            }

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . basename($resolved) . '.zip"');
            header('Content-Length: ' . filesize($tempZip));
            readfile($tempZip);
            unlink($tempZip);
            exit;

        case 'admin_list_users':
            if ($userRole !== 'owner') {
                throw new Exception("Admin access required.");
            }
            $res = $db->query("SELECT id, username, role, storage_root, created_at FROM users");
            $users = [];
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $users[] = $row;
            }
            echo json_encode(['status' => 'success', 'data' => $users]);
            break;

        case 'admin_create_user':
            if ($userRole !== 'owner') {
                throw new Exception("Admin access required.");
            }
            $newUsername = trim($_POST['username'] ?? '');
            $newPassword = $_POST['password'] ?? '';
            $newRealName = trim($_POST['real_name'] ?? '');
            $newPetName = trim($_POST['pet_name'] ?? '');

            if (empty($newUsername) || empty($newPassword) || empty($newRealName) || empty($newPetName)) {
                throw new Exception("All fields are required.");
            }
            if (strlen($newPassword) < 8) {
                throw new Exception("Password must be at least 8 characters long.");
            }

            // Setup isolated sub-folder under DEFAULT_STORAGE_ROOT
            $newUserStorage = DEFAULT_STORAGE_ROOT . DIRECTORY_SEPARATOR . $newUsername;
            if (!file_exists($newUserStorage)) {
                mkdir($newUserStorage, 0755, true);
            }

            $stmtCheck = $db->prepare("SELECT count(*) FROM users WHERE username = :username");
            $stmtCheck->bindValue(':username', $newUsername, SQLITE3_TEXT);
            if ($stmtCheck->execute()->fetchArray()[0] > 0) {
                throw new Exception("Username already exists.");
            }

            $encryptedReal = encrypt_data($newRealName);
            $petHash = password_hash(strtolower(trim($newPetName)), PASSWORD_DEFAULT);
            $pwdHash = password_hash($newPassword, PASSWORD_DEFAULT);

            $stmtIns = $db->prepare("INSERT INTO users (username, password_hash, real_name, pet_name_hash, role, storage_root) VALUES (:username, :password_hash, :real_name, :pet_name_hash, :role, :storage_root)");
            $stmtIns->bindValue(':username', $newUsername, SQLITE3_TEXT);
            $stmtIns->bindValue(':password_hash', $pwdHash, SQLITE3_TEXT);
            $stmtIns->bindValue(':real_name', $encryptedReal, SQLITE3_TEXT);
            $stmtIns->bindValue(':pet_name_hash', $petHash, SQLITE3_TEXT);
            $stmtIns->bindValue(':role', 'user', SQLITE3_TEXT);
            $stmtIns->bindValue(':storage_root', $newUserStorage, SQLITE3_TEXT);
            $stmtIns->execute();

            echo json_encode(['status' => 'success', 'message' => 'User created successfully.']);
            break;

        case 'admin_delete_user':
            if ($userRole !== 'owner') {
                throw new Exception("Admin access required.");
            }
            $userId = intval($_POST['user_id'] ?? 0);
            
            // Cannot delete current user
            if ($userId === intval($_SESSION['user_id'])) {
                throw new Exception("You cannot delete yourself.");
            }

            $stmtCheck = $db->prepare("SELECT * FROM users WHERE id = :id");
            $stmtCheck->bindValue(':id', $userId, SQLITE3_INTEGER);
            $userToDelete = $stmtCheck->execute()->fetchArray(SQLITE3_ASSOC);

            if (!$userToDelete) {
                throw new Exception("User not found.");
            }

            // Remove user storage folder contents safely
            $delStorage = $userToDelete['storage_root'];
            if (file_exists($delStorage)) {
                recursive_delete($delStorage, $delStorage);
            }

            // Delete from database
            $stmtDel = $db->prepare("DELETE FROM users WHERE id = :id");
            $stmtDel->bindValue(':id', $userId, SQLITE3_INTEGER);
            $stmtDel->execute();

            echo json_encode(['status' => 'success', 'message' => 'User deleted successfully.']);
            break;

        default:
            throw new Exception("Invalid Action.");
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
