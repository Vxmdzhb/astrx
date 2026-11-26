#!/usr/bin/env python3
"""Runs the key-login client that ships with astrx/cms.

The client itself is vendor/astrx/cms/resources/zkp/zkp_client.py, the same
file the site offers for download on its login and sign-up pages. This
launcher keeps `python3 tools/zkp_client.py ...` working from a checkout.
"""
import runpy
import sys
from pathlib import Path

CLIENT = Path(__file__).resolve().parent.parent / "vendor/astrx/cms/resources/zkp/zkp_client.py"

if not CLIENT.is_file():
    sys.exit(f"Error: {CLIENT} is missing; run composer install first.")
sys.argv[0] = str(CLIENT)
runpy.run_path(str(CLIENT), run_name="__main__")
