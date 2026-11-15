#!/usr/bin/env python3
"""Create RyzeMP Egg manifest. RyzeVanillaGen is distributed independently."""
import argparse
import hashlib
import json
from pathlib import Path
import re

parser = argparse.ArgumentParser()
parser.add_argument('--release', required=True)
parser.add_argument('--channel', choices=('pruebas', 'estable'), required=True)
parser.add_argument('--phar', type=Path, required=True)
parser.add_argument('--out', type=Path, required=True)
args = parser.parse_args()

if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]{0,159}', args.release):
    parser.error('Invalid release tag')
if args.phar.suffix != '.phar' or args.phar.stat().st_size < 1_000_000:
    parser.error('Missing or incomplete PHAR')
runtime = json.loads(Path(__file__).with_name('ryzertc-runtime.json').read_text())
if runtime['ryzertc']['api'] != 2:
    parser.error('RyzeRTC native API must be 2')
with args.phar.open('rb') as stream:
    checksum = hashlib.file_digest(stream, 'sha256').hexdigest()
# Schema 1 is intentional: RyzeVanillaGen v0.1.0 comes from its own release,
# not duplicated in RyzeMP assets. Both old and new Eggs accept schema 1.
manifest = {
    'schema': 1,
    'channel': args.channel,
    'release': args.release,
    'platform': runtime['platform'],
    'phar': {'asset': args.phar.name, 'sha256': checksum},
    'ryzertc': runtime['ryzertc']
}
args.out.write_text(json.dumps(manifest, indent=2) + '\n')
print(f'RyzeMP {args.release}: {args.channel}, PHAR sha256={checksum}, '
      f'RyzeRTC={runtime["ryzertc"]["tag"]}, standalone RyzeVanillaGen installed by Egg')
