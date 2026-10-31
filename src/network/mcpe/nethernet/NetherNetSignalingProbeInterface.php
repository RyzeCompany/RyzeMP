<?php
/*
 * This file is part of RyzeMP.
 * Copyright (C) RyzeCompany contributors.
 * SPDX-License-Identifier: LGPL-3.0-or-later
 */
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

use pocketmine\network\NetworkInterface;
use pocketmine\network\NetworkInterfaceStartException;

/**
 * Same-public-port TCP observability for NetherNet handshake attempts.
 *
 * This is deliberately NOT an authenticated signaling service: GET /v1/join
 * returns 404 until real Minecraft token+DTLS binding is implemented; HTTP
 * offers fail closed. Never log SDP, JWT, fingerprints or identity assertions.
 *
 * Uses the original server-ip/server-port, not an additional game port.
 * Nonblocking work is strictly bounded per server tick.
 * @internal
 */
final class NetherNetSignalingProbeInterface implements NetworkInterface{
    private const MAX_CLIENTS = 32;
    private const ACCEPTS_PER_TICK = 8;
    private const MAX_HEADERS = 8192;
    private const CLIENT_TIMEOUT = 2.0;

    /** @var resource|null */
    private $listener = null;
    /** @var array<int, array{socket: resource, data: string, expires: float}> */
    private array $clients = [];
    private float $reportAt = 0.0;
    private int $probes = 0;
    private int $offers = 0;
    private int $others = 0;

    /** @param \Closure(string): void $log */
    public function __construct(private string $ip, private int $port, private \Closure $log){
        if(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $port < 1 || $port > 65535){
            throw new \InvalidArgumentException('Invalid NetherNet TCP endpoint');
        }
    }

    public function start() : void{
        if($this->listener !== null){
            return;
        }
        $code = 0;
        $error = '';
        $socket = @stream_socket_server(
            "tcp://{$this->ip}:{$this->port}", $code, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );
        if($socket === false){
            throw new NetworkInterfaceStartException("NetherNet TCP {$this->port} bind failed: $error ($code)");
        }
        stream_set_blocking($socket, false);
        $this->listener = $socket;
        $this->reportAt = microtime(true);
        ($this->log)("NetherNet TCP diagnostic listening on {$this->ip}:{$this->port} (same UDP game port). Identity signaling not ready.");
    }

    public function setName(string $name) : void{}

    public function tick() : void{
        if($this->listener === null){
            return;
        }
        $now = microtime(true);
        for($i = 0; $i < self::ACCEPTS_PER_TICK; ++$i){
            $s = @stream_socket_accept($this->listener, 0);
            if($s === false){
                break;
            }
            if(count($this->clients) >= self::MAX_CLIENTS){
                fclose($s);
                continue;
            }
            stream_set_blocking($s, false);
            $this->clients[(int) $s] = ['socket' => $s, 'data' => '', 'expires' => $now + self::CLIENT_TIMEOUT];
        }
        foreach($this->clients as $id => $client){
            if($now >= $client['expires'] || feof($client['socket'])){
                $this->closeClient($id);
                continue;
            }
            $space = self::MAX_HEADERS - strlen($client['data']);
            if($space <= 0){
                $this->respond($id, 431, 'Request Header Fields Too Large');
                continue;
            }
            $read = @fread($client['socket'], min(4096, $space));
            if($read === false){
                $this->closeClient($id);
                continue;
            }
            if($read === ''){
                continue;
            }
            $this->clients[$id]['data'] .= $read;
            $received = $this->clients[$id]['data'];
            if(!str_contains($received, "\r\n\r\n")){
                if(strlen($received) >= self::MAX_HEADERS){
                    $this->respond($id, 431, 'Request Header Fields Too Large');
                }
                continue;
            }
            $first = explode("\r\n", $received, 2)[0];
            if(preg_match('~\A(GET|POST|HEAD) (/[^\s]{0,1023}) HTTP/1\.[01]\z~D', $first, $m) !== 1){
                $this->respond($id, 400, 'Bad Request');
                continue;
            }
            $path = explode('?', $m[2], 2)[0];
            if($m[1] === 'GET' && $path === '/v1/join'){
                if(++$this->probes === 1){
                    ($this->log)('NetherNet TCP probe reached RyzeMP; authenticated signaling is still unavailable.');
                }
                $this->respond($id, 404, 'Not Found');
            }elseif($m[1] === 'POST' && str_starts_with($path, '/v1/join/')){
                if(++$this->offers === 1){
                    ($this->log)('NetherNet TCP offer reached RyzeMP; refused until Minecraft identity and DTLS are verified.');
                }
                $this->respond($id, 503, 'Service Unavailable');
            }else{
                ++$this->others;
                $this->respond($id, 404, 'Not Found');
            }
        }
        if($now - $this->reportAt >= 20){
            if($this->probes || $this->offers || $this->others){
                ($this->log)("NetherNet TCP last 20s: probes={$this->probes}, refused offers={$this->offers}, other={$this->others}; no users authenticated.");
            }
            $this->probes = $this->offers = $this->others = 0;
            $this->reportAt = $now;
        }
    }

    private function respond(int $id, int $code, string $reason) : void{
        if(!isset($this->clients[$id])){
            return;
        }
        $wire = "HTTP/1.1 $code $reason\r\nContent-Length: 0\r\nConnection: close\r\nCache-Control: no-store\r\n";
        if($code === 503){
            $wire .= "Retry-After: 30\r\n";
        }
        @fwrite($this->clients[$id]['socket'], $wire . "\r\n");
        $this->closeClient($id);
    }

    private function closeClient(int $id) : void{
        if(isset($this->clients[$id])){
            fclose($this->clients[$id]['socket']);
            unset($this->clients[$id]);
        }
    }

    public function shutdown() : void{
        foreach(array_keys($this->clients) as $id){
            $this->closeClient($id);
        }
        if($this->listener !== null){
            fclose($this->listener);
            $this->listener = null;
        }
    }
}
