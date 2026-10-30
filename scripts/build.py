#!/usr/bin/env python3
"""Build a WordPress upload ZIP containing runtime files and licenses only."""
from pathlib import Path
import hashlib
import zipfile

root = Path(__file__).resolve().parents[1]
target = root / 'dist'
target.mkdir(exist_ok=True)
archive = target / 'uams-search-0.1.0.zip'
paths = [root / n for n in ['uams-search.php', 'readme.txt', 'LICENSE', 'THIRD-PARTY.md']]
for folder in ['includes', 'assets', 'templates', 'vendor/pdfparser']:
    paths.extend(p for p in (root / folder).rglob('*') if p.is_file() and p.name != '.DS_Store')
paths.append(root / 'scripts/pdf-worker.php')
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as out:
    for p in sorted(paths): out.write(p, 'uams-search/' + str(p.relative_to(root)))
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
(target / (archive.name + '.sha256')).write_text(digest + '  ' + archive.name + '\n')
print(f'{archive}: {len(paths)} files, {archive.stat().st_size:,} bytes, SHA256 {digest}')
