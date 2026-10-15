#!/usr/bin/env python3
"""Prepare the source tree that MUST be committed before a GitHub release."""
import argparse
import hashlib
import json
import re
import shutil
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
NAMESPACE = re.search(r'^namespace\s+([^;]+);', (ROOT / 'Plugin.php').read_text(encoding='utf-8'), re.M)[1]
VERSION = re.search(r"public const VERSION = '([^']+)';", (ROOT / 'Plugin.php').read_text(encoding='utf-8'))[1]
RUNTIME = ['Plugin.php', 'Actions', 'Console', 'Jobs', 'Models', 'Services', 'Support', 'database', 'runtime']


def psr(value):
    if not re.fullmatch(r'[A-Za-z][A-Za-z0-9_-]*', value):
        raise ValueError('Use a GitHub owner/repository starting with a letter, followed by letters, digits, hyphens or underscores.')
    # Exactly matches Vito InstallGithubPlugin::toPsrCase for these names.
    return ''.join(word[0].upper() + word[1:] for word in re.split(r'[-_ ]+', value) if word)


def archive_path(output):
    return output.parent / (output.name + '.zip')


def prepare(owner, repo, output):
    namespace = 'App\\Vito\\Plugins\\' + psr(owner) + '\\' + psr(repo)
    output = Path(output).resolve()
    if output == ROOT or ROOT in output.parents and '.work' not in output.parts and 'dist' not in output.parts:
        raise ValueError('Write release output in dist/ or .work/, never over the working repository.')
    if output.exists():
        raise ValueError('Output already exists. Choose a fresh directory.')
    output.mkdir(parents=True)
    for name in RUNTIME + ['README.md', 'LICENSE', 'CHANGELOG.md', 'docs', 'tests', 'tools', '.github', '.gitignore', '.gitattributes']:
        src = ROOT / name
        if not src.exists():
            raise ValueError(f'Missing release input: {name}')
        dest = output / name
        if src.is_dir():
            shutil.copytree(src, dest, ignore=shutil.ignore_patterns('__pycache__', '*.pyc'))
        else:
            shutil.copy2(src, dest)
    for path in output.rglob('*'):
        if path.suffix in ('.php', '.md', '.py', '.yml', '.json'):
            path.write_text(path.read_text(encoding='utf-8').replace(NAMESPACE, namespace), encoding='utf-8', newline='\n')
    manifest = {'version': VERSION, 'owner': owner, 'repository': repo, 'namespace': namespace,
                'vito_version': '4.1.1', 'vito_commit': '8598e4dc0bce12d2b2f915766a100b445b7d9e22'}
    (output / 'release.json').write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8', newline='\n')
    archive = archive_path(output)
    with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as bundle:
        wrapper = f'{owner}-{repo}-source'
        for path in sorted(output.rglob('*')):
            if path.is_file():
                bundle.write(path, wrapper + '/' + path.relative_to(output).as_posix())
    print(json.dumps({'source': str(output), 'archive': str(archive), 'namespace': namespace,
                      'sha256': hashlib.sha256(archive.read_bytes()).hexdigest()}, indent=2))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('owner')
    parser.add_argument('repository')
    parser.add_argument('--output', required=True)
    args = parser.parse_args()
    prepare(args.owner, args.repository, args.output)
