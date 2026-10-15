#!/usr/bin/env python3
"""Test-only SQL probe. Never distributed as a UI action."""
import json
import os
import subprocess
import sys

with open(sys.argv[1], encoding='utf-8') as stream:
    r = json.load(stream)
env = {'PATH': '/usr/bin:/bin', 'HOME': '/var/lib/postgresql', 'LANG': 'C.UTF-8'}
if r.get('password'):
    env['PGPASSWORD'] = r['password']
p = subprocess.run(['/usr/lib/postgresql/18/bin/psql', '-XAtq', '-v', 'ON_ERROR_STOP=1',
                    '-h', '127.0.0.1' if r.get('password') else '/var/run/postgresql',
                    '-p', '55487', '-U', r.get('user', 'postgres'), '--dbname=' + r.get('database', 'postgres')],
                   input=r['sql'], text=True, capture_output=True, env=env, timeout=30)
print(json.dumps({'ok': p.returncode == 0, 'output': p.stdout.strip() if p.returncode == 0 else ''}))
