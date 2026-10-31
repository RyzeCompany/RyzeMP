#!/usr/bin/env python3
"""Real PHAR pre-spawn regression; synthetic server-side player, no retail-client claim."""
import pathlib, shutil, socket, subprocess, sys, tempfile
php, extension, phar = sys.argv[1:]
phar = str(pathlib.Path(phar).resolve())
extension = str(pathlib.Path(extension).resolve())
fixture = pathlib.Path('tests/fixtures/prespawn').resolve()
with tempfile.TemporaryDirectory(prefix='ryze-prespawn-') as directory:
    data = pathlib.Path(directory)
    plugins = data / 'plugins'
    plugins.mkdir()
    package = plugins / 'PrespawnFixture.phar'
    builder = '''$p=new Phar($argv[1]); $p->startBuffering();
    $p->addFile($argv[2]."/plugin.yml","plugin.yml");
    $p->addFile($argv[2]."/Main.php","src/RyzeTests/Prespawn/Main.php");
    $p->setStub("<?php __HALT_COMPILER();"); $p->stopBuffering();'''
    subprocess.run([php,'-n','-d','phar.readonly=0','-r',builder,str(package),str(fixture)],check=True)
    shutil.copy('resources/pocketmine.yml',data/'pocketmine.yml')
    config = data/'pocketmine.yml'
    config.write_text(config.read_text().replace('enable-dev-builds: false','enable-dev-builds: true'))
    with socket.socket(socket.AF_INET,socket.SOCK_STREAM) as reservation:
        reservation.bind(('127.0.0.1',0))
        port=reservation.getsockname()[1]
    (data/'server.properties').write_text(f'server-ip=127.0.0.1\nserver-port={port}\nenable-ipv6=off\n')
    with open('native-prespawn-smoke.log','w+') as log:
        process=subprocess.Popen([php,'-n','-d',f'extension={extension}',phar,'--no-wizard','--disable-ansi',
            f'--data={data}',f'--plugins={plugins}'],stdin=subprocess.PIPE,stdout=log,stderr=subprocess.STDOUT,text=True)
        try:
            process.wait(timeout=80)
        finally:
            if process.poll() is None:
                process.kill()
                process.wait(timeout=10)
            log.seek(0)
            output=log.read()
            print(output)
    assert process.returncode==0,process.returncode
    assert 'PASS: synthetic 2225 player completed real pre-spawn' in output
    assert not any(marker in output for marker in ('PRESPAWN FIXTURE FAILED','Fatal error','Uncaught','CRITICAL','EMERGENCY','Undefined array key'))
print('PASS: actual PHAR pre-spawn and world-spawn regression. This is a synthetic server-side session; retail-client gameplay remains a separate host test.')
