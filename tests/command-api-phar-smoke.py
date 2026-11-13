#!/usr/bin/env python3
"""Load a public fixture plugin against the actual PHAR, without private dependencies."""
import pathlib
import shutil
import socket
import subprocess
import sys
import tempfile

php, extension, phar = sys.argv[1:]
phar = str(pathlib.Path(phar).resolve())
extension = str(pathlib.Path(extension).resolve())
fixture = pathlib.Path('tests/fixtures/commandapi').resolve()
with tempfile.TemporaryDirectory(prefix='ryze-commandapi-') as directory:
    root = pathlib.Path(directory)
    plugins = root / 'plugins'
    plugins.mkdir()
    builder = '''$p=new Phar($argv[1]); $p->startBuffering();
    $p->addFile($argv[2]."/plugin.yml","plugin.yml");
    $p->addFile($argv[2]."/Main.php","src/RyzeTests/CommandApi/Main.php");
    $p->setStub("<?php __HALT_COMPILER();"); $p->stopBuffering();'''
    subprocess.run([php, '-n', '-d', 'phar.readonly=0', '-r', builder,
                    str(plugins / 'PublicCommandApiFixture.phar'), str(fixture)], check=True)
    shutil.copy('resources/pocketmine.yml', root / 'pocketmine.yml')
    config = root / 'pocketmine.yml'
    config.write_text(config.read_text().replace('enable-dev-builds: false', 'enable-dev-builds: true'))
    with socket.socket() as reservation:
        reservation.bind(('127.0.0.1', 0))
        port = reservation.getsockname()[1]
    (root / 'server.properties').write_text(f'server-ip=127.0.0.1\nserver-port={port}\nenable-ipv6=off\n')
    with open('public-command-smoke.log', 'w+') as log:
        process = subprocess.Popen([php, '-n', '-d', f'extension={extension}', phar, '--no-wizard', '--disable-ansi',
            f'--data={root}', f'--plugins={plugins}'], stdin=subprocess.PIPE, stdout=log, stderr=subprocess.STDOUT, text=True)
        try:
            process.wait(timeout=60)
        finally:
            if process.poll() is None:
                process.kill()
                process.wait(timeout=10)
            log.seek(0)
            output = log.read()
            print(output)
    assert process.returncode == 0, process.returncode
    assert 'COMMAND_API_PUBLIC_OK native=42 protocols=3 plugin-alias-typed-dispatch=ok' in output
    assert not any(value in output for value in ('COMMAND_API_PUBLIC_FAILED', 'Fatal error', 'Uncaught', 'CRITICAL', 'EMERGENCY'))
print('PASS: actual PHAR public plugin API, 42 native commands, metadata for 589/2193/2225, aliases and typed dispatch. Private HCF integration is separate.')
