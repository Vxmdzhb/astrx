#!/usr/bin/env python3
"""Offline tests: real libsodium, encrypted key files and adversarial transcripts."""
import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import struct
import tempfile
import unittest
from unittest.mock import patch
from contextlib import redirect_stdout, redirect_stderr
import io
import time

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("zkp_client", ROOT / "tools/zkp_client.py")
client = importlib.util.module_from_spec(spec)
spec.loader.exec_module(client)

BASE = bytes.fromhex("e2f2ae0a6abc4e71a884a961c500515f58e30b6aa582dd8db6a65945e08d2d76")


def reference_verify(sodium, challenge, username, proof):
    """Separate verifier: does not use the client's transcript/prove functions."""
    try:
        if proof["v"] != 1 or proof["username"] != username or proof["nonce"] != challenge["nonce"]:
            return False
        public = bytes.fromhex(proof["public_key"])
        commitment = bytes.fromhex(proof["commitment"])
        z = bytes.fromhex(proof["response"])
        if not sodium.point_valid(public) or not sodium.point_valid(commitment) or len(z) != 32 or int.from_bytes(z, "little") >= client.ORDER:
            return False
        parts = [b"AstrX Schnorr proof of knowledge v1", b"ristretto255-sha512", BASE,
                 challenge["origin"].encode(), challenge["action"].encode(), bytes.fromhex(challenge["nonce"]),
                 str(challenge["expires"]).encode(), username.encode(), public, commitment]
        framed = b"".join(len(part).to_bytes(4, "big") + part for part in parts)
        digest = hashlib.sha512(framed).digest()
        c = sodium.operation("crypto_core_ristretto255_scalar_reduce", digest)
        left = bytes(32) if z == bytes(32) else sodium.public(z)
        cx = bytes(32) if c == bytes(32) else sodium.operation("crypto_scalarmult_ristretto255", c, public)
        right = sodium.operation("crypto_core_ristretto255_add", commitment, cx)
        return left == right
    except (ValueError, KeyError, TypeError):
        return False


class ClientTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sodium = client.Sodium()

    def setUp(self):
        self.secret = self.sodium.scalar()
        self.challenge = {"v": 1, "suite": client.SUITE, "action": "login", "origin": "https://blackhost.xyz",
                          "nonce": "ab"*32, "expires": 2000000000}
        self.key = {"origins": ["https://blackhost.xyz"], "username": "alice", "secret": self.secret.hex()}

    def test_published_basepoint(self):
        self.assertEqual(self.sodium.public(bytes([1])+bytes(31)), BASE)

    def test_real_proofs_and_fresh_nonces(self):
        commitments = set()
        for _ in range(32):
            proof = client.prove(self.sodium, self.secret, "alice", self.challenge)
            self.assertTrue(reference_verify(self.sodium, self.challenge, "alice", proof))
            commitments.add(proof["commitment"])
        self.assertEqual(len(commitments), 32)

    def test_transcript_substitution(self):
        proof = client.prove(self.sodium, self.secret, "alice", self.challenge)
        for field, value in [("origin", "https://attacker.example"), ("action", "register"),
                             ("nonce", "cd"*32), ("expires", 2000000001)]:
            altered = dict(self.challenge, **{field: value})
            self.assertFalse(reference_verify(self.sodium, altered, "alice", proof), field)
        self.assertFalse(reference_verify(self.sodium, self.challenge, "bob", proof))
        for field in ("public_key", "commitment", "response"):
            self.assertFalse(reference_verify(self.sodium, self.challenge, "alice", dict(proof, **{field: "00"*32})), field)
        self.assertFalse(reference_verify(self.sodium, self.challenge, "alice", dict(proof, public_key=self.sodium.public(self.sodium.scalar()).hex())))

    def test_noncanonical_response(self):
        proof = client.prove(self.sodium, self.secret, "alice", self.challenge)
        z = int.from_bytes(bytes.fromhex(proof["response"]), "little")
        proof["response"] = (z + client.ORDER).to_bytes(32, "little").hex()
        self.assertFalse(reference_verify(self.sodium, self.challenge, "alice", proof))

    def test_challenge_intent_and_expiry(self):
        token = client.b64(json.dumps(self.challenge).encode())
        self.assertEqual(client.challenge_read(token, self.key["origins"], "login", now=1999999999), self.challenge)
        for origins, action, now in [(["https://elsewhere.example"], "login",1999999999),
                                     (self.key["origins"],"register",1999999999),
                                     (self.key["origins"],"login",2000000000),
                                     (self.key["origins"],"login",1999999000)]:
            with self.assertRaises(ValueError): client.challenge_read(token,origins,action,now)
        for field,value in [("v", True), ("expires", True), ("suite", "unknown"), ("nonce", "aa")]:
            bad=client.b64(json.dumps(dict(self.challenge, **{field:value})).encode())
            with self.assertRaises(ValueError): client.challenge_read(bad,self.key["origins"],"login",1999999999)

    def test_origin_rules(self):
        for origin in ["https://blackhost.xyz", "http://"+"a"*56+".onion", "http://127.0.0.1:8080"]:
            self.assertTrue(client.origin_valid(origin),origin)
        for origin in ["http://blackhost.xyz", "https://blackhost.xyz/", "https://blackhost.xyz:443",
                       "https://blackhost.xyz@evil.test", "https://evil.test#blackhost.xyz", "http://short.onion",
                       "https://BLACKHOST.XYZ", "https://blackhost.xyz:0"]:
            self.assertFalse(client.origin_valid(origin),origin)

    def test_encrypted_key_roundtrip_and_tampering(self):
        phrase="a local passphrase for testing"
        encrypted=client.encrypt_key(self.sodium,self.key,phrase)
        self.assertNotIn(self.secret.hex(),json.dumps(encrypted))
        self.assertEqual(client.decrypt_key(self.sodium,encrypted,phrase),self.key)
        with self.assertRaises(ValueError): client.decrypt_key(self.sodium,encrypted,"wrong password")
        changed=copy.deepcopy(encrypted)
        body=bytearray(client.unb64(changed["ciphertext"])); body[20]^=1
        changed["ciphertext"]=client.b64(body)
        with self.assertRaises(ValueError): client.decrypt_key(self.sodium,changed,phrase)

    def test_key_file_safety(self):
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp)/"key"
            client.write_new_key(path,{"fixture": True})
            self.assertEqual(client.read_key(path),{"fixture":True})
            with self.assertRaises(FileExistsError): client.write_new_key(path,{"fixture":False})
            if os.name=="posix":
                self.assertEqual(path.stat().st_mode & 0o777,0o600)
                path.chmod(0o644)
                with self.assertRaises(ValueError): client.read_key(path)
                path.chmod(0o600)
                link=Path(tmp)/"link"; link.symlink_to(path)
                with self.assertRaises(OSError): client.read_key(link)

    def test_bounded_canonical_transport(self):
        for token in ["a", "====", "a b", "x"*4097, "AB"]:
            with self.assertRaises(ValueError): client.unb64(token)
        with self.assertRaises(ValueError): client.load_json('{"v":1,"v":2}')

    def test_cli_keygen_and_login_without_network(self):
        phrase="this passphrase stays entirely local"
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp)/"blackhost.key"
            stdout=io.StringIO(); stderr=io.StringIO()
            with patch("getpass.getpass",side_effect=[phrase,phrase]), redirect_stdout(stdout), redirect_stderr(stderr):
                client.main(["keygen","--key",str(path),"--origin","https://blackhost.xyz","--username","alice"])
            public=stdout.getvalue().strip()
            challenge=dict(self.challenge,expires=int(time.time())+300)
            token=client.b64(json.dumps(challenge,separators=(",",":")).encode())
            output=io.StringIO()
            with patch("getpass.getpass",return_value=phrase), patch("sys.stdin",io.StringIO(token+"\ny\n")), redirect_stdout(output), redirect_stderr(stderr):
                client.main(["prove","--key",str(path),"--action","login","--username","renamed"])
            proof=client.load_json(client.unb64(output.getvalue().strip()))
            self.assertEqual(proof["public_key"],public)
            self.assertTrue(reference_verify(self.sodium,challenge,"renamed",proof))
            self.assertNotIn(phrase,stdout.getvalue()+stderr.getvalue()+output.getvalue())

    def test_unicode_account_is_bound_as_utf8(self):
        for username in ["città", "利用者", "user\u2028name"]:
            proof=client.prove(self.sodium,self.secret,username,self.challenge)
            self.assertTrue(reference_verify(self.sodium,self.challenge,username,proof))
            token=client.b64(json.dumps(proof,ensure_ascii=False,separators=(",",":")).encode())
            self.assertEqual(client.load_json(client.unb64(token)),proof)


if __name__ == "__main__":
    unittest.main()
