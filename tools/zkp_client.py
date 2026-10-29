#!/usr/bin/env python3
"""Offline AstrX Schnorr proof client. Python 3.10+ and libsodium >= 1.0.18.

No HTTP, Tor, browser automation or network access. Private scalar arithmetic
runs in libsodium, not Python's variable-time big integers. See docs/zkp-auth.md.
"""
from __future__ import annotations

import argparse
import base64
import ctypes as C
import ctypes.util
import getpass
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import struct
import sys
import time
from urllib.parse import urlsplit

DOMAIN = b"AstrX Schnorr proof of knowledge v1"
SUITE = "ristretto255-sha512"
KEY_FORMAT = "astrx-zkp-encrypted-key-v1"
ZERO = bytes(32)
ORDER = 2**252 + 27742317777372353535851937790883648493


class Sodium:
    def __init__(self):
        name = os.environ.get("ASTRX_LIBSODIUM") or ctypes.util.find_library("sodium")
        if not name:
            raise ValueError("Install libsodium (apt install libsodium23, or brew install libsodium).")
        self.lib = C.CDLL(name)
        definitions = {
            "sodium_init": ([], C.c_int),
            "crypto_core_ristretto255_scalar_random": ([C.c_void_p], None),
            "crypto_core_ristretto255_scalar_reduce": ([C.c_void_p, C.c_void_p], None),
            "crypto_core_ristretto255_scalar_mul": ([C.c_void_p]*3, None),
            "crypto_core_ristretto255_scalar_add": ([C.c_void_p]*3, None),
            "crypto_core_ristretto255_add": ([C.c_void_p]*3, C.c_int),
            "crypto_core_ristretto255_is_valid_point": ([C.c_void_p], C.c_int),
            "crypto_scalarmult_ristretto255_base": ([C.c_void_p]*2, C.c_int),
            "crypto_scalarmult_ristretto255": ([C.c_void_p]*3, C.c_int),
            "crypto_secretbox_easy": ([C.c_void_p, C.c_void_p, C.c_ulonglong, C.c_void_p, C.c_void_p], C.c_int),
            "crypto_secretbox_open_easy": ([C.c_void_p, C.c_void_p, C.c_ulonglong, C.c_void_p, C.c_void_p], C.c_int),
        }
        try:
            for name, (args, result) in definitions.items():
                fn = getattr(self.lib, name)
                fn.argtypes, fn.restype = args, result
        except AttributeError as exc:
            raise ValueError("libsodium >= 1.0.18 with Ristretto support is required.") from exc
        if self.lib.sodium_init() < 0:
            raise ValueError("libsodium initialization failed")

    def operation(self, name, *parts, size=32):
        out = C.create_string_buffer(size)
        buffers = [C.create_string_buffer(p, len(p)) for p in parts]
        result = getattr(self.lib, name)(out, *buffers)
        if result not in (None, 0):
            raise ValueError("libsodium rejected an operation")
        return out.raw

    def scalar(self):
        return self.operation("crypto_core_ristretto255_scalar_random")

    def public(self, scalar):
        if len(scalar) != 32 or scalar == ZERO:
            raise ValueError("invalid private scalar")
        return self.operation("crypto_scalarmult_ristretto255_base", scalar)

    def point_valid(self, point):
        return len(point) == 32 and point != ZERO and self.lib.crypto_core_ristretto255_is_valid_point(C.create_string_buffer(point, 32)) == 1

    def box(self, message, nonce, key, decrypt=False):
        if len(nonce) != 24 or len(key) != 32 or (decrypt and len(message) < 16):
            raise ValueError("invalid encrypted key file")
        out = C.create_string_buffer(len(message) - 16 if decrypt else len(message) + 16)
        fn = self.lib.crypto_secretbox_open_easy if decrypt else self.lib.crypto_secretbox_easy
        result = fn(out, C.create_string_buffer(message, len(message)), len(message), C.create_string_buffer(nonce, 24), C.create_string_buffer(key, 32))
        if result != 0:
            raise ValueError("incorrect passphrase or damaged key file")
        return out.raw


def b64(data):
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode("ascii")


def unb64(text, maximum=4096):
    if not isinstance(text, str) or len(text) > maximum or not re.fullmatch(r"[A-Za-z0-9_-]+", text):
        raise ValueError("invalid base64url data")
    raw = base64.b64decode(text + "=" * (-len(text) % 4), altchars=b"-_", validate=True)
    if b64(raw) != text:
        raise ValueError("noncanonical base64url data")
    return raw


def no_duplicates(pairs):
    value = {}
    for key, item in pairs:
        if key in value:
            raise ValueError("duplicate JSON field")
        value[key] = item
    return value


