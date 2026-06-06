# PHP File Manager

A zero-dependency, self-hosted web file manager written in pure, modern, object-oriented PHP with an SQLite3 backend, multi-user isolation (chrooting), and zero external cloud service reliance.

[![GitHub license](https://img.shields.io/github/license/RedZONERROR/FileManagerPHP.svg)](https://github.com/RedZONERROR/FileManagerPHP/blob/main/LICENSE)
[![GitHub stars](https://img.shields.io/github/stars/RedZONERROR/FileManagerPHP.svg)](https://github.com/RedZONERROR/FileManagerPHP/stargazers)

---

## Key Features

- **Zero-Dependency Installation**: Intercepts first-launch requests and redirects to a visual Setup Wizard. Generates SQLite3 database, tables, and a cryptographically secure encryption key automatically.
- **Strict Path Isolation (Chrooting)**: Resolves paths via absolute logical canonicalization. Users cannot escape their assigned folder boundaries via directory traversal (`../`) tricks.
- **Offline Security Questions Recovery**: No email service dependencies. Recover admin/user passwords securely using local verification questions (Real Name + Secret Pet Name).
- **Direct Public Raw Downloads**: Generate unique public share links that stream raw file content directly to users with proper MIME headers without requiring login or session tokens.
- **Feature-Rich Explorer**: 
  - Standard operations: Upload (with overwrite toggle), Download, Rename, Delete, Move, and Copy.
  - Multi-select actions: Bulk deletion and folder-to-folder migration.
  - Dynamic folder archiving: Zip entire directories on-the-fly for immediate download.
  - Inline text editor: Edit source files (.txt, .html, .css, .js, .json, .php, etc.) directly in-browser.
- **Administration Console**: Owner accounts can easily register, view, or delete isolated accounts, dynamically maintaining individual storage environments.
- **Modern User Experience**: Sleek UI powered by Tailwind CSS, Lucide Icons, and clean, responsive AJAX controllers for smooth, non-blocking page operations.

---

## File Structure

```text
├── data/                  # Auto-generated write-protected configuration & database directory
│   ├── .htaccess          # Web server rules denying public access to config & DB
│   ├── filemanager.db     # SQLite3 database file
│   └── config_key.php     # AES-256 encryption key
├── storage/               # Root folder for user uploads
│   ├── admin/             # Default isolated root folder for Super Admin
│   └── [username]/        # Isolated roots created dynamically for other users
├── config.php             # System configuration, security checks & DB class
├── installer.php          # Visual setup wizard
├── auth.php               # Login/logout interface & recovery validator
├── index.php              # Main request routing hub & raw file sharing broker
├── api.php                # Unified backend AJAX operations controller
├── dashboard.php          # Responsive explorer UI and sidebar admin utilities
└── README.md              # Documentation
```

---

## Installation & Deployment

### Quick Start (Local Development)

1. Clone the repository into your local environment:
   ```bash
   git clone https://github.com/RedZONERROR/FileManagerPHP.git
   cd FileManagerPHP
   ```
2. Run the built-in PHP development server:
   ```bash
   php -S localhost:8080
   ```
3. Open `http://localhost:8080` in your web browser. You will be automatically redirected to the visual Installation Wizard.

### Production Deployment (Shared Hosting / VPS)

1. Upload the files to your web server (e.g. `public_html` or a subdirectory).
2. Ensure directory write permissions are set so PHP can create the `data/` and `storage/` directories.
3. Access your domain (e.g. `https://yourdomain.com/`) to run the visual Setup Wizard.

---

## Security Model

- **Data Protection**: Sensitive database records (like the owner's real name) are encrypted using **AES-256-CBC** via OpenSSL. Recovery pet names are secured via **PHP BCrypt (`password_hash`)**.
- **Public Share Links**: Sharing generates a secure 32-character token. Reaching `index.php?share=<token>` looks up the record, decrypts the original absolute file path, and streams it. No database path leaks are exposed in the URLs.
- **Directory Traversal Defense**: All input paths pass through `verify_and_resolve_path()`, comparing canonical target structures against the allowed user storage root before any write or read operation executes.
- **CSRF Defense**: Every state-modifying AJAX request requires an HTTP header header matching the user's active session token.
- **Database Isolation**: The database file `filemanager.db` is written inside the `/data` folder. An `.htaccess` file containing Apache rules blocks all external web access to prevent direct database downloads.

---

## License

This project is open-source and licensed under the terms of the MIT License.
