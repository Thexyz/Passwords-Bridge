<?php
/**
 * XYZ Passwords — self-hosted SQL bridge (reference implementation).
 *
 * Deploy this ONE file on a server you control, next to your own database.
 * It is the only thing that ever holds your DB credentials. The vault app
 * (passwords.xyz.am) sends it ONLY the encrypted vault blob — never your
 * master password, never your derived key, never plaintext passwords. A full
 * compromise of this bridge or its database yields only Argon2id +
 * XChaCha20-Poly1305 ciphertext.
 *
 * Supports MySQL / MariaDB and PostgreSQL via PDO.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * SETUP
 *   1. Copy this file to an HTTPS endpoint on your server, e.g.
 *        https://vault.example.com/vault-bridge.php
 *      It MUST be served over HTTPS. Do not run it over plain HTTP.
 *   2. Fill in the CONFIG block below (DB connection + a long random
 *      ACCESS_TOKEN that you'll also paste into the app).
 *   3. The table is created automatically on first use, or create it yourself:
 *
 *        -- MySQL / MariaDB
 *        CREATE TABLE xyz_vaults (
 *          id VARCHAR(64) PRIMARY KEY,
 *          user_ref VARCHAR(128) NULL,
 *          vault_version INT NOT NULL,
 *          encrypted_blob LONGTEXT NOT NULL,
 *          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 *        );
 *
 *        -- PostgreSQL
 *        CREATE TABLE xyz_vaults (
 *          id VARCHAR(64) PRIMARY KEY,
 *          user_ref VARCHAR(128),
 *          vault_version INT NOT NULL,
 *          encrypted_blob TEXT NOT NULL,
 *          updated_at TIMESTAMPTZ DEFAULT now()
 *        );
 *
 *   4. In the app: Settings → Bring Your Own SQL, paste the endpoint URL,
 *      the ACCESS_TOKEN, and a vault id (any string, e.g. "default").
 * ──────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

/* ============================ CONFIG ============================ */
// PDO DSN for YOUR database:
//   MySQL/MariaDB : 'mysql:host=127.0.0.1;dbname=yourdb;charset=utf8mb4'
//   PostgreSQL    : 'pgsql:host=127.0.0.1;dbname=yourdb'
const DB_DSN  = 'mysql:host=127.0.0.1;dbname=yourdb;charset=utf8mb4';
const DB_USER = 'youruser';
const DB_PASS = 'yourpassword';

// A long random secret. Generate one, e.g.:  openssl rand -hex 32
// Paste this same value into the app's Bring Your Own SQL settings.
const ACCESS_TOKEN = 'CHANGE-ME-to-a-long-random-secret';

// The exact origin of your vault app (for CORS). Default is the hosted app.
const ALLOWED_ORIGIN = 'https://passwords.xyz.am';
/* =============================================================== */

/* ---------------- CORS ---------------- */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === ALLOWED_ORIGIN) {
    header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
    header('Vary: Origin');
}
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function out(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------------- Auth ---------------- */
function bearer(): string {
    $h = '';
    // 1) getallheaders (case-insensitive)
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'authorization') { $h = (string)$v; break; }
        }
    }
    // 2) FastCGI/CGI commonly expose it only via $_SERVER, sometimes REDIRECT_-prefixed
    if ($h === '') { $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''); }
    if ($h === '') { $h = (string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''); }
    return (preg_match('/Bearer\s+(\S+)/i', $h, $m)) ? $m[1] : '';
}
if (ACCESS_TOKEN === 'CHANGE-ME-to-a-long-random-secret') {
    out(['error' => 'Bridge not configured: set ACCESS_TOKEN.'], 500);
}
if (!hash_equals(ACCESS_TOKEN, bearer())) {
    out(['error' => 'Unauthorized'], 401);
}

/* ---------------- Validation: encrypted vaults only ---------------- */
function is_encrypted_vault($v): bool {
    if (!is_array($v)) return false;
    if (array_key_exists('entries', $v)) return false; // plaintext — never stored
    if (!isset($v['vault_version']) || !is_int($v['vault_version'])) return false;
    if (empty($v['ciphertext']) || !is_string($v['ciphertext'])) return false;
    $kdf = $v['kdf'] ?? null;
    if (!is_array($kdf) || ($kdf['algorithm'] ?? '') !== 'argon2id' || empty($kdf['salt'])) return false;
    $enc = $v['encryption'] ?? null;
    if (!is_array($enc) || ($enc['algorithm'] ?? '') !== 'xchacha20-poly1305' || empty($enc['nonce'])) return false;
    return true;
}

/* ---------------- DB ---------------- */
try {
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    out(['error' => 'Database connection failed'], 500);
}
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$blobType = ($driver === 'pgsql') ? 'TEXT' : 'LONGTEXT';
$pdo->exec("CREATE TABLE IF NOT EXISTS xyz_vaults (
    id VARCHAR(64) PRIMARY KEY,
    user_ref VARCHAR(128) NULL,
    vault_version INT NOT NULL,
    encrypted_blob {$blobType} NOT NULL,
    updated_at TIMESTAMP NULL
)");

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id = isset($_GET['id']) ? substr((string)$_GET['id'], 0, 64) : '';

if ($method === 'GET') {
    if ($id === '') out(['error' => 'Missing id'], 400);
    $stmt = $pdo->prepare("SELECT encrypted_blob FROM xyz_vaults WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) out(['error' => 'Not found'], 404);
    out(['vault' => json_decode($row['encrypted_blob'], true)]);
}

if ($method === 'PUT') {
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    $vid  = isset($body['id']) ? substr((string)$body['id'], 0, 64) : $id;
    $vault = $body['vault'] ?? null;
    if ($vid === '') out(['error' => 'Missing id'], 400);
    if (!is_encrypted_vault($vault)) {
        // Refuse anything that isn't a sealed vault — the bridge must never
        // store plaintext, even by mistake.
        out(['error' => 'Body is not a valid encrypted vault'], 422);
    }
    $blob = json_encode($vault, JSON_UNESCAPED_SLASHES);
    $version = (int)$vault['vault_version'];
    $now = gmdate('Y-m-d H:i:s');
    if ($driver === 'pgsql') {
        $sql = "INSERT INTO xyz_vaults (id, vault_version, encrypted_blob, updated_at)
                VALUES (?, ?, ?, ?)
                ON CONFLICT (id) DO UPDATE SET
                  vault_version = EXCLUDED.vault_version,
                  encrypted_blob = EXCLUDED.encrypted_blob,
                  updated_at = EXCLUDED.updated_at";
    } else {
        $sql = "INSERT INTO xyz_vaults (id, vault_version, encrypted_blob, updated_at)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  vault_version = VALUES(vault_version),
                  encrypted_blob = VALUES(encrypted_blob),
                  updated_at = VALUES(updated_at)";
    }
    $pdo->prepare($sql)->execute([$vid, $version, $blob, $now]);
    out(['success' => true]);
}

if ($method === 'DELETE') {
    if ($id === '') out(['error' => 'Missing id'], 400);
    $pdo->prepare("DELETE FROM xyz_vaults WHERE id = ?")->execute([$id]);
    out(['success' => true]);
}

out(['error' => 'Method not allowed'], 405);