def load_json(raw):
    value = json.loads(raw, object_pairs_hook=no_duplicates)
    if not isinstance(value, dict):
        raise ValueError("expected a JSON object")
    return value


def origin_valid(origin):
    if not isinstance(origin, str) or len(origin) > 255 or not origin.isascii():
        return False
    try:
        uri = urlsplit(origin)
        host = uri.hostname or ""
        port = uri.port
    except ValueError:
        return False
    if uri.username or uri.password or uri.path or uri.query or uri.fragment or not host or host != host.lower():
        return False
    if not re.fullmatch(r"[a-z0-9.-]+", host) or not (port is None or 1 <= port <= 65535):
        return False
    if origin != uri.scheme + "://" + host + (":" + str(port) if port is not None else ""):
        return False
    if uri.scheme == "https":
        return port != 443
    return uri.scheme == "http" and port != 80 and (
        bool(re.fullmatch(r"[a-z2-7]{56}\.onion", host)) or host in ("localhost", "127.0.0.1")
    )


def username_valid(username):
    return isinstance(username, str) and 0 < len(username.encode("utf-8")) <= 128 and not any(ord(c) < 32 or ord(c) == 127 for c in username)


def challenge_read(token, origins, expected_action, now=None):
    challenge = load_json(unb64(token))
    if set(challenge) != {"v", "suite", "action", "origin", "nonce", "expires"}:
        raise ValueError("unexpected challenge fields")
    if type(challenge["v"]) is not int or challenge["v"] != 1 or challenge["suite"] != SUITE:
        raise ValueError("unsupported protocol")
    if challenge["action"] != expected_action or expected_action not in ("register", "login", "enroll"):
        raise ValueError("challenge action does not match your command")
    if challenge["origin"] not in origins or not origin_valid(challenge["origin"]):
        raise ValueError("challenge origin is not one you selected when creating this key")
    if not isinstance(challenge["nonce"], str) or not re.fullmatch(r"[0-9a-f]{64}", challenge["nonce"]):
        raise ValueError("invalid nonce")
    now = int(time.time()) if now is None else now
    if type(challenge["expires"]) is not int or not now < challenge["expires"] <= now + 600:
        raise ValueError("challenge expired, exceeds its allowed lifetime, or local clock is wrong")
    return challenge


def transcript(sodium, challenge, username, public, commitment):
    fields = [DOMAIN, SUITE.encode(), sodium.public(bytes([1]) + bytes(31)),
              challenge["origin"].encode(), challenge["action"].encode(), bytes.fromhex(challenge["nonce"]),
              str(challenge["expires"]).encode(), username.encode("utf-8"), public, commitment]
    return b"".join(struct.pack("!I", len(field)) + field for field in fields)


def prove(sodium, secret, username, challenge):
    public = sodium.public(secret)
    nonce = sodium.scalar()  # Fresh for EVERY proof; never supplied by the server or saved.
    commitment = sodium.public(nonce)
    digest = hashlib.sha512(transcript(sodium, challenge, username, public, commitment)).digest()
    c = sodium.operation("crypto_core_ristretto255_scalar_reduce", digest)
    product = sodium.operation("crypto_core_ristretto255_scalar_mul", c, secret)
    response = sodium.operation("crypto_core_ristretto255_scalar_add", nonce, product)
    return {"v": 1, "nonce": challenge["nonce"], "username": username,
            "public_key": public.hex(), "commitment": commitment.hex(), "response": response.hex()}


def derive(passphrase, salt):
    if not isinstance(passphrase, str) or not 1 <= len(passphrase.encode("utf-8")) <= 1024 or len(salt) != 16:
        raise ValueError("invalid passphrase or salt")
    return hashlib.scrypt(passphrase.encode("utf-8"), salt=salt, n=32768, r=8, p=1, dklen=32, maxmem=64*1024*1024)


def encrypt_key(sodium, key, passphrase):
    salt, nonce = os.urandom(16), os.urandom(24)
    raw = json.dumps(key, separators=(",", ":"), ensure_ascii=False).encode()
    return {"format": KEY_FORMAT, "salt": b64(salt), "nonce": b64(nonce),
            "ciphertext": b64(sodium.box(raw, nonce, derive(passphrase, salt)))}


