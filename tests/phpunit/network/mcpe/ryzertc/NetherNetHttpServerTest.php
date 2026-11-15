<?php
declare(strict_types=1);

namespace pocketmine\tests\network\mcpe\ryzertc;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\ryzertc\NetherNetHttpServer;

/**
 * Exercises HTTP and TLS negotiation on the SAME configured TCP port.
 * No native extension or Minecraft credentials are needed for signaling tests.
 */
final class NetherNetHttpServerTest extends TestCase{

    private static function freePort() : int{
        $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        self::assertNotFalse($socket, $message);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr(strrchr($name, ':'), 1);
    }

    private static function server(int $port, string $cert = '', string $key = '') : NetherNetHttpServer{
        return new NetherNetHttpServer('127.0.0.1', $port,
            static fn() : array => ['name'=>'RyzeMP', 'protocol'=>2225],
            static function() : void{},
            static function() : void{},
            $cert, $key);
    }

    public function testPlainHttpAndTlsFallbackShareOnePort() : void{
        $port = self::freePort();
        $server = self::server($port);
        $server->start();
        try{
            $client = stream_socket_client("tcp://127.0.0.1:$port", $error, $message, 2);
            self::assertNotFalse($client, $message);
            stream_set_blocking($client, false);
            fwrite($client, "GET /v1/join HTTP/1.1\r\nHost: localhost\r\n\r\n");
            $output = '';
            for($i = 0; $i < 200 && !str_contains($output, '"protocol":2225'); ++$i){
                $server->tick();
                $output .= fread($client, 4096);
                usleep(2_000);
            }
            self::assertStringContainsString('HTTP/1.1 200 OK', $output);
            self::assertStringContainsString('"protocol":2225', $output);
            fclose($client);

            $tls = stream_socket_client("tcp://127.0.0.1:$port", $error, $message, 2);
            self::assertNotFalse($tls, $message);
            stream_set_blocking($tls, false);
            fwrite($tls, "\x16\x03\x01\x00\x02\x01\x00");
            $alert = '';
            for($i = 0; $i < 100 && strlen($alert) < 7; ++$i){
                $server->tick();
                $alert .= fread($tls, 4096);
                usleep(2_000);
            }
            self::assertSame("\x15\x03\x03\x00\x02\x02\x28", substr($alert, 0, 7));
            fclose($tls);
        }finally{
            $server->shutdown();
        }
    }

    public function testCertificateRequiresMatchingPrivateKey() : void{
        $server = self::server(self::freePort(), '/missing/test-cert.pem');
        $this->expectException(\InvalidArgumentException::class);
        $server->start();
    }

    public function testHttpsNegotiatesOnOriginalPortWhenCertificateExists() : void{
        $key = openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA, 'private_key_bits'=>2048]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName'=>'localhost'], $key);
        self::assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $key, 1);
        self::assertNotFalse($cert);
        openssl_x509_export($cert, $pem);
        openssl_pkey_export($key, $private);
        $dir = sys_get_temp_dir() . '/ryzertc-tls-' . bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $certFile = $dir . '/certificate.pem';
        $keyFile = $dir . '/key.pem';
        file_put_contents($certFile, $pem);
        file_put_contents($keyFile, $private);
        $port = self::freePort();
        $server = self::server($port, $certFile, $keyFile);
        try{
            $server->start();
            $client = stream_socket_client("tcp://127.0.0.1:$port", $error, $message, 2,
                STREAM_CLIENT_CONNECT, stream_context_create(['ssl'=>[
                    'verify_peer'=>false, 'verify_peer_name'=>false, 'allow_self_signed'=>true
                ]]));
            self::assertNotFalse($client, $message);
            stream_set_blocking($client, false);
            $negotiated = false;
            for($i = 0; $i < 500; ++$i){
                @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                $server->tick();
                $result = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                if($result === true){$negotiated = true; break;}
                if($result === false){break;}
                usleep(2_000);
            }
            self::assertTrue($negotiated, 'TLS handshake failed on the shared signaling port');
            fwrite($client, "GET /v1/join HTTP/1.1\r\nHost: localhost\r\n\r\n");
            $output = '';
            for($i = 0; $i < 200 && !str_contains($output, '"protocol":2225'); ++$i){
                $server->tick();
                $output .= fread($client, 4096);
                usleep(2_000);
            }
            self::assertStringContainsString('HTTP/1.1 200 OK', $output);
            fclose($client);
        }finally{
            $server->shutdown();
            @unlink($certFile);
            @unlink($keyFile);
            @rmdir($dir);
        }
    }
}
