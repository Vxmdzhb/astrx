#!/usr/bin/env python3
"""Exercise the production challenge-consumption SQL under concurrent connections.

Uses SQLite to test the compare-and-swap statement itself; it is not a MariaDB
integration test. The PHP integration test exercises the repository methods.
"""
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import re
import sqlite3
import tempfile
import threading
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "vendor/astrx/cms/User/Zkp/ChallengeRepository.php"


class ChallengeRaceTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.database = str(Path(self.temp.name) / "challenge.sqlite")
        source = SOURCE.read_text()
        matches = re.findall(r"prepare\('(UPDATE user_zkp_challenge SET used = 1.*?)'\)", source, re.S)
        self.assertEqual(len(matches), 1)
        self.sql = matches[0]
        with sqlite3.connect(self.database) as db:
            db.execute("CREATE TABLE user_zkp_challenge (nonce TEXT PRIMARY KEY, session_hash TEXT, action TEXT, origin TEXT, expires INTEGER, used INTEGER DEFAULT 0)")
            db.execute("INSERT INTO user_zkp_challenge VALUES (?,?,?,?,?,0)", ("ab"*32,"browser","login","https://blackhost.xyz",2000))
        self.params = {"nonce":"ab"*32,"binding":"browser","action":"login","origin":"https://blackhost.xyz","now":1000}

    def tearDown(self):
        self.temp.cleanup()

    def consume(self, params):
        with sqlite3.connect(self.database, timeout=10) as db:
            return db.execute(self.sql, params).rowcount

    def test_sixteen_simultaneous_requests_have_one_winner(self):
        barrier = threading.Barrier(16)
        def compete(_):
            barrier.wait()
            return self.consume(self.params)
        with ThreadPoolExecutor(max_workers=16) as pool:
            results=list(pool.map(compete,range(16)))
        self.assertEqual(results.count(1),1)
        self.assertEqual(results.count(0),15)
        self.assertEqual(self.consume(self.params),0)

    def test_binding_origin_action_and_expiry_cannot_be_substituted(self):
        for key,value in [("binding","other"),("origin","https://evil.example"),("action","register"),("now",2000),("nonce","cd"*32)]:
            self.assertEqual(self.consume(dict(self.params,**{key:value})),0,key)
        self.assertEqual(self.consume(self.params),1)


if __name__ == "__main__":
    unittest.main()
