#!/usr/bin/env python3
"""Describe the exact PHAR/native pair, published last to opt a release into egg updates."""
import argparse
import hashlib
import json
import pathlib
import re

parser = argparse.ArgumentParser()
parser.add_argument('--release', required=True)
parser.add_argument('--channel', choices=('pruebas', 'estable'), required=True)
parser.add_argument('--phar', type=pathlib.Path, required=True)
parser.add_argument('--out', type=pathlib.Path, required=True)
args = parser.parse_args()
if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]{0,159}', args.release):
    parser.error('Invalid release tag')
if args.phar.suffix != '.phar' or args.phar.stat().st_size < 1_000_000:
    parser.error('Missing or incomplete PHAR')
runtime = json.loads(pathlib.Path(__file__).with_name('ryzertc-runtime.json').read_text())
with args.phar.open('rb') as stream:
    checksum = hashlib.file_digest(stream, 'sha256').hexdigest()
manifest = {'schema': 1, 'channel': args.channel, 'release': args.release,
    'platform': runtime['platform'], 'phar': {'asset': args.phar.name, 'sha256': checksum},
    'ryzertc': runtime['ryzertc']}
args.out.write_text(json.dumps(manifest, indent=2) + '\n')
print(f'{args.release}: {args.channel}, PHAR {checksum}, RyzeRTC {runtime["ryzertc"]["tag"]}')
