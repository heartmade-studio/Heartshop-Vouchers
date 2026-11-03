#!/usr/bin/env python3
"""Inherited cross-offer algorithm tests use an isolated synthetic configuration.
Product defaults and boundaries are tested separately by standalone.py.
"""
import shutil
import subprocess
import tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='heartshop-core-') as temp:
    fixture=Path(temp)/'repo'
    shutil.copytree(ROOT,fixture,ignore=shutil.ignore_patterns('.git','var','private','__pycache__'))
    shutil.copy2(ROOT/'tests/fixtures/core-config.php',fixture/'public/heartshop/api/config.php')
    for command in [['node','--test','tests/heartshop/frontend.cjs'],['php','tests/heartshop/backend.php'],['php','tests/heartshop/outbox.php'],['python3','tests/heartshop/routes.py'],['python3','tests/heartshop/apache.py']]:
        subprocess.run(command,cwd=fixture,check=True)
