#!/bin/sh
# Makes fresh, throwaway signing material for the trust pages:
#   - an OpenPGP key "AstrX operators" (no passphrase) in OUT/gnupg
#   - canary.asc: the warrant canary, clear-signed with it
#   - mirrors.asc: the official onion addresses, clear-signed with it
#   - manifest.txt/.pub/.sig: a release manifest with an Ed25519 signature
#     (what the downloads page checks)
#   - tipline.pub/.key: a sealed-box keypair for the tip line
#
#   sh tools/demo/trust/make.sh demo-data/trust
#
# Nothing here is a real key of the project: it exists only for screenshots.
set -eu
OUT=${1:?usage: make.sh OUTDIR}
HERE=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$HERE/../../.." && pwd)
mkdir -p "$OUT"
# gpg-agent's socket lives in GNUPGHOME and its path must be short, so the
# keyring is made in a temporary folder and copied to OUT/gnupg at the end.
export GNUPGHOME=$(mktemp -d)
trap 'gpgconf --kill gpg-agent; rm -rf "$GNUPGHOME"' EXIT
TODAY=$(date '+%-d %B %Y')

gpg --batch --pinentry-mode loopback --passphrase '' \
    --quick-generate-key 'AstrX operators <admin@example.onion>' ed25519 sign 2y
gpg --armor --export admin@example.onion > "$OUT/operators.asc"

cat > "$OUT/canary.txt" <<TXT
AstrX warrant canary
Date: $TODAY

As of the date above, the operators of this site state that:

1. We have not received any National Security Letter, gag order,
   FISA order or similar secret request for user data.
2. We have not been subject to any search or seizure of our servers.
3. We have not been asked to install any backdoor or to weaken the
   site's encryption, and we have not done so.
4. We have not disclosed any user's data to a third party.

This canary is renewed every 14 days. If it is not renewed, assume
the statements above may no longer be true.
TXT
gpg --batch --yes --pinentry-mode loopback --passphrase '' --clearsign -o "$OUT/canary.asc" "$OUT/canary.txt"

# Three made-up but well-formed v3 onion addresses.
python3 - "$OUT" <<'PY'
import base64, hashlib, os, sys
def onion():
    pub = os.urandom(32)
    chk = hashlib.sha3_256(b'.onion checksum' + pub + b'\x03').digest()[:2]
    return base64.b32encode(pub + chk + b'\x03').decode().lower() + '.onion'
out = sys.argv[1]
a = [onion() for _ in range(3)]
open(f'{out}/primary.txt', 'w').write(f'http://{a[0]}/\n')
open(f'{out}/mirror_list.txt', 'w').write(''.join(f'http://{h}/\n' for h in a[1:]))
PY
{
  echo "AstrX official addresses, $TODAY"
  echo
  echo "Canonical: $(cat "$OUT/primary.txt")"
  sed 's/^/Mirror:    /' "$OUT/mirror_list.txt"
} > "$OUT/mirrors.txt"
gpg --batch --yes --pinentry-mode loopback --passphrase '' --clearsign -o "$OUT/mirrors.asc" "$OUT/mirrors.txt"

# A release manifest: sha256 lines of (invented) release files, signed with
# a fresh Ed25519 key.
STAMP=$(date +%Y.%m.%d)
for f in "astrx-site-$STAMP.tar.gz" astrx-suite-x86_64-linux.tar.gz astrx-suite-aarch64-linux.tar.gz \
         "astrx-docker-compose-$STAMP.zip" tipline-keygen-offline.php; do
  printf '%s  %s\n' "$(printf '%s' "$f" | sha256sum | cut -d' ' -f1)" "$f"
done > "$OUT/manifest.txt"
php -r '
  $kp = sodium_crypto_sign_keypair();
  $msg = file_get_contents($argv[1] . "/manifest.txt");
  file_put_contents($argv[1] . "/manifest.pub", base64_encode(sodium_crypto_sign_publickey($kp)));
  file_put_contents($argv[1] . "/manifest.sig", base64_encode(sodium_crypto_sign_detached($msg, sodium_crypto_sign_secretkey($kp))));
' "$OUT"

php "$ROOT/tools/tipline.php" keygen > "$OUT/tipline.txt"
sed -n 3p "$OUT/tipline.txt" > "$OUT/tipline.pub"
sed -n 6p "$OUT/tipline.txt" > "$OUT/tipline.key"
rm "$OUT/tipline.txt"
gpgconf --kill gpg-agent
rm -rf "$OUT/gnupg" && cp -r "$GNUPGHOME" "$OUT/gnupg" && rm -f "$OUT/gnupg"/S.*
echo "trust material in $OUT"
