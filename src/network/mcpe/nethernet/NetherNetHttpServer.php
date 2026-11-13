<?php
declare(strict_types=1);
namespace pocketmine\network\mcpe\nethernet;

/** Bounded HTTP/1 signaling. Full ICE answers are completed asynchronously. */
final class NetherNetHttpServer{
    private $listener = null;
    private array $clients = [];
    private int $sequence = 0;

    /**
     * @param \Closure(): array $metadata
     * @param \Closure(int, string, string, string, string): void $offer
     * @param \Closure(int): void $cancel
     */
    public function __construct(
        private string $ip, private int $port, private \Closure $metadata,
        private \Closure $offer, private \Closure $cancel
    ){}

    public function start() : void{
        if($this->listener !== null){return;}
        $socket = @stream_socket_server("tcp://{$this->ip}:{$this->port}", $code, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if($socket === false){throw new \RuntimeException("Cannot bind NetherNet TCP {$this->ip}:{$this->port}: $error ($code)");}
        stream_set_blocking($socket, false);
        $this->listener = $socket;
    }

    public function tick() : void{
        if($this->listener === null){return;}
        $now = microtime(true);
        for($i = 0; $i < 8; ++$i){
            $s = @stream_socket_accept($this->listener, 0, $remote);
            if($s === false){break;}
            $remoteIp = substr($remote, 0, (int)strrpos($remote, ':'));
            $sameIp = 0;
            foreach($this->clients as $client){if($client['ip'] === $remoteIp){++$sameIp;}}
            if(count($this->clients) >= 64 || $sameIp >= 8){fclose($s); continue;}
            stream_set_blocking($s, false);
            $this->clients[++$this->sequence] = ['socket' => $s, 'ip' => $remoteIp, 'data' => '', 'response' => null,
                'header' => null, 'length' => 0, 'waiting' => false, 'expires' => $now + 4.0];
        }
        $budget = 262144;
        $offers = 0;
        foreach(array_keys($this->clients) as $id){
            if(!isset($this->clients[$id])){continue;}
            $client = &$this->clients[$id];
            if($now >= $client['expires'] || feof($client['socket'])){$this->close($id); unset($client); continue;}
            if($client['response'] !== null){
                $written = @fwrite($client['socket'], substr($client['response'], 0, 16384));
                if($written === false){$this->close($id);}else{
                    $client['response'] = substr($client['response'], $written);
                    if($client['response'] === ''){$this->close($id);}
                }
                unset($client); continue;
            }
            if($client['waiting'] || $budget <= 0 || $offers >= 4){unset($client); continue;}
            $read = @fread($client['socket'], min(16384, $budget));
            if($read === false){$this->close($id); unset($client); continue;}
            $budget -= strlen($read);
            $client['data'] .= $read;
            if($client['header'] === null){
                $end = strpos($client['data'], "\r\n\r\n");
                if($end === false){
                    if(strlen($client['data']) > 8192){$this->respond($id, 431);}
                    unset($client); continue;
                }
                if($end + 4 > 8192){$this->respond($id, 431); unset($client); continue;}
                $header = substr($client['data'], 0, $end + 4);
                $client['data'] = substr($client['data'], $end + 4);
                $lines = explode("\r\n", substr($header, 0, -4));
                $request = array_shift($lines);
                if(preg_match('~\A(GET|POST) (/[^\s]{0,255}) HTTP/1\.[01]\z~D', $request, $m) !== 1){
                    $this->respond($id, 400); unset($client); continue;
                }
                $headers = [];
                $bad = false;
                foreach($lines as $line){
                    if(preg_match('/\A([A-Za-z0-9-]+):[ \t]*([^\r\n\x00-\x08\x0b\x0c\x0e-\x1f]*)\z/D', $line, $h) !== 1){$bad = true; break;}
                    $key = strtolower($h[1]);
                    if(isset($headers[$key])){$bad = true; break;}
                    $headers[$key] = trim($h[2]);
                }
                if($bad || !isset($headers['host']) || isset($headers['transfer-encoding']) || isset($headers['expect'])){
                    $this->respond($id, 400); unset($client); continue;
                }
                $length = $headers['content-length'] ?? '0';
                if(preg_match('/\A[0-9]{1,6}\z/D', $length) !== 1 || (int)$length > 65536){
                    $this->respond($id, 413); unset($client); continue;
                }
                $client['length'] = (int)$length;
                $client['header'] = [$m[1], explode('?', $m[2], 2)[0], $headers];
            }
            if(strlen($client['data']) < $client['length']){unset($client); continue;}
            if(strlen($client['data']) > $client['length']){$this->respond($id, 400); unset($client); continue;}
            [$method, $path, $headers] = $client['header'];
            if($method === 'GET' && $path === '/v1/join' && $client['length'] === 0){
                $metadata = ($this->metadata)();
                if($metadata === null){$this->respond($id, 503);}else{
                    $this->respond($id, 200, json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'application/json');
                }
            }elseif($method === 'POST' && preg_match('~\A/v1/join/([A-Za-z0-9._\~-]{1,128})\z~D', $path, $m) === 1){
                if(!isset($headers['content-length']) || $client['length'] === 0){$this->respond($id, 411);}
                elseif(strtolower(explode(';', $headers['content-type'] ?? '')[0]) !== 'application/sdp'){$this->respond($id, 415);}
                else{
                    ++$offers;
                    $client['waiting'] = true;
                    $client['expires'] = $now + 15.0;
                    try{($this->offer)($id, $m[1], $client['data'], $headers['host'], $client['ip']);}
                    catch(\InvalidArgumentException|\JsonException){$this->respond($id, 400);}
                    catch(\Throwable){$this->respond($id, 500);}
                }
            }else{$this->respond($id, 404);}
            unset($client);
        }
    }

    public function respond(int $id, int $code, string $body = '', string $type = 'text/plain') : bool{
        if(!isset($this->clients[$id]) || $this->clients[$id]['response'] !== null){return false;}
        $reasons = [200=>'OK',400=>'Bad Request',401=>'Unauthorized',403=>'Forbidden',404=>'Not Found',409=>'Conflict',
            411=>'Length Required',413=>'Payload Too Large',415=>'Unsupported Media Type',429=>'Too Many Requests',
            431=>'Request Header Fields Too Large',500=>'Internal Server Error',503=>'Service Unavailable',504=>'Gateway Timeout'];
        $this->clients[$id]['response'] = "HTTP/1.1 $code " . ($reasons[$code] ?? 'Error') . "\r\nContent-Type: $type\r\nContent-Length: " . strlen($body) . "\r\nCache-Control: no-store\r\nConnection: close\r\n\r\n" . $body;
        $this->clients[$id]['waiting'] = false;
        $this->clients[$id]['expires'] = microtime(true) + 4.0;
        return true;
    }

    public function isPending(int $id) : bool{return isset($this->clients[$id]) && $this->clients[$id]['waiting'];}

    private function close(int $id) : void{
        if(!isset($this->clients[$id])){return;}
        $pending = $this->clients[$id]['waiting'];
        fclose($this->clients[$id]['socket']); unset($this->clients[$id]);
        if($pending){($this->cancel)($id);}
    }
    public function shutdown() : void{
        foreach(array_keys($this->clients) as $id){$this->close($id);}
        if($this->listener !== null){fclose($this->listener); $this->listener = null;}
    }
}
