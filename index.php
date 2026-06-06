<?php
/**
 * index.php - Main Routing and Unauthenticated Direct Share Downloads
 */

require_once 'config.php';
safe_session_start();

// 1. Intercept installer check
if (!FileManagerDB::isInstalled()) {
    header("Location: installer.php");
    exit;
}

$db = FileManagerDB::connect();

// 2. Intercept and process unauthenticated share links
if (isset($_GET['share'])) {
    $token = trim($_GET['share']);
    
    $stmt = $db->prepare("SELECT * FROM shares WHERE share_token = :token");
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
    $res = $stmt->execute();
    $share = $res->fetchArray(SQLITE3_ASSOC);
    
    if ($share) {
        try {
            $filePath = decrypt_data($share['file_path']);
            if ($filePath && file_exists($filePath) && is_file($filePath)) {
                // Determine mime type
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $filePath);
                finfo_close($finfo);
                
                if (!$mimeType) {
                    $mimeType = 'application/octet-stream';
                }

                // Clear headers buffer to ensure perfect direct file streaming
                if (ob_get_level()) {
                    ob_end_clean();
                }

                // Set headers to serve file raw
                header('Content-Description: File Transfer');
                header('Content-Type: ' . $mimeType);
                header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($filePath));
                
                // Stream the file in chunks to optimize memory usage
                $file = fopen($filePath, 'rb');
                while (!feof($file)) {
                    echo fread($file, 8192);
                    flush();
                }
                fclose($file);
                exit;
            }
        } catch (Exception $e) {
            // Decryption or other errors
        }
    }
    
    // Fallback if share token is invalid or file is missing
    http_response_code(404);
    echo "<!DOCTYPE html>
    <html lang='en' class='h-full bg-slate-950 text-slate-100'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>404 - File Not Found</title>
        <script src='https://cdn.tailwindcss.com'></script>
    </head>
    <body class='h-full flex flex-col items-center justify-center p-4 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-slate-900 to-black'>
        <div class='text-center'>
            <h1 class='text-9xl font-black text-indigo-500'>404</h1>
            <p class='text-2xl font-bold mt-4 text-white'>Link Expired or File Missing</p>
            <p class='text-slate-400 mt-2 max-w-sm mx-auto'>This share link is invalid, expired, or the shared file has been deleted by the owner.</p>
        </div>
    </body>
    </html>";
    exit;
}

// 3. Authenticate regular dashboard users
if (!isset($_SESSION['user_id'])) {
    header("Location: auth.php");
    exit;
}

// 4. Render Dashboard
require_once 'dashboard.php';
