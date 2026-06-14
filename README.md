# XYZ Passwords

A **zero-knowledge, local-first password manager** for xyz.am.

The core principle: **xyz.am can never read, decrypt, recover, or access your
saved passwords.** Not by policy — by construction. Your vault is encrypted on
your device with a master password only you know; sync is optional, and even
then only the encrypted blob ever moves.

Ships as a **web app**, a **browser extension**, and a **self-hostable SQL
bridge**.

## Highlights

- 🔐 **Zero-knowledge** — Argon2id key derivation + XChaCha20-Poly1305 authenticated encryption, all on-device.
- 📦 **Local-first** — works offline; your local vault is the source of truth. Sync is opt-in.
- 🗄️ **Your storage, your choice** — Local Only (default), Browser Sync, **Bring Your Own SQL**, or XYZ Encrypted Sync.
- 🧩 **Browser extension** — unlock, autofill on your click, and save-login prompts; same vault format.
- 🛡️ **Hardened** — strict CSP, no third-party scripts, no analytics or trackers, structurally XSS-safe rendering.
- 🧪 **Tested** — Vitest suite covering crypto round-trips, wrong-password/tamper rejection, nonce uniqueness, import/export, and storage adapters.
- 🚫 **No recovery backdoor** — by design. You hold the only key.

## Contents

