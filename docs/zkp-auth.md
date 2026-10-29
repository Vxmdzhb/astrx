# Optional local-key registration and login

This implements a Schnorr non-interactive proof of knowledge of a private scalar,
using Fiat–Shamir, SHA-512 and libsodium's Ristretto255 group. The server stores a
public key. The Python client keeps the private key and its passphrase locally.
The browser uses ordinary forms: no JavaScript, extension or network connection
from the Python script is needed.

This is new authentication code, **disabled by default**, with no independent
cryptographic audit. The client and database compare-and-swap tests were run in
the development workspace. PHP files were parsed with tree-sitter (not `php -l`), but PHP/Docker/MariaDB were
unavailable there; the PHP integration and actual browser tests below must run
on a development instance before enabling it on Blackhost. Nothing here changes
the live production service.

## User commands

Use Python 3.10+ and a trusted system libsodium 1.0.18 or newer. No pip packages
are required. On Debian/Ubuntu, install `python3` and `libsodium23`; on macOS,
install Python and `libsodium` with your package manager. On NixOS, for example:

```bash
nix shell nixpkgs#python3 nixpkgs#libsodium --command bash
export ASTRX_LIBSODIUM="$(nix build --no-link --print-out-paths nixpkgs#libsodium)/lib/libsodium.so"
```

`ASTRX_LIBSODIUM` is optional on systems where the normal library loader finds
libsodium. It must point to your trusted installed library, never a file supplied
with a login challenge. The client is currently exercised on Linux; Windows is
not a tested target.

Obtain `tools/zkp_client.py` from your reviewed AstrX checkout. Generate a separate
key for this account, **before** opening the registration page:

```bash
umask 077
mkdir -p "$HOME/.config/astrx"
python3 tools/zkp_client.py keygen \
  --key "$HOME/.config/astrx/blackhost.key" \
  --origin https://blackhost.xyz \
  --username alice
```

The script asks for a local passphrase twice. Neither the passphrase nor private
key is pasted into the website. Keep a backup of the encrypted `.key` file and
retain its passphrase independently. The client refuses to overwrite an existing
file and creates new files with mode 0600.

To use the same account through your onion service, repeat `--origin` in the
key-generation command with the **actual** `http://…onion` origin. Do not use a
placeholder onion address. Both origins must also appear in the server allowlist.
Origins have no path or trailing slash. Ordinary HTTP is refused except for v3
onion addresses and explicit localhost/127.0.0.1 development origins.

1. Open registration, enter the same username and select **Local key**.
2. Leave password fields empty. Run the following command and paste the page's
   challenge when prompted:

```bash
python3 tools/zkp_client.py prove \
  --key "$HOME/.config/astrx/blackhost.key" --action register
```

3. Check the action, account and origin displayed by the client. Confirm only
   an action you started. Paste its output into the form's **Proof** field, then
   complete CAPTCHA, invitation, consent and other required fields normally.
4. Complete email verification if the installation requires it.
5. On each subsequent login, select Local key and use the login page's fresh
   challenge with:

```bash
python3 tools/zkp_client.py prove \
  --key "$HOME/.config/astrx/blackhost.key" --action login
```

The proof expires with its five-minute challenge and can be submitted once.
Refresh the form for a new challenge after expiry or a failed submission. An
existing TOTP requirement still follows a successful key proof. The local
passphrase unlocks the key file; it is not a second factor verified by the site.

Existing password accounts can enroll a key in **Settings → Local login key**.
Generate a key using the account's current username, use the Settings challenge
with `--action enroll`, paste the proof and enter the **current account password**
in the website form. Replacing/removing the key requires a fresh password check
and signs out all sessions. This does not remove the account's password method.
One key per account is supported in this version.

For a renamed account, add `--username NEW_NAME` to `prove`; this explicit local
override changes the account bound into the proof, not the private key. A public
key summary is available with `python3 tools/zkp_client.py public --key PATH`.

## Mail and recovery boundaries

The existing Blackhost Dovecot setup uses a password hash from the shared user
database. A Schnorr proof cannot supply an IMAP password. A new key-only account
has an unusable password marker, so it cannot authenticate to that password-based
mail service. This change does not implement Dovecot key authentication or a
separate webmail credential prompt. Existing password users can continue using
password login for webmail. Do not present key-only signup as fully working
webmail signup.

