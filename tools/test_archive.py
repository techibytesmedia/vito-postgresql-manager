#!/usr/bin/env python3
"""Build a test archive preserving the current source namespace."""
import json
import sys
from pathlib import Path
from prepare_release import prepare, ROOT

manifest = json.loads((ROOT / 'release.json').read_text()) if (ROOT / 'release.json').exists() else {
    'owner': 'Development', 'repository': 'vito-postgresql-manager'}
prepare(manifest['owner'], manifest['repository'], sys.argv[1] if len(sys.argv) > 1 else ROOT / 'dist/development')
