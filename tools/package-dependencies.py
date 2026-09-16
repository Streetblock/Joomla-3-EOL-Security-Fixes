#!/usr/bin/env python3
"""Package complete, Composer-installed releases; never edit dependency source.

Usage: python tools/package-dependencies.py ORIGINAL_JOOMLA_ROOT COMPOSER_BUILD_ROOT
Build the latter with the runtime-only composer files under tools/dependency-build.
"""
import hashlib
import json
import shutil
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
original, build = (Path(p).resolve() for p in sys.argv[1:])
vendor = Path('libraries/vendor')
versions = {'paragonie/sodium_compat': 'v1.24.2', 'symfony/yaml': 'v5.4.53',
            'symfony/deprecation-contracts': 'v2.5.4'}
installed = json.loads((build / vendor / 'composer/installed.json').read_text())['packages']
packages = {p['name']: p for p in installed}
assert all(packages[n]['version'] == v for n, v in versions.items())
baseline = json.loads((original / vendor / 'composer/installed.json').read_text())['packages']
assert {p['name']: p['version'] for p in baseline if p['name'] not in versions} == {
    n: p['version'] for n, p in packages.items() if n not in versions}, 'Unrequested dependency changes'
assert {p['name']: p.get('source') for p in baseline if p['name'] not in versions} == {
    n: p.get('source') for n, p in packages.items() if n not in versions}, 'Unrequested dependency source changes'
metadata_path = ROOT / 'DEPENDENCIES.json'
previous = json.loads(metadata_path.read_text()) if metadata_path.exists() else {}
metadata = dict(php_minimum='7.4.0', composer='2.10.3', packages=[], additions=[], removals={},
                accepted_targets={}, upstream_files={})
for n, version in versions.items():
    p = packages[n]
    metadata['packages'].append(dict(name=n, version=version, source=p['source'], dist=p['dist'], require=p.get('require', {})))
paths = [vendor / 'autoload.php']
for n in [*versions, 'composer']:
    paths += [p.relative_to(build) for p in (build / vendor / n).rglob('*') if p.is_file()]
for relative in sorted(paths):
    name = relative.as_posix()
    source, target, old = build / relative, ROOT / 'files' / relative, original / relative
    raw = source.read_bytes()
    digest = hashlib.sha256(raw).hexdigest()
    if old.is_file():
        allowed = set(previous.get('accepted_targets', {}).get(name, []))
        allowed.add(hashlib.sha256(old.read_bytes()).hexdigest())
        if target.is_file():
            allowed.add(hashlib.sha256(target.read_bytes()).hexdigest())
        metadata['accepted_targets'][name] = sorted(allowed)
    else:
        metadata['additions'].append(name)
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_bytes(raw)
    metadata['upstream_files'][name] = digest
for n in versions:
    for old in (original / vendor / n).rglob('*'):
        if old.is_file() and not (build / old.relative_to(original)).exists():
            relative = old.relative_to(original).as_posix()
            metadata['removals'][relative] = [hashlib.sha256(old.read_bytes()).hexdigest()]
metadata_path.write_text(json.dumps(metadata, indent=2, ensure_ascii=False) + '\n', encoding='utf-8', newline='\n')
out = ROOT / 'tools/dependency-build'
out.mkdir(exist_ok=True)
for name in ['composer.json', 'composer.lock']:
    shutil.copyfile(build / name, out / name)
print(f'Packaged {len(paths)} official/generated files; {len(metadata["additions"])} additions; {len(metadata["removals"])} removals')
