# XYZ Passwords — Self-Hosted SQL Bridge

A tiny HTTPS endpoint that lets [XYZ Passwords](https://passwords.xyz.am) store
your **encrypted vault** in a database you run yourself — MySQL, MariaDB, or
PostgreSQL.

## What it does (and doesn't)

- ✅ Stores **only the encrypted blob** (Argon2id + XChaCha20-Poly1305 ciphertext).
- ✅ Holds your database credentials on **your** server, never in the browser.
- ❌ Never sees your master password, your encryption key, or any plaintext.

The app encrypts everything on your device before it's sent here, and decrypts
it on your device after it's fetched. A full compromise of this bridge or its
database yields only ciphertext.

## Files

| File | Purpose |
|------|---------|
| `vault-bridge.php` | The bridge. Deploy on your server; fill in the CONFIG block. |
| `.htaccess` | CORS + passes the `Authorization` header to PHP (needed on most shared hosts). Put it in the same folder. |
| `schema.sql` | The table (the bridge also creates it automatically). |

## Setup

1. **Create the table** (optional — the bridge auto-creates it). See `schema.sql`.

2. **Upload `vault-bridge.php`** to an **HTTPS** folder with a valid TLS cert,
   e.g. `https://yourserver.com/vault-bridge.php`. Edit the CONFIG block:

   ```php
   const DB_DSN  = 'mysql:host=127.0.0.1;dbname=yourdb;charset=utf8mb4';
   // PostgreSQL: 'pgsql:host=127.0.0.1;dbname=yourdb'
   const DB_USER = 'youruser';
   const DB_PASS = 'yourpassword';
   const ACCESS_TOKEN = '...';                 // openssl rand -hex 32
   const ALLOWED_ORIGIN = 'https://passwords.xyz.am';
   ```

3. **Upload `.htaccess`** into the **same folder**. It forces correct CORS and
   makes sure the bearer token reaches PHP.

4. **In the app:** Settings → Storage → **Bring Your Own SQL**. Enter the bridge
   URL, the `ACCESS_TOKEN`, and a vault id (e.g. `default`), then **Save &
   connect**. On another device, enter the same details and **Pull (replace
   local)**, then unlock with your master password.

## API

All requests authenticate with `Authorization: Bearer <ACCESS_TOKEN>`.

| Method | Path | Body | Result |
|--------|------|------|--------|
| `GET` | `?id=<vaultId>` | – | `{ "vault": <EncryptedVault> }` or `404` |
| `PUT` | – | `{ "id": "<vaultId>", "vault": <EncryptedVault> }` | `{ "success": true }` |
| `DELETE` | `?id=<vaultId>` | – | `{ "success": true }` |

The bridge **refuses** any body that isn't a valid encrypted vault (it rejects
anything containing plaintext `entries`).

## Troubleshooting

- **"Failed to fetch" in the app** — CORS. Confirm the bridge is HTTPS with a
  valid cert, the `.htaccess` is in the same folder, and a parent server config
  isn't stripping CORS headers. The preflight must return
  `Access-Control-Allow-Origin: https://passwords.xyz.am` and allow
  `GET, PUT, DELETE, OPTIONS`.
- **"HTTP 401"** — token mismatch, or your host (FastCGI/CGI) is stripping the
  `Authorization` header. Make sure the `ACCESS_TOKEN` matches exactly
  (case-sensitive, no trailing spaces) **and** the included `.htaccess` is
  present — it passes the header through.
- **"Body is not a valid encrypted vault" (422)** — a safety check; you should
  never hit it in normal use.

## Requirements

PHP 7.4+ with PDO (`pdo_mysql` and/or `pdo_pgsql`), Apache with `mod_headers`
and `mod_rewrite` (for the `.htaccess`). Nginx users: replicate the `.htaccess`
headers in your server block.

## Security notes

- Keep `ACCESS_TOKEN` secret. It grants read/write to your encrypted blob — but
  cannot decrypt it.
- There is no master-password recovery. Keep an encrypted export as backup.
- Serve over HTTPS only.
