<?php
/**
 * dashboard.php - Main dashboard presentation layer and AJAX client code
 */

if (!isset($_SESSION['user_id'])) {
    header("Location: auth.php");
    exit;
}

$currentUser = $_SESSION['username'];
$currentRole = $_SESSION['role'];
$csrfToken = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-950 text-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
    <title>Mundo PHP File Manager - Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Lucide Icons CDN -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { font-family: 'Outfit', sans-serif; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #0b0f19; }
        ::-webkit-scrollbar-thumb { background: #1e293b; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #4f46e5; }
    </style>
</head>
<body class="h-full flex flex-col bg-slate-950 overflow-hidden">

    <!-- Top Header -->
    <header class="h-16 flex items-center justify-between px-6 bg-slate-900 border-b border-slate-800 shrink-0">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-600 flex items-center justify-center shadow-lg shadow-indigo-500/10">
                <i data-lucide="folder-open" class="w-5 h-5 text-white"></i>
            </div>
            <div>
                <h1 class="text-md font-bold tracking-tight text-white leading-tight">Mundo FileManager</h1>
                <p class="text-xs text-indigo-400 font-medium">Zero-Dependency Cloud Portal</p>
            </div>
        </div>

        <div class="flex items-center gap-4">
            <div class="text-right hidden sm:block">
                <div class="text-sm font-semibold text-white"><?php echo htmlspecialchars($currentUser); ?></div>
                <div class="text-xs text-slate-400 capitalize"><?php echo htmlspecialchars($currentRole); ?></div>
            </div>
            
            <a href="auth.php?action=logout" class="p-2.5 rounded-xl bg-slate-800 hover:bg-rose-500/10 border border-slate-700 hover:border-rose-500/30 text-slate-400 hover:text-rose-400 transition" title="Log Out">
                <i data-lucide="log-out" class="w-5 h-5"></i>
            </a>
        </div>
    </header>

    <div class="flex flex-1 overflow-hidden">
        <!-- Sidebar -->
        <aside class="w-64 bg-slate-900/60 border-r border-slate-800 p-4 flex flex-col justify-between shrink-0 hidden md:flex">
            <div class="space-y-6">
                <div>
                    <h3 class="px-3 text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Explorer</h3>
                    <nav class="space-y-1">
                        <button onclick="navigateTo('')" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium bg-indigo-600/10 text-indigo-400 border border-indigo-500/20 transition">
                            <i data-lucide="hard-drive" class="w-4 h-4"></i>
                            <span>All Files</span>
                        </button>
                    </nav>
                </div>
                
                <?php if ($currentRole === 'owner'): ?>
                <div>
                    <h3 class="px-3 text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Administration</h3>
                    <nav class="space-y-1">
                        <button onclick="toggleAdminPanel(true)" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition">
                            <i data-lucide="users" class="w-4 h-4"></i>
                            <span>Manage Users</span>
                        </button>
                    </nav>
                </div>
                <?php endif; ?>
            </div>

            <!-- Upload quick card -->
            <div class="p-4 rounded-xl border border-slate-800 bg-slate-950/40">
                <h4 class="text-xs font-semibold text-white mb-2">Upload Files</h4>
                <div class="space-y-3">
                    <label class="flex items-center gap-2 cursor-pointer text-xs font-medium text-indigo-400 hover:text-indigo-300 transition">
                        <input type="file" id="sidebar-upload-input" class="hidden" multiple onchange="handleSidebarFiles(this)">
                        <i data-lucide="plus" class="w-4 h-4"></i>
                        <span>Select files...</span>
                    </label>
                    <label class="flex items-center gap-2 text-xs text-slate-400 cursor-pointer">
                        <input type="checkbox" id="sidebar-overwrite-toggle" class="rounded border-slate-800 bg-slate-950 text-indigo-600 focus:ring-indigo-600">
                        <span>Overwrite existing</span>
                    </label>
                </div>
            </div>
        </aside>

        <!-- Main Explorer Area -->
        <main class="flex-1 flex flex-col overflow-hidden bg-slate-950">
            <!-- Breadcrumbs / Action Bar -->
            <div class="p-4 bg-slate-900/40 border-b border-slate-800/60 flex flex-wrap items-center justify-between gap-4 shrink-0">
                <!-- Directory Breadcrumbs -->
                <div class="flex items-center gap-2 overflow-x-auto py-1" id="breadcrumbs">
                    <!-- Dynamic breadcrumbs inject -->
                </div>

                <!-- Action Button Group -->
                <div class="flex items-center gap-2">
                    <button onclick="openCreateFolderModal()" class="flex items-center gap-2 px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm font-medium border border-slate-700 transition">
                        <i data-lucide="folder-plus" class="w-4 h-4 text-indigo-400"></i>
                        <span class="hidden sm:inline">New Folder</span>
                    </button>
                    <button onclick="triggerBulkDelete()" class="flex items-center gap-2 px-3 py-2 rounded-xl bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 text-sm font-medium border border-rose-500/20 transition hidden" id="bulk-delete-btn">
                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                        <span>Delete Selected</span>
                    </button>
                    <!-- Mobile Admin button -->
                    <?php if ($currentRole === 'owner'): ?>
                    <button onclick="toggleAdminPanel(true)" class="md:hidden flex items-center gap-2 px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm font-medium border border-slate-700 transition">
                        <i data-lucide="users" class="w-4 h-4"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Upload progress bar container -->
            <div id="upload-progress-container" class="px-6 py-3 border-b border-slate-800 bg-slate-900/10 hidden shrink-0">
                <div class="flex items-center justify-between text-xs text-indigo-400 mb-1.5">
                    <span id="upload-progress-status">Uploading files...</span>
                    <span id="upload-progress-percent">0%</span>
                </div>
                <div class="w-full bg-slate-800 rounded-full h-1.5">
                    <div id="upload-progress-bar" class="bg-indigo-600 h-1.5 rounded-full transition-all duration-300" style="width: 0%"></div>
                </div>
            </div>

            <!-- File Explorer Table/Grid Container -->
            <div class="flex-1 overflow-y-auto p-6">
                <!-- Dropzone Area -->
                <div id="dropzone" class="h-full border-2 border-dashed border-slate-800 rounded-2xl flex flex-col items-center justify-center p-8 transition-colors duration-300 hover:border-indigo-500/30 overflow-y-auto">
                    
                    <div id="file-list-wrapper" class="w-full hidden">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="border-b border-slate-800 text-slate-400 text-xs font-semibold uppercase tracking-wider">
                                    <th class="py-3 w-8">
                                        <input type="checkbox" id="select-all" class="rounded border-slate-800 bg-slate-900 text-indigo-600 focus:ring-indigo-600" onchange="toggleSelectAll(this)">
                                    </th>
                                    <th class="py-3">Name</th>
                                    <th class="py-3 hidden sm:table-cell">Size</th>
                                    <th class="py-3 hidden md:table-cell">Last Modified</th>
                                    <th class="py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="file-list" class="divide-y divide-slate-800/50 text-sm">
                                <!-- Dynamic rows -->
                            </tbody>
                        </table>
                    </div>

                    <!-- Empty state -->
                    <div id="empty-state" class="text-center flex flex-col items-center justify-center">
                        <div class="w-16 h-16 rounded-2xl bg-slate-900 flex items-center justify-center border border-slate-800 text-slate-400 mb-4">
                            <i data-lucide="cloud-off" class="w-8 h-8"></i>
                        </div>
                        <h3 class="text-lg font-bold text-white">This folder is empty</h3>
                        <p class="text-slate-400 text-sm mt-1 max-w-xs">Drag and drop files here to upload, or use the menu buttons to start organizing.</p>
                    </div>

                </div>
            </div>
        </main>
    </div>

    <!-- Modals -->
    
    <!-- 1. Text File Editor Modal -->
    <div id="editor-modal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="w-full max-w-4xl bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl flex flex-col h-[85vh]">
            <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between shrink-0">
                <div>
                    <h3 class="text-md font-bold text-white" id="editor-filename">Edit File</h3>
                    <p class="text-xs text-slate-400 mt-0.5" id="editor-filepath"></p>
                </div>
                <button onclick="closeEditorModal()" class="text-slate-400 hover:text-white transition">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <div class="flex-1 p-6 overflow-hidden">
                <textarea id="editor-textarea" class="w-full h-full bg-slate-950 border border-slate-800 rounded-xl p-4 font-mono text-sm text-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent resize-none"></textarea>
            </div>
            <div class="px-6 py-4 border-t border-slate-800 flex items-center justify-end gap-3 shrink-0">
                <button onclick="closeEditorModal()" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-sm font-medium transition">
                    Cancel
                </button>
                <button onclick="saveFileContent()" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium shadow-lg shadow-indigo-500/10 transition">
                    Save Changes
                </button>
            </div>
        </div>
    </div>

    <!-- 2. Create Folder Modal -->
    <div id="create-folder-modal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="w-full max-w-sm bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl p-6">
            <h3 class="text-md font-bold text-white mb-4">Create New Folder</h3>
            <input type="text" id="new-folder-name" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition mb-6" placeholder="Folder Name">
            <div class="flex items-center justify-end gap-3">
                <button onclick="closeCreateFolderModal()" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-sm font-medium transition">
                    Cancel
                </button>
                <button onclick="createFolder()" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium transition">
                    Create
                </button>
            </div>
        </div>
    </div>

    <!-- 3. Rename Modal -->
    <div id="rename-modal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="w-full max-w-sm bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl p-6">
            <h3 class="text-md font-bold text-white mb-4">Rename Item</h3>
            <input type="text" id="rename-item-name" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition mb-6">
            <input type="hidden" id="rename-item-old">
            <div class="flex items-center justify-end gap-3">
                <button onclick="closeRenameModal()" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-sm font-medium transition">
                    Cancel
                </button>
                <button onclick="renameItem()" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium transition">
                    Rename
                </button>
            </div>
        </div>
    </div>

    <!-- 4. Share Links Modal -->
    <div id="share-modal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="w-full max-w-md bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl p-6">
            <h3 class="text-md font-bold text-white mb-2">Direct Link Public Sharing</h3>
            <p class="text-xs text-slate-400 mb-4">Anyone with this link can view or download this raw file directly, no token required.</p>
            
            <div class="space-y-4">
                <div class="bg-slate-950 border border-slate-800 rounded-xl p-3 flex items-center justify-between gap-3">
                    <span id="share-link-text" class="text-sm text-indigo-400 font-medium truncate flex-1">Generating...</span>
                    <button onclick="copyShareLink()" class="p-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 transition shrink-0" title="Copy Link">
                        <i data-lucide="copy" class="w-4 h-4"></i>
                    </button>
                </div>
            </div>
            
            <div class="flex items-center justify-end gap-3 mt-6">
                <button onclick="disableSharing()" class="px-4 py-2 rounded-xl bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 text-sm font-medium transition" id="disable-share-btn">
                    Disable Share Link
                </button>
                <button onclick="closeShareModal()" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-sm font-medium transition">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- 5. Admin Panel User Management Modal -->
    <?php if ($currentRole === 'owner'): ?>
    <div id="admin-modal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="w-full max-w-3xl bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl flex flex-col h-[80vh]">
            <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between shrink-0">
                <h3 class="text-md font-bold text-white">Manage User Accounts</h3>
                <button onclick="toggleAdminPanel(false)" class="text-slate-400 hover:text-white transition">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            
            <div class="flex-1 p-6 overflow-y-auto space-y-8">
                <!-- Create User -->
                <div class="bg-slate-950/40 border border-slate-800/80 rounded-2xl p-6">
                    <h4 class="text-sm font-semibold text-indigo-400 mb-4">Add Isolated User Account</h4>
                    <form id="create-user-form" onsubmit="createUser(event)" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Username</label>
                            <input type="text" id="admin-user-username" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Password (Min 8 chars)</label>
                            <input type="password" id="admin-user-password" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Real Name</label>
                            <input type="text" id="admin-user-real-name" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Secret Pet Name (Recovery)</label>
                            <input type="text" id="admin-user-pet-name" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition text-sm">
                        </div>
                        <div class="md:col-span-2 flex justify-end">
                            <button type="submit" class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-semibold text-sm transition">
                                Create Account
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Existing Users List -->
                <div>
                    <h4 class="text-sm font-semibold text-white mb-4">Existing Users</h4>
                    <div class="overflow-x-auto border border-slate-800 rounded-2xl bg-slate-950/20">
                        <table class="w-full text-left border-collapse text-sm">
                            <thead>
                                <tr class="bg-slate-900 border-b border-slate-800 text-slate-400 text-xs font-semibold uppercase tracking-wider">
                                    <th class="p-3">Username</th>
                                    <th class="p-3">Role</th>
                                    <th class="p-3">Created At</th>
                                    <th class="p-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="admin-users-list" class="divide-y divide-slate-800/40">
                                <!-- Users list populated via JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Toast Notification Container -->
    <div id="toast-container" class="fixed top-6 right-6 z-[9999] flex flex-col gap-3"></div>

    <!-- Client-side Javascript Code -->
    <script>
        let currentPath = '';
        let selectedItems = new Set();
        let activeSharePath = '';

        // Initialize Lucide Icons and fetch default directory
        document.addEventListener('DOMContentLoaded', () => {
            navigateTo('');
            
            // Drag and drop events on dropzone
            const dropzone = document.getElementById('dropzone');
            
            window.addEventListener('dragover', (e) => e.preventDefault());
            window.addEventListener('drop', (e) => e.preventDefault());

            dropzone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropzone.classList.add('border-indigo-500', 'bg-indigo-500/5');
            });

            dropzone.addEventListener('dragleave', () => {
                dropzone.classList.remove('border-indigo-500', 'bg-indigo-500/5');
            });

            dropzone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropzone.classList.remove('border-indigo-500', 'bg-indigo-500/5');
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    uploadFiles(files);
                }
            });
        });

        // Toast Helper
        function showToast(message, type = 'success') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            
            toast.className = `flex items-center gap-3 px-4 py-3 rounded-xl border text-sm shadow-xl transition-all duration-300 transform translate-y-2 opacity-0 ` +
                (type === 'success' 
                    ? 'bg-emerald-950/80 border-emerald-500/30 text-emerald-400' 
                    : 'bg-rose-950/80 border-rose-500/30 text-rose-400');
            
            const icon = type === 'success' ? 'check-circle' : 'alert-circle';
            toast.innerHTML = `<i data-lucide="${icon}" class="w-5 h-5 shrink-0"></i><span>${message}</span>`;
            
            container.appendChild(toast);
            lucide.createIcons();

            setTimeout(() => {
                toast.classList.remove('translate-y-2', 'opacity-0');
            }, 50);

            setTimeout(() => {
                toast.classList.add('translate-y-2', 'opacity-0');
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }

        // Fetch API request wrapper helper
        async function apiCall(action, method = 'GET', body = null, headers = {}) {
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            const url = `api.php?action=${action}`;
            
            const fetchOptions = {
                method: method,
                headers: {
                    'X-CSRF-Token': csrfToken,
                    ...headers
                }
            };

            if (body) {
                if (body instanceof FormData) {
                    fetchOptions.body = body;
                } else {
                    fetchOptions.headers['Content-Type'] = 'application/x-www-form-urlencoded';
                    fetchOptions.body = new URLSearchParams(body).toString();
                }
            }

            const response = await fetch(url, fetchOptions);
            const data = await response.json();
            
            if (data.status === 'error') {
                throw new Error(data.message);
            }
            return data;
        }

        // Traverse directories
        async function navigateTo(path) {
            currentPath = path;
            selectedItems.clear();
            updateBulkDeleteButton();
            renderBreadcrumbs();
            
            try {
                const response = await apiCall(`list&path=${encodeURIComponent(path)}`);
                renderFiles(response.data);
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        // Breadcrumbs rendering
        function renderBreadcrumbs() {
            const container = document.getElementById('breadcrumbs');
            container.innerHTML = '';
            
            // Root
            const rootBtn = document.createElement('button');
            rootBtn.className = 'flex items-center gap-1 text-sm text-slate-400 hover:text-white transition font-medium shrink-0';
            rootBtn.innerHTML = `<i data-lucide="hard-drive" class="w-4 h-4"></i><span>All Files</span>`;
            rootBtn.onclick = () => navigateTo('');
            container.appendChild(rootBtn);

            if (currentPath) {
                const parts = currentPath.split('/');
                let accumPath = '';
                
                parts.forEach((part, index) => {
                    accumPath += (index === 0 ? '' : '/') + part;
                    
                    const separator = document.createElement('span');
                    separator.className = 'text-slate-600 text-xs shrink-0';
                    separator.innerHTML = '&sol;';
                    container.appendChild(separator);

                    const partBtn = document.createElement('button');
                    partBtn.className = 'text-sm text-slate-400 hover:text-white transition font-medium shrink-0';
                    partBtn.textContent = part;
                    const thisPath = accumPath;
                    partBtn.onclick = () => navigateTo(thisPath);
                    container.appendChild(partBtn);
                });
            }
            lucide.createIcons();
        }

        // Render File Table Rows
        function renderFiles(items) {
            const list = document.getElementById('file-list');
            list.innerHTML = '';

            const wrapper = document.getElementById('file-list-wrapper');
            const empty = document.getElementById('empty-state');

            if (items.length === 0) {
                wrapper.classList.add('hidden');
                empty.classList.remove('hidden');
                return;
            }

            wrapper.classList.remove('hidden');
            empty.classList.add('hidden');

            items.forEach(item => {
                const tr = document.createElement('tr');
                tr.className = 'border-b border-slate-900/50 hover:bg-slate-900/40 text-slate-300 transition-colors';
                
                // Get matching icon
                let icon = 'file-text';
                if (item.is_dir) {
                    icon = 'folder';
                } else {
                    const ext = item.name.split('.').pop().toLowerCase();
                    if (['zip', 'rar', 'tar', 'gz'].includes(ext)) icon = 'archive';
                    else if (['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'].includes(ext)) icon = 'image';
                    else if (['html', 'css', 'js', 'json', 'php', 'xml'].includes(ext)) icon = 'file-code';
                    else if (ext === 'pdf') icon = 'file-digit';
                }

                const sizeText = item.is_dir ? '-' : formatBytes(item.size);
                const dateText = new Date(item.mtime * 1000).toLocaleString();

                // Build Actions
                let actionHtml = '';
                if (item.is_dir) {
                    actionHtml = `
                        <button onclick="downloadFolderZip('${item.path}')" class="p-1.5 rounded-lg bg-slate-800/80 hover:bg-slate-700 text-indigo-400 transition" title="Zip Download">
                            <i data-lucide="archive" class="w-4 h-4"></i>
                        </button>
                    `;
                } else {
                    const editable = ['txt','html','css','js','json','php','xml','md','sh','bat'].includes(item.name.split('.').pop().toLowerCase());
                    if (editable) {
                        actionHtml += `
                            <button onclick="openEditorModal('${item.path}')" class="p-1.5 rounded-lg bg-slate-800/80 hover:bg-slate-700 text-emerald-400 transition mr-1.5" title="Edit inline">
                                <i data-lucide="edit-3" class="w-4 h-4"></i>
                            </button>
                        `;
                    }
                    actionHtml += `
                        <button onclick="openShareModal('${item.path}', '${item.share_token || ''}')" class="p-1.5 rounded-lg bg-slate-800/80 hover:bg-slate-700 text-sky-400 transition mr-1.5" title="Get direct raw link">
                            <i data-lucide="share-2" class="w-4 h-4"></i>
                        </button>
                        <a href="api.php?action=download&file_path=${encodeURIComponent(item.path)}" target="_blank" class="inline-block p-1.5 rounded-lg bg-slate-800/80 hover:bg-slate-700 text-indigo-400 transition mr-1.5" title="Download">
                            <i data-lucide="download" class="w-4 h-4"></i>
                        </a>
                    `;
                }

                actionHtml += `
                    <button onclick="openRenameModal('${item.name}')" class="p-1.5 rounded-lg bg-slate-800/80 hover:bg-slate-700 text-yellow-400 transition mr-1.5" title="Rename">
                        <i data-lucide="pencil" class="w-4 h-4"></i>
                    </button>
                    <button onclick="deleteSingleItem('${item.path}')" class="p-1.5 rounded-lg bg-slate-800/80 hover:bg-rose-500/20 text-rose-400 transition" title="Delete">
                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                    </button>
                `;

                tr.innerHTML = `
                    <td class="py-3">
                        <input type="checkbox" class="item-checkbox rounded border-slate-800 bg-slate-900 text-indigo-600 focus:ring-indigo-600" data-path="${item.path}" onchange="toggleItemSelect(this)">
                    </td>
                    <td class="py-3 font-medium">
                        ${item.is_dir 
                            ? `<button onclick="navigateTo('${item.path}')" class="flex items-center gap-2.5 text-white hover:text-indigo-400 transition text-left">
                                 <i data-lucide="${icon}" class="w-5 h-5 text-indigo-400 shrink-0"></i>
                                 <span>${item.name}</span>
                               </button>`
                            : `<div class="flex items-center gap-2.5">
                                 <i data-lucide="${icon}" class="w-5 h-5 text-slate-400 shrink-0"></i>
                                 <span>${item.name}</span>
                               </div>`
                        }
                    </td>
                    <td class="py-3 hidden sm:table-cell text-slate-400 font-mono text-xs">${sizeText}</td>
                    <td class="py-3 hidden md:table-cell text-slate-400 text-xs">${dateText}</td>
                    <td class="py-3 text-right whitespace-nowrap">${actionHtml}</td>
                `;
                list.appendChild(tr);
            });
            lucide.createIcons();
        }

        // Byte Formatter
        function formatBytes(bytes, decimals = 2) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }

        // Multi-select actions
        function toggleSelectAll(master) {
            const checkboxes = document.querySelectorAll('.item-checkbox');
            checkboxes.forEach(chk => {
                chk.checked = master.checked;
                toggleItemSelect(chk);
            });
        }

        function toggleItemSelect(chk) {
            const path = chk.getAttribute('data-path');
            if (chk.checked) {
                selectedItems.add(path);
            } else {
                selectedItems.delete(path);
            }
            updateBulkDeleteButton();
        }

        function updateBulkDeleteButton() {
            const btn = document.getElementById('bulk-delete-btn');
            if (selectedItems.size > 0) {
                btn.classList.remove('hidden');
            } else {
                btn.classList.add('hidden');
            }
        }

        // Upload functionality
        function handleSidebarFiles(input) {
            if (input.files.length > 0) {
                uploadFiles(input.files);
            }
        }

        function uploadFiles(files) {
            const container = document.getElementById('upload-progress-container');
            const bar = document.getElementById('upload-progress-bar');
            const percent = document.getElementById('upload-progress-percent');
            const status = document.getElementById('upload-progress-status');
            const overwrite = document.getElementById('sidebar-overwrite-toggle').checked;

            container.classList.remove('hidden');
            let uploaded = 0;
            const total = files.length;

            const uploadNext = (index) => {
                if (index >= total) {
                    setTimeout(() => {
                        container.classList.add('hidden');
                        bar.style.width = '0%';
                        percent.textContent = '0%';
                    }, 2000);
                    showToast("All files processed.");
                    navigateTo(currentPath);
                    return;
                }

                const file = files[index];
                status.textContent = `Uploading ${file.name} (${index + 1}/${total})...`;
                
                const formData = new FormData();
                formData.append('file', file);
                formData.append('dest_dir', currentPath);
                formData.append('overwrite', overwrite ? 'true' : 'false');
                formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'api.php?action=upload', true);
                xhr.setRequestHeader('X-CSRF-Token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

                xhr.upload.onprogress = (e) => {
                    if (e.lengthComputable) {
                        const pct = Math.round((e.loaded / e.total) * 100);
                        bar.style.width = `${pct}%`;
                        percent.textContent = `${pct}%`;
                    }
                };

                xhr.onload = () => {
                    const res = JSON.parse(xhr.responseText);
                    if (xhr.status === 200 && res.status === 'success') {
                        uploadNext(index + 1);
                    } else {
                        showToast(`Failed to upload ${file.name}: ${res.message}`, 'error');
                        // continue with next
                        uploadNext(index + 1);
                    }
                };

                xhr.onerror = () => {
                    showToast("A network error occurred during upload.", "error");
                    container.classList.add('hidden');
                };

                xhr.send(formData);
            };

            uploadNext(0);
        }

        // Folder zipping
        function downloadFolderZip(folderPath) {
            window.open(`api.php?action=zip&path=${encodeURIComponent(folderPath)}`, '_blank');
        }

        // Create Folder Modal Control
        function openCreateFolderModal() {
            document.getElementById('new-folder-name').value = '';
            document.getElementById('create-folder-modal').classList.remove('hidden');
        }
        function closeCreateFolderModal() {
            document.getElementById('create-folder-modal').classList.add('hidden');
        }
        async function createFolder() {
            const folderName = document.getElementById('new-folder-name').value.trim();
            if (!folderName) return;
            try {
                const res = await apiCall('create_folder', 'POST', {
                    parent_dir: currentPath,
                    folder_name: folderName
                });
                showToast(res.message);
                closeCreateFolderModal();
                navigateTo(currentPath);
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        // Rename Modal Control
        function openRenameModal(oldName) {
            document.getElementById('rename-item-name').value = oldName;
            document.getElementById('rename-item-old').value = oldName;
            document.getElementById('rename-modal').classList.remove('hidden');
        }
        function closeRenameModal() {
            document.getElementById('rename-modal').classList.add('hidden');
        }
        async function renameItem() {
            const oldName = document.getElementById('rename-item-old').value;
            const newName = document.getElementById('rename-item-name').value.trim();
            if (!newName || oldName === newName) return;

            try {
                const res = await apiCall('rename', 'POST', {
                    parent_dir: currentPath,
                    old_name: oldName,
                    new_name: newName
                });
                showToast(res.message);
                closeRenameModal();
                navigateTo(currentPath);
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        // Delete handlers
        async function deleteSingleItem(itemPath) {
            if (!confirm("Are you sure you want to delete this item?")) return;
            try {
                const res = await apiCall('delete', 'POST', {
                    items: [itemPath]
                });
                showToast(res.message);
                navigateTo(currentPath);
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        async function triggerBulkDelete() {
            if (selectedItems.size === 0) return;
            if (!confirm(`Are you sure you want to delete the ${selectedItems.size} selected items?`)) return;

            try {
                const res = await apiCall('delete', 'POST', {
                    items: Array.from(selectedItems)
                });
                showToast(res.message);
                navigateTo(currentPath);
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        // Inline Code Editor
        async function openEditorModal(filePath) {
            document.getElementById('editor-textarea').value = 'Loading file contents...';
            document.getElementById('editor-filename').textContent = filePath.split('/').pop();
            document.getElementById('editor-filepath').textContent = filePath;
            document.getElementById('editor-modal').classList.remove('hidden');

            try {
                const res = await apiCall(`get_content&path=${encodeURIComponent(filePath)}`);
                document.getElementById('editor-textarea').value = res.content;
            } catch (err) {
                showToast(err.message, 'error');
                closeEditorModal();
            }
        }

        function closeEditorModal() {
            document.getElementById('editor-modal').classList.add('hidden');
        }

        async function saveFileContent() {
            const filePath = document.getElementById('editor-filepath').textContent;
            const content = document.getElementById('editor-textarea').value;

            try {
                const res = await apiCall('save_content', 'POST', {
                    path: filePath,
                    content: content
                });
                showToast(res.message);
                closeEditorModal();
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        // Sharing System
        async function openShareModal(filePath, existingToken) {
            activeSharePath = filePath;
            const textEl = document.getElementById('share-link-text');
            const disableBtn = document.getElementById('disable-share-btn');

            if (existingToken) {
                const protocol = window.location.protocol;
                const pathStr = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
                const shareUrl = `${protocol}//${window.location.host}${pathStr}/index.php?share=${existingToken}`;
                textEl.textContent = shareUrl;
                disableBtn.classList.remove('hidden');
            } else {
                textEl.textContent = "Generating...";
                disableBtn.classList.add('hidden');
            }

            document.getElementById('share-modal').classList.remove('hidden');

            if (!existingToken) {
                try {
                    const res = await apiCall('share', 'POST', { path: filePath });
                    textEl.textContent = res.share_url;
                    disableBtn.classList.remove('hidden');
                    // Refresh listing to update cache
                    navigateTo(currentPath);
                } catch (err) {
                    showToast(err.message, 'error');
                }
            }
        }

        function closeShareModal() {
            document.getElementById('share-modal').classList.add('hidden');
        }

        function copyShareLink() {
            const link = document.getElementById('share-link-text').textContent;
            navigator.clipboard.writeText(link).then(() => {
                showToast("Share link copied to clipboard!");
            });
        }

        async function disableSharing() {
            if (!activeSharePath) return;
            try {
                const res = await apiCall('unshare', 'POST', { path: activeSharePath });
                showToast(res.message);
                closeShareModal();
                navigateTo(currentPath);
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        // Admin Panel Functions
        <?php if ($currentRole === 'owner'): ?>
        async function toggleAdminPanel(show) {
            const modal = document.getElementById('admin-modal');
            if (show) {
                modal.classList.remove('hidden');
                loadAdminUsers();
            } else {
                modal.classList.add('hidden');
            }
        }

        async function loadAdminUsers() {
            const list = document.getElementById('admin-users-list');
            list.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-slate-400">Loading users...</td></tr>';
            
            try {
                const res = await apiCall('admin_list_users');
                list.innerHTML = '';
                
                res.data.forEach(user => {
                    const tr = document.createElement('tr');
                    tr.className = 'border-b border-slate-900 text-slate-300';
                    
                    const isSelf = user.username === '<?php echo $currentUser; ?>';
                    const actionBtn = isSelf 
                        ? `<span class="text-xs text-indigo-400 font-semibold px-2 py-1 rounded bg-indigo-500/10 border border-indigo-500/20">Active Session</span>`
                        : `<button onclick="deleteUser(${user.id})" class="text-rose-400 hover:text-rose-300 text-xs font-semibold px-2.5 py-1.5 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 border border-rose-500/20 transition">Delete</button>`;

                    tr.innerHTML = `
                        <td class="p-3 font-semibold text-white">${user.username}</td>
                        <td class="p-3 capitalize">${user.role}</td>
                        <td class="p-3 text-slate-400 text-xs">${user.created_at}</td>
                        <td class="p-3 text-right">${actionBtn}</td>
                    `;
                    list.appendChild(tr);
                });
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        async function createUser(e) {
            e.preventDefault();
            const username = document.getElementById('admin-user-username').value.trim();
            const password = document.getElementById('admin-user-password').value;
            const realName = document.getElementById('admin-user-real-name').value.trim();
            const petName = document.getElementById('admin-user-pet-name').value.trim();

            try {
                const res = await apiCall('admin_create_user', 'POST', {
                    username: username,
                    password: password,
                    real_name: realName,
                    pet_name: petName
                });
                showToast(res.message);
                document.getElementById('create-user-form').reset();
                loadAdminUsers();
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        async function deleteUser(userId) {
            if (!confirm("Are you sure you want to delete this user account? All their isolated storage contents will be permanently erased!")) return;
            try {
                const res = await apiCall('admin_delete_user', 'POST', { user_id: userId });
                showToast(res.message);
                loadAdminUsers();
            } catch (err) {
                showToast(err.message, 'error');
            }
        }
        <?php endif; ?>
    </script>
</body>
</html>
