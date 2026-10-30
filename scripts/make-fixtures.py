#!/usr/bin/env python3
"""Create tiny, deterministic test PDFs. These contain no external content."""
from pathlib import Path

def pdf(path, content):
    objs = [
        b'<< /Type /Catalog /Pages 2 0 R >>',
        b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        b'<< /Length ' + str(len(content)).encode() + b' >>\nstream\n' + content + b'\nendstream',
    ]
    data = b'%PDF-1.4\n'; offsets = []
    for i, obj in enumerate(objs, 1):
        offsets.append(len(data)); data += f'{i} 0 obj\n'.encode() + obj + b'\nendobj\n'
    xref = len(data)
    data += f'xref\n0 {len(objs)+1}\n0000000000 65535 f \n'.encode()
    for offset in offsets: data += f'{offset:010d} 00000 n \n'.encode()
    data += f'trailer\n<< /Size {len(objs)+1} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n'.encode()
    path.write_bytes(data)

root = Path(__file__).resolve().parents[1] / 'fixtures'
pdf(root / 'text.pdf', b'BT /F1 16 Tf 72 700 Td (Cardiology PDF sentinel) Tj ET')
pdf(root / 'scanned.pdf', b'0.5 g 72 500 400 100 re f')
