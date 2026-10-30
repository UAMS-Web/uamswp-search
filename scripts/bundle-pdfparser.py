#!/usr/bin/env python3
"""Bundle the pinned upstream release under a private namespace.

Download the official archive first, then pass its local ZIP path. Runtime source
and license are included; upstream tests, development dependencies and tools are not.
"""
from pathlib import Path
import hashlib
import shutil
import sys
import zipfile

VERSION = '2.13.1'
COMMIT = 'a71c6dd38e60a995fdb4d36e4433b8b3557c65dc'
root = Path(__file__).resolve().parents[1]
archive = Path(sys.argv[1])
target = root / 'vendor/pdfparser'
with zipfile.ZipFile(archive) as package:
    prefix = package.namelist()[0].split('/')[0] + '/'
    source = [(n, n[len(prefix):]) for n in package.namelist() if n.startswith(prefix + 'src/') and not n.endswith('/')]
    archive_commit = prefix.removeprefix('smalot-pdfparser-').rstrip('/') if prefix.startswith('smalot-pdfparser-') else ''
    if not source or len(archive_commit) < 7 or not COMMIT.startswith(archive_commit):
        raise ValueError('Archive does not match the pinned upstream commit')
    if (target / 'src').exists():
        backup = Path('/private/tmp/uams-search-pdfparser-before-update')
        shutil.copytree(target, backup, dirs_exist_ok=True)
        shutil.rmtree(target / 'src')
    target.mkdir(parents=True, exist_ok=True)
    for filename, relative in source:
        destination = target / relative
        if '..' in Path(relative).parts:
            raise ValueError('Unsafe archive path')
        destination.parent.mkdir(parents=True, exist_ok=True)
        data = package.read(filename)
        if filename.endswith('.php'):
            text = data.decode('utf-8')
            text = text.replace('Smalot\\\\PdfParser', 'UAMS_Search_Vendor\\\\PdfParser')
            text = text.replace('Smalot\\PdfParser', 'UAMS_Search_Vendor\\PdfParser')
            data = text.encode('utf-8')
        destination.write_bytes(data)
    for filename in ['LICENSE.txt', 'composer.json']:
        (target / filename).write_bytes(package.read(prefix + filename))
    (target / 'UAMS-MODIFICATIONS.md').write_text(
        f'Bundled smalot/pdfparser {VERSION}, commit {COMMIT}, from the official upstream release archive. '
        'LGPL-3.0 license and runtime source are included. All source namespaces, including dynamic class '
        'name strings, were changed from Smalot\\PdfParser to UAMS_Search_Vendor\\PdfParser to avoid '
        'WordPress plugin dependency conflicts. Native mbstring, iconv and zlib are required; no polyfill '
        'is bundled. Used only inside a resource-limited child process.\n'
        f'Upstream archive SHA256: {hashlib.sha256(archive.read_bytes()).hexdigest()}\n'
    )
print(f'Bundled PDF parser {VERSION}: {len(source)} source files')