def decrypt_key(sodium, encrypted, passphrase):
    if set(encrypted) != {"format", "salt", "nonce", "ciphertext"} or encrypted["format"] != KEY_FORMAT:
        raise ValueError("unsupported key format")
    salt, nonce = unb64(encrypted["salt"]), unb64(encrypted["nonce"])
    raw = sodium.box(unb64(encrypted["ciphertext"]), nonce, derive(passphrase, salt), decrypt=True)
    key = load_json(raw)
    if set(key) != {"origins", "username", "secret"} or not username_valid(key["username"]):
        raise ValueError("invalid key payload")
    origins = key["origins"]
    if not isinstance(origins, list) or not 1 <= len(origins) <= 8 or not all(origin_valid(x) for x in origins):
        raise ValueError("invalid saved origins")
    if not isinstance(key["secret"], str) or not re.fullmatch(r"[0-9a-f]{64}", key["secret"]):
        raise ValueError("invalid private scalar")
    secret = bytes.fromhex(key["secret"])
    # The canonical check is a public validation; private arithmetic stays in C.
    if secret == ZERO or sodium.operation("crypto_core_ristretto255_scalar_reduce", secret + ZERO) != secret:
        raise ValueError("invalid private scalar")
    return key


def read_key(path):
    flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0)
    fd = os.open(path, flags)
    with os.fdopen(fd, "rb") as stream:
        info = os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_size > 8192:
            raise ValueError("not a bounded regular key file")
        if os.name == "posix" and (info.st_uid != os.getuid() or stat.S_IMODE(info.st_mode) & 0o077):
            raise ValueError("key must be owned by you and private (chmod 600)")
        return load_json(stream.read(8193))


def write_new_key(path, encrypted):
    # O_EXCL prevents overwriting a key or following an existing symlink.
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as stream:
        json.dump(encrypted, stream, separators=(",", ":"))
        stream.write("\n")
        stream.flush()
        os.fsync(stream.fileno())


def prompt(message):
    # Keep stdout to the public key/proof, including when output is redirected.
    print(message, end="", file=sys.stderr, flush=True)
    return input()


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    generate = sub.add_parser("keygen", help="create an encrypted per-account key")
    generate.add_argument("--key", type=Path, required=True)
    generate.add_argument("--origin", action="append", required=True, help="trusted canonical origin; repeat for your onion origin")
    generate.add_argument("--username", required=True)
    public = sub.add_parser("public", help="show the public key and account")
    public.add_argument("--key", type=Path, required=True)
    proof = sub.add_parser("prove", help="answer a challenge without any network access")
    proof.add_argument("--key", type=Path, required=True)
    proof.add_argument("--action", required=True, choices=("register", "login", "enroll"))
    proof.add_argument("--challenge", help="paste the page's challenge; prompted if omitted")
    proof.add_argument("--username", help="explicit account-name override after renaming your account")
    args = parser.parse_args(argv)
    sodium = Sodium()
    if args.command == "keygen":
        if not username_valid(args.username) or not 1 <= len(args.origin) <= 8 or not all(origin_valid(x) for x in args.origin):
            raise ValueError("use a valid username and canonical HTTPS, v3 onion HTTP, or loopback development origin")
        phrase = getpass.getpass("Local key passphrase (12+ characters): ")
        if len(phrase) < 12 or phrase != getpass.getpass("Repeat passphrase: "):
            raise ValueError("passphrases must match and contain at least 12 characters")
        key = {"origins": args.origin, "username": args.username, "secret": sodium.scalar().hex()}
        write_new_key(args.key, encrypt_key(sodium, key, phrase))
        print("Key saved. Back up this encrypted file and retain its passphrase.", file=sys.stderr)
        print(sodium.public(bytes.fromhex(key["secret"])).hex())
        return
    key = decrypt_key(sodium, read_key(args.key), getpass.getpass("Local key passphrase: "))
    secret = bytes.fromhex(key["secret"])
    if args.command == "public":
        print(json.dumps({"username": key["username"], "origins": key["origins"], "public_key": sodium.public(secret).hex()}, indent=2))
        return
    username = args.username or key["username"]
    if not username_valid(username):
        raise ValueError("invalid account name")
    token = args.challenge or prompt("Paste challenge: ").strip()
    challenge = challenge_read(token, key["origins"], args.action)
    print(f"Action: {challenge['action']}\nAccount: {username}\nSite: {challenge['origin']}", file=sys.stderr)
    if prompt("Is this the action you started in your browser? [y/N] ").strip().lower() != "y":
        raise ValueError("cancelled")
    # Recheck expiry after the user interaction.
    challenge = challenge_read(token, key["origins"], args.action)
    result = prove(sodium, secret, username, challenge)
    print(b64(json.dumps(result, separators=(",", ":"), ensure_ascii=False).encode()))


if __name__ == "__main__":
    try:
        main()
    except (ValueError, OSError, EOFError, KeyboardInterrupt) as error:
        print(f"Error: {error}", file=sys.stderr)
        sys.exit(1)
