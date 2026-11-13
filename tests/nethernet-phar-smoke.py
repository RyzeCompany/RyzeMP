#!/usr/bin/env python3
"""Boot the real PHAR, fetch live official JWKS, exercise TCP/UDP and restart."""
import hashlib, http.client, pathlib, shutil, socket, struct, subprocess, sys, tempfile, time
php, extension, phar = sys.argv[1:]
phar = str(pathlib.Path(phar).resolve())
extension = str(pathlib.Path(extension).resolve())
logs = []
with tempfile.TemporaryDirectory(prefix='ryzemp-phar-') as root:
    data = pathlib.Path(root)
    shutil.copy('resources/pocketmine.yml', data / 'pocketmine.yml')
    p = data / 'pocketmine.yml'
    p.write_text(p.read_text().replace('enable-dev-builds: false', 'enable-dev-builds: true'))
    (data / 'plugins').mkdir()
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as reservation:
        reservation.bind(('127.0.0.1', 0))
        port = reservation.getsockname()[1]
    (data / 'server.properties').write_text(f'server-ip=127.0.0.1\nserver-port={port}\nenable-ipv6=off\n')
    identity_digest = None
    for run in range(2):
        log = open(data / f'start-{run}.log', 'w+')
        process = subprocess.Popen([php, '-d', f'extension={extension}', phar, '--no-wizard', '--disable-ansi',
            f'--data={data}', f'--plugins={data / "plugins"}'], stdin=subprocess.PIPE, stdout=log, stderr=subprocess.STDOUT, text=True)
        def request(method, path, body=None):
            connection = http.client.HTTPConnection('127.0.0.1', port, timeout=1)
            try:
                connection.request(method, path, body, {'Content-Type': 'application/sdp'} if body is not None else {})
                response = connection.getresponse()
                return response.status, response.read()
            finally:
                connection.close()
        try:
            deadline = time.monotonic() + 40
            while time.monotonic() < deadline:
                if process.poll() is not None:
                    raise RuntimeError(f'PHAR exited before READY: {process.returncode}')
                try:
                    code, body = request('GET', '/v1/join')
                    if code == 200:
                        break
                except (OSError, http.client.HTTPException):
                    pass
                time.sleep(.15)
            else:
                raise RuntimeError('PHAR never became ready with live official JWKS')
            import json
            metadata = json.loads(body)
            assert metadata['protocol'] == 2225 and metadata['version'] == '1.26.60', metadata
            assert request('POST', '/v1/join/unsigned-client', 'v=0\r\n')[0] == 401
            magic = bytes.fromhex('00ffff00fefefefefdfdfdfd12345678')
            with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as udp:
                udp.settimeout(3)
                udp.sendto(b'\x01' + struct.pack('>q', int(time.time()*1000)) + magic + struct.pack('>q', 12345), ('127.0.0.1', port))
                pong, address = udp.recvfrom(4096)
                assert address[1] == port and pong[0] == 0x1c, (address, pong[:16])
            digest = hashlib.sha256((data/'nethernet/operator-key.pem').read_bytes()).hexdigest()
            if identity_digest is not None:
                assert digest == identity_digest, 'Operator identity changed after PHAR restart'
            identity_digest = digest
            process.stdin.write('stop\n')
            process.stdin.flush()
            process.wait(timeout=20)
            assert process.returncode == 0, process.returncode
        finally:
            if process.poll() is None:
                process.kill()
                process.wait(timeout=10)
            log.seek(0)
            output = log.read()
            print(output)
            logs.append(output)
            log.close()
            pathlib.Path('native-phar-smoke.log').write_text('\n'.join(logs))
        assert 'NetherNet READY:' in output
        assert not any(marker in output for marker in ('Fatal error', 'Uncaught', 'CRITICAL', 'EMERGENCY', 'NetherNet UNAVAILABLE'))
print('PASS: real 2225 PHAR starts twice with RyzeRTC, live HTTPS JWKS, HTTP capability/auth rejection, legacy RakNet pong on same TCP/UDP port, persistent operator key and clean shutdown. Retail client login/world/gameplay remains pending.')