The existing email recovery policy is preserved. When recovery is configured,
it can establish a password without possession of the key. After recovering from
a lost or compromised key, replace or revoke that key in Settings. Recovery does
not silently delete it. Without recovery, a key-only user needs their backed-up
key or an explicit operator recovery process.

Other existing settings that require a current password, including TOTP setup,
keep that requirement. A key-only account must establish a password through its
configured recovery process to use those settings. Existing TOTP login checks
are preserved. Fully passwordless key rotation and recovery are not implemented.

## Install and test the patch bundle

The bundle contains separate patches for the two repositories. Baselines:

- `Vxmdzhb/astrx`: `6451f2d4dc3609b94fcbfd4110f501918b34d60d`
- `Vxmdzhb/cms`: `0349fd33b368c00567cc44621d0233d0d23e99f5`

They are the source snapshots reviewed for the production plan. A fresh HEAD
check was blocked by the workspace network during this implementation. If your
repositories have advanced, use `git apply --check` and review any conflicts.
Do not reset existing work to these commits just to make a patch apply.

For a **development** AstrX checkout, from its root, with the unpacked bundle
path stored in `ZKP_BUNDLE`:

```bash
set -euo pipefail
read -r -p 'Absolute path to the unpacked AstrX-ZKP-auth directory: ' ZKP_BUNDLE
test -f "$ZKP_BUNDLE/astrx.patch"
git switch -c feature/zkp-auth
git apply --check "$ZKP_BUNDLE/astrx.patch"
git apply "$ZKP_BUNDLE/astrx.patch"
composer install --no-interaction --prefer-dist --no-plugins --no-scripts
git apply --check --directory=vendor/astrx/cms "$ZKP_BUNDLE/cms.patch"
git apply --directory=vendor/astrx/cms "$ZKP_BUNDLE/cms.patch"
python3 tests/zkp_client_test.py -v
python3 tests/zkp_sql_test.py -v
php -r 'if (!function_exists("sodium_crypto_scalarmult_ristretto255_base")) { fwrite(STDERR,"Ristretto support missing\n"); exit(1); }'
php tests/zkp_auth_test.php
php tests/user_security_test.php
php tests/review_auth_test.php
php tests/login_secrets_test.php
php tools/check_config.php
php tools/check_lang_parity.php
php tools/check_diagnostics.php
git diff --check
```

The vendor patch is for local integration testing. Composer can replace vendor
files. For a durable release, apply `cms.patch` to your **CMS repository**, commit
and publish that package change, then update AstrX's Composer lock to that actual
CMS revision and commit the AstrX patch. No remote commit identifier has been
invented and neither repository was pushed by this implementation.

The PHP tests require PDO SQLite as well as sodium and the normal CMS extensions.
The race test uses SQLite with the exact consumption statement extracted from
`ChallengeRepository.php`; it does not certify MariaDB or the complete HTTP flow.

On an isolated development site, back up its database and apply its migrations
using the existing installer:

```bash
php tools/install.php --schema-only --no-input
```

This adds `user_zkp_key` and `user_zkp_challenge`; no existing password is changed.
Enable the feature by merging this section into the development site's
`resources/config/User.config.php`, preserving all existing sections:

```php
'ZkpService' => [
    'enabled' => true,
    'origins' => ['http://127.0.0.1:8080'],
],
```

Use the actual test origin in both this configuration and the client's key file.
For production, the allowlist would contain `https://blackhost.xyz` and the exact
onion origin. Behind your existing nginx proxy, keep the correct `HTTPS` and
`SERVER_PORT` FastCGI values from the production runbook. The new service declares
its `User.config.php` configuration domain explicitly.

Before enabling production, exercise these browser checks with JavaScript off:

- Register and log in with a local key; also exercise ordinary password signup/login.
- Reject closed registration, missing invitations, required CAPTCHA, consent and
  verification; confirm that the optional method cannot avoid those gates.
- Reject a proof from another browser, another account, another origin or the
  other operation; reject a replay and an expired proof.
- Confirm that TOTP-enabled, locked, deleted and closed accounts follow existing
  policy. Corrupt/unreadable TOTP state must fail closed.
- Enroll, replace and revoke a key on a password account. Confirm session
  invalidation, including an older login that was waiting for TOTP.
- Confirm password-based webmail still works and the key-only limitation is visible.
- Test both configured clearnet and onion origins. Keep test SMTP delivery
  contained; registration verification otherwise uses the existing mail path.