- [Security model](#security-model)
- [Storage modes](#what-each-storage-mode-stores-and-where)
- [Bring Your Own SQL](#bring-your-own-sql--why-a-bridge) · see also [`bridge/`](bridge/)
- [Project layout](#project-layout)
- [Develop / build / test](#develop--build--test)
- [Deployment](#deployment--passwordsxyzam)
- [Things deliberately not implemented](#things-deliberately-not-implemented-and-why)

## Quick start

```bash
git clone <your-repo-url> xyz-passwords
cd xyz-passwords
npm install
npm run dev          # web app at the printed localhost URL
```

Production build: `npm run build` → static `dist/`. Browser extension:
`npm run build:extension` → `extension-dist/` (load unpacked in your browser).
Run the tests with `npm test`.

---

## Security model

### What happens on your device (always)

1. You choose a **master password**. It is never stored and never transmitted —
   not to xyz.am, not to anyone.
2. The master password is run through **Argon2id** (64 MiB memory, 3 passes,
   random 16-byte salt per vault) to derive a 256-bit vault key. The KDF
   parameters are stored *inside* the vault metadata, so they can be
   strengthened later without breaking existing vaults.
3. Your entries are serialized and sealed with **XChaCha20-Poly1305**
   (authenticated encryption — tampering is detected, not just hidden). Every
   single encryption operation uses a **fresh random 24-byte nonce**; nonces
   are never reused with the same key.
4. Only the resulting **encrypted blob** is ever written to storage:

```json
{
  "vault_version": 1,
  "kdf": {
    "algorithm": "argon2id",
    "salt": "base64",
    "memory": 65536,
    "iterations": 3,
    "parallelism": 1
  },
  "encryption": {
    "algorithm": "xchacha20-poly1305",
    "nonce": "base64"
  },
  "ciphertext": "base64"
}
```

The derived key lives only in memory (as a `Uint8Array`) and is **zeroized
with `sodium.memzero`** when the vault locks — manually, on auto-lock
timeout, or (in the extension) when the popup closes. JavaScript cannot
guarantee that *strings* are wiped from memory; key material is therefore
never held as a string.

### What each storage mode stores, and where

| Mode | Where the encrypted blob lives | Who operates it | Status |
|---|---|---|---|
| **Local Only** (default) | IndexedDB / `chrome.storage.local` on this device | You | ✅ Shipped |
| **Browser Sync** | Your browser vendor's sync service (Google/Mozilla) | Browser vendor | Adapter ready, not in UI |
| **Bring Your Own SQL** | Your own MySQL/MariaDB/PostgreSQL, via a tiny HTTPS bridge **you** host | You | ✅ Shipped (bridge + Settings UI) |
| **XYZ Encrypted Sync** | xyz.am (Premium) | xyz.am | Sync API + SSO handoff shipped |

In **every** mode, what leaves the device is the encrypted blob above and
nothing else. The storage layer enforces this mechanically: every adapter
validates the object shape before persisting and **throws if handed anything
that looks like plaintext entries** (see `BaseStorageAdapter.save` and the
`isEncryptedVault` guard). The `SyncManager` applies the same check.

**XYZ Encrypted Sync expiry policy:** if a Premium membership lapses, the
stored vault is **not deleted**. Downloads keep working forever; syncing new
changes (and adding/editing through sync) resumes when the plan is renewed.

### Bring Your Own SQL — why a bridge

Browsers cannot speak the MySQL/PostgreSQL wire protocol, and shipping SQL
credentials into a web page would expose them to any XSS. So BYO SQL works
through a minimal HTTPS bridge **you host next to your database**; the bridge
receives only encrypted blobs. Suggested schema:

```sql
CREATE TABLE xyz_vaults (
  id VARCHAR(64) PRIMARY KEY,
  user_ref VARCHAR(128),
  vault_version INT NOT NULL,
  encrypted_blob LONGTEXT NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

Your SQL credentials never touch this app at all. The bridge URL + token are
configured on-device. We deliberately did **not** build any flow where the
app collects SQL credentials and a remote service executes queries — that
would expose credentials and break the trust model.

**To use it:**

1. Deploy [`bridge/vault-bridge.php`](bridge/vault-bridge.php) on an HTTPS
   endpoint on your own server, next to your database. Fill in its CONFIG
   block (PDO DSN + DB user/pass + a long random `ACCESS_TOKEN`). It supports
   MySQL/MariaDB and PostgreSQL and creates the table automatically.
2. In the app: **Settings → Bring Your Own SQL**. Paste the bridge URL, the
   `ACCESS_TOKEN`, and a vault id (any string, e.g. `default`). Click **Save &
   connect**.

Behavior: your **local IndexedDB stays the working copy**; after every change
the same encrypted blob is pushed to your bridge (toggleable "auto-push"). On
a new device, configure the bridge and click **Pull (replace local)** — it
downloads the encrypted blob and asks for your master password to unlock it
(the blob carries its own salt/KDF, so the master password is all you need).
The bridge validates that every stored object is a sealed vault and **refuses
plaintext**, the same guard the client enforces.

### Recovery — and what we refuse to build

- xyz.am **cannot** reset or recover your master password. There is no
  backdoor key, no escrow, no support override. If we could recover your
  vault, so could an attacker who compromised us.
- Your recovery kit is: **the encrypted export file + your master password**,
  stored somewhere safe. With those two things you can restore on any device.
- The UI says it plainly: *"If you lose your master password and do not have
  a backup or recovery key, your vault cannot be recovered."*

### Account model

Your xyz.am login (for the app shell, billing, future sync config) is a
**completely separate credential** from your vault master password. xyz.am
stores only minimal account data (`user_id`, `email`, `password_hash`,
`plan`, `created_at`, `last_login_at`) — and for Local Only vaults, xyz.am
stores **nothing at all** about your vault.

### Hardening in the client

- **No third-party anything** in the vault UI: no analytics, ads, tracking
  pixels, external scripts, fonts, or chat widgets.
- **Strict CSP** (`default-src 'none'`; `script-src 'self' 'wasm-unsafe-eval'`
  — the wasm grant is required by libsodium).
- **Structural XSS safety**: there is no `innerHTML` in the codebase; every
  vault field renders through `textContent`, so a hostile entry title cannot
  execute.
- **Auto-lock** after inactivity (configurable), **manual lock**, and (in the
  extension) lock-on-popup-close.
- **Clipboard auto-clear** after copying secrets (configurable; checks it
  isn't stomping on something you copied afterwards).
- **No secret logging** — the crypto, vault, and UI modules contain explicit
  comments marking where logging is forbidden.

---

## Project layout

```
src/
  crypto/    vaultCrypto (Argon2id + XChaCha20-Poly1305), password generator, sodium loader
  vault/     types + isEncryptedVault guard, VaultManager (session, CRUD, auto-lock, export/import)
  storage/   adapter interface + Local IndexedDB, extension local, browser sync,
             BYO SQL (bridge client), XYZ Encrypted Sync (future) adapters
  sync/      SyncManager — handles encrypted blobs only
  ui/        web app (7 screens), XSS-safe DOM helpers, clipboard auto-clear
  extension/ MV3 manifest, popup, content script (detect/fill/save-offer), background
tests/       vitest suite (33 tests)
```

## Develop / build / test

```bash
npm install
npm test               # 33 tests: crypto roundtrip, wrong password, nonce uniqueness,
                       # tamper detection, import/export, adapters refusing plaintext…
npm run typecheck
npm run dev            # web app dev server
npm run build          # web app → dist/
npm run build:extension  # extension → extension-dist/ (chrome://extensions → Load unpacked)
```

## Deployment — passwords.xyz.am

The web app is a static bundle (`dist/`) — no server-side code, no database.

**Deploy it to its own origin, separate from the main xyz.am PHP host.** The
reason is specific to a password manager: a tampered JS bundle would defeat
zero-knowledge entirely (it could capture the master password at the moment
it's typed, before encryption). The main xyz.am server runs a large PHP
attack surface (YOURLS, uploads, user-published HTML at `xyz.am/<name>`), and
those user pages share the apex origin. A dedicated origin on isolated static
hosting removes both the same-origin risk and the shared-server-compromise
risk.

### Recommended: Cloudflare Pages (static, immutable, free)

The DNS for `xyz.am` already lives in Cloudflare, so this is the smoothest path.

```bash
npm run build
npx wrangler login
npx wrangler pages deploy dist --project-name=xyz-passwords
```

Then in the Cloudflare dashboard → the Pages project → **Custom domains** →
add `passwords.xyz.am`. Cloudflare creates the CNAME and provisions the TLS
certificate automatically because the zone is in the same account.

(No-CLI alternative: Workers & Pages → Create → Pages → **Upload assets** →
drag the `dist/` folder in.)

Security headers are applied by **`public/_headers`** (copied into `dist/` on
build) — Cloudflare Pages reads this file automatically. It sets HSTS,
`X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: no-referrer`, a locked
`Permissions-Policy`, cross-origin isolation, and the full strict CSP
(including `frame-ancestors 'none'`, which a `<meta>` CSP cannot express).

### Fallback: Apache / PHP host

If you ever serve from a traditional host instead, put this in the docroot's
`.htaccess` (equivalent to `public/_headers`):

```apache
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
Header always set X-Frame-Options "DENY"
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "no-referrer"
Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
Header always set Content-Security-Policy "default-src 'none'; script-src 'self' 'wasm-unsafe-eval'; style-src 'self'; img-src 'self' data:; connect-src 'self'; font-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'"
```

### Note for the future XYZ Encrypted Sync

That sync API will live on `xyz.am` (a different origin from
`passwords.xyz.am`), so when it ships: add a CORS allow for
`https://passwords.xyz.am` on the endpoint, and add `https://xyz.am` to the
app's CSP `connect-src`. Local Only (the default) makes no network calls, so
nothing is needed for launch.

## Browser extension

Build with `npm run build:extension`, then load `extension-dist/` via
`chrome://extensions` → Developer mode → **Load unpacked**.

- Same vault format and crypto as the web app.
- Local Only storage (`chrome.storage.local`).
- Unlock happens in the popup; **closing the popup locks the vault** (the key
  existed only in popup memory). Manual lock + in-popup inactivity timer too.
- **Autofill only on your click** — never automatic.
- Detects login form submissions and **offers** to save (candidate is parked
  in memory-only `chrome.storage.session` until you accept or dismiss).
- Encrypted import/export via the shared vault format.
- Sends nothing, plaintext or otherwise, to xyz.am.

## Things deliberately not implemented (and why)

- **Server-side master password reset / vault recovery** — impossible without
  giving xyz.am decryption capability. Alternative shipped: user-held
  recovery kit (encrypted export + master password).
- **Plaintext export** — an unencrypted CSV on disk defeats the model.
  Alternative: encrypted export; individual values can be copied when needed.
- **Direct SQL connections / credential collection** — see BYO SQL above.
- **Cloud clipboard / sharing of entries** — any sharing feature must be
  end-to-end encrypted; until designed properly, it's out.
