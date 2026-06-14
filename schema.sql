-- XYZ Passwords — Bring Your Own SQL: table schema.
-- The bridge creates this automatically on first run, or you can create it
-- yourself. It stores ONLY the encrypted vault blob — never plaintext.

-- ── MySQL / MariaDB ───────────────────────────────────────────────
CREATE TABLE xyz_vaults (
  id VARCHAR(64) PRIMARY KEY,
  user_ref VARCHAR(128) NULL,
  vault_version INT NOT NULL,
  encrypted_blob LONGTEXT NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ── PostgreSQL ────────────────────────────────────────────────────
-- CREATE TABLE xyz_vaults (
--   id VARCHAR(64) PRIMARY KEY,
--   user_ref VARCHAR(128),
--   vault_version INT NOT NULL,
--   encrypted_blob TEXT NOT NULL,
--   updated_at TIMESTAMPTZ DEFAULT now()
-- );
