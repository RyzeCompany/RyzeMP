<?php
declare(strict_types=1);

// Standalone regression: no Composer dependencies, no real player credentials.
// The HTTP diagnostic must share the Bedrock port, bound its inputs, refuse
// unauthenticated joins, and never reveal SDP / tokens to the logs.
namespace pocketmine\network{
    interface NetworkInterface{
        public function start() : void;
        public function setName(string $name) : void;
        public function tick() : void;
        public function shutdown() : void;
    }
    class NetworkInterfaceStartException extends \RuntimeException{}
}
namespace {
    require dirname(__DIR__) . '/src/network/mcpe/nethernet/NetherNetSignalingProbeInterface.php';

    use pocketmine\network\mcpe\nethernet\NetherNetSignalingProbeInterface;

    function assertTrue(bool $value, string $reason) : void{
        if(!$value){throw new \RuntimeException($reason);}
    }

    // One disposable port; test both TCP accept and UDP co-existence on that port.
    $reservation = stream_socket_server('tcp://127.0.0.1:0', $e, $err);
    assertTrue($reservation !== false, 'Could not reserve TCP test port');
    $port = (int)substr(strrchr(stream_socket_get_name($reservation, false), ':'), 1);
    fclose($reservation);
    $udp = stream_socket_server("udp://127.0.0.1:$port", $e, $err, STREAM_SERVER_BIND);
    assertTrue($udp !== false, 'Could not reserve UDP on same public port');

    $logs = [];
    $probe = new NetherNetSignalingProbeInterface('127.0.0.1', $port, static function(string $s) use (&$logs): void{$logs[] = $s;});
    $probe->start();
    $probe->start();

    function exchange(NetherNetSignalingProbeInterface $probe, int $port, string $request) : string{
        $client = stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 1);
        assertTrue($client !== false, 'TCP connection refused: ' . $errstr);
        stream_set_blocking($client, false);
        // Simulate fragmented headers across multiple network ticks.
        $half = intdiv(strlen($request), 2);
        fwrite($client, substr($request, 0, $half));
        $probe->tick();
        fwrite($client, substr($request, $half));
        $result = '';
        for($i = 0; $i < 100 && !str_contains($result, "\r\n\r\n"); ++$i){
            $probe->tick();
            $read = fread($client, 4096);
            if($read !== false){$result .= $read;}
            usleep(1000);
        }
        fclose($client);
        assertTrue(str_contains($result, "\r\n\r\n"), 'No HTTP response');
        return $result;
    }

    try{
        $status = exchange($probe, $port, "GET /v1/join?name=test HTTP/1.1\r\nHost: localhost\r\n\r\n");
        assertTrue(str_starts_with($status, 'HTTP/1.1 404 Not Found'), 'Probe must not advertise unimplemented NetherNet');
        $status = exchange($probe, $port,
            "POST /v1/join/123 HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\nX-Secret: DO_NOT_LOG\r\n\r\n");
        assertTrue(str_starts_with($status, 'HTTP/1.1 503 Service Unavailable'), 'Unverified offer must fail closed');
        $status = exchange($probe, $port, "GET /private HTTP/1.1\r\nHost: localhost\r\n\r\n");
        assertTrue(str_starts_with($status, 'HTTP/1.1 404 Not Found'), 'Unrecognized path must stay unavailable');
        $status = exchange($probe, $port, "POST /v1/join/456 HTTP/1.1\r\nHost: x\r\nX-Payload: " .
            str_repeat('x', 8300) . "\r\n\r\n");
        assertTrue(str_starts_with($status, 'HTTP/1.1 431 Request Header Fields Too Large'), 'Header limit not enforced');
        assertTrue(count($logs) >= 3, 'Expected startup, GET and POST diagnostic logs');
        assertTrue(!str_contains(implode("\n", $logs), 'DO_NOT_LOG'), 'Sensitive header was logged');
        assertTrue(!str_contains(implode("\n", $logs), '123'), 'Connection ID leaked');
    }finally{
        $probe->shutdown();
        fclose($udp);
    }
    // Port should be immediately reusable once shutdown completed.
    $again = stream_socket_server("tcp://127.0.0.1:$port", $e, $err);
    assertTrue($again !== false, 'TCP resource not released on shutdown');
    fclose($again);
    echo "RyzeMP NetherNet same-port TCP observability and fail-closed tests OK\n";
}