Do not disable the feature while key-only users depend on it without providing
a recovery path. Disabling it hides/refuses the alternative but retains its data.
The existing full database dump includes both new tables. Client private keys
remain outside the server and need their owners' separate backups.

## Protocol v1

`X = xG` is the public key. For each proof, the client generates a fresh nonzero
random scalar `r` and computes `R = rG`, then `z = r + c*x mod L`. The verifier
checks `zG = R + cX`, with validated, nonidentity `X` and `R` and a canonical
scalar `z`. All secret scalar/group operations use libsodium. Public hash and
encoding operations use the language's standard library.

`c` is SHA-512 reduced modulo the Ristretto group order. The hash transcript is
the following sequence; **each item** is preceded by a four-byte unsigned
big-endian byte length:

1. UTF-8 `AstrX Schnorr proof of knowledge v1`
2. ASCII `ristretto255-sha512`
3. The 32-byte canonical Ristretto encoding of generator `G`
4. Canonical origin, ASCII
5. Operation, ASCII: `register`, `login` or `enroll`
6. The server's 32-byte random challenge nonce
7. Expiry as an ASCII decimal Unix timestamp
8. Account name, UTF-8, case preserved
9. Public key `X`, 32 bytes
10. Commitment `R`, 32 bytes

The challenge JSON has exactly `v`, `suite`, `action`, `origin`, `nonce`, `expires`.
The proof JSON has exactly `v`, `nonce`, `username`, `public_key`, `commitment`,
`response`. Version is integer `1`; nonce, points and response use lowercase hex.
Transport is unpadded canonical base64url of compact UTF-8 JSON. JSON strings use
unescaped Unicode/slashes, and duplicated fields are rejected. The PHP decoder
requires its compact JSON round-trip to match. Proofs are limited to 4096 bytes.
The fixed vector in `tests/fixtures/zkp-v1.json` is public test data, never a key
or nonce to use for an account.

The server records the challenge with a hash of a random browser-session binding,
the operation, origin and five-minute deadline. A conditional database update
marks it spent **before** verification; even an invalid attempt spends that
challenge. Consumed rows remain until expiry, with a best-effort cap of twelve
live/consumed challenges per binding (concurrent issuance can exceed that cap).
Existing site rate limits and CAPTCHA remain necessary;
this cap is not a global denial-of-service defense. Credential registration is
transactional with account creation. Key replacement/removal and session-epoch
increments are transactional too. Key login captures that epoch with the key,
including across a pending TOTP step.

## What the proof does and does not establish

Schnorr/Fiat–Shamir provides a proof of knowledge in the random-oracle model under
the discrete-log assumptions. This is an application-specific transcript based
on that construction; it is not a claim of RFC wire-format compatibility, a
formal proof of this whole implementation, or an audit.

It protects the private scalar from being sent to the site. It does not conceal
the username, public key, traffic metadata or account activity. It does not
encrypt mail or other application data, stop an operator from altering its own
database, or protect a compromised client. Python can retain copies of secrets
in process memory; no guaranteed memory erasure is claimed.

**Manual challenge transfer is vulnerable to live phishing/relay.** A phisher can
relay a genuine site's challenge from the phisher's session. Checking a pinned
origin inside the challenge does not cryptographically bind it to the browser
window the user intended. This is not a WebAuthn replacement and is not called
phishing-resistant. Origin/action/account checks still prevent accidental and
cross-context reuse. Use authenticated HTTPS or your authenticated v3 onion URL.

The key file uses scrypt (`N=32768`, `r=8`, `p=1`, 16-byte salt) to derive a 32-byte
key and libsodium secretbox with a fresh 24-byte nonce to encrypt and authenticate
the private scalar **and** its account/origin metadata. Parameters are fixed by
the file-format version, not accepted from an untrusted key file. Keep a strong
passphrase; a stolen encrypted key still permits offline guessing.

References: [Schnorr NIZK, RFC 8235](https://www.rfc-editor.org/rfc/rfc8235.html),
[Ristretto255, RFC 9496](https://www.rfc-editor.org/rfc/rfc9496.html),
[libsodium Ristretto APIs](https://doc.libsodium.org/advanced/point-arithmetic/ristretto),
[PHP sodium APIs](https://www.php.net/manual/en/ref.sodium.php).
