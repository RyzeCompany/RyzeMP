<?php
declare(strict_types=1);
namespace pocketmine\network\mcpe\nethernet;

use RyzeRTC\PeerConnection;

/** Owns native peers on the main PHP thread; callbacks copy bytes in RyzeRTC only. */
final class NetherNetTransport{
    private PeerConnection $anchor;
    private int $backendPort;
    private bool $ready = false;
    private array $connections = [];
    private array $networkIds = [];
    private array $ufrags = [];
    private array $requests = [];
    private int $sequence = 0;

    /**
     * @param \Closure(): ?NetherNetIdentityAssertionVerifier $verifier
     * @param \Closure(int, int, string): bool $answer HTTP request ID, status, SDP
     * @param \Closure(int, string, int, array, \Closure, \Closure): void $accept
     * @param \Closure(int, string): void $receive
     * @param \Closure(int, string): void $disconnected
     */
    public function __construct(
        private int $publicPort, private NetherNetServerIdentity $identity,
        private NetherNetSharedPortSignalingBridge $bridge, private \Closure $verifier,
        private \Closure $answer, private \Closure $accept, private \Closure $receive,
        private \Closure $disconnected, private \Closure $log
    ){
        if(!extension_loaded('ryzertc') || \RyzeRTC\Runtime::apiVersion() < 2){
            throw new \RuntimeException('RyzeRTC API 2 required; install the current official runtime using the Egg');
        }
        $reservation = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if($reservation === false || !socket_bind($reservation, '127.0.0.1', 0) || !socket_getsockname($reservation, $ip, $port)){
            throw new \RuntimeException('Cannot reserve private RyzeRTC ICE endpoint');
        }
        socket_close($reservation);
        $this->backendPort = $port;
        // Keep libjuice's UDP mux bound for the lifetime of this interface.
        $this->anchor = new PeerConnection('127.0.0.1', $port, true);
        $this->anchor->createDataChannel('RyzeRTC-private-mux');
        $this->anchor->beginOffer();
    }

    public function getBackendPort() : int{return $this->backendPort;}
    public function isReady() : bool{return $this->ready && ($this->verifier)() !== null;}

    /** Public address is resolved by the signaling layer, never read from SDP. */
    public function offer(int $requestId, string $networkId, string $sdp, string $publicIp, string $httpIp) : void{
        if(!$this->isReady()){($this->answer)($requestId, 503, ''); return;}
        if(count($this->connections) >= 256 || count($this->requests) >= 32){($this->answer)($requestId, 429, ''); return;}
        if(isset($this->networkIds[$networkId])){($this->answer)($requestId, 409, ''); return;}
        $sameIp = 0;
        foreach($this->connections as $c){if(!$c['accepted'] && $c['httpIp'] === $httpIp){++$sameIp;}}
        if($sameIp >= 4){($this->answer)($requestId, 429, ''); return;}
        try{
            $verified = ($this->verifier)()->validate($sdp);
        }catch(\InvalidArgumentException|\JsonException){
            ($this->answer)($requestId, 401, '');
            ($this->log)('NetherNet offer rejected: Minecraft JWT or DTLS identity assertion invalid.');
            return;
        }
        $normalized = str_replace("\r\n", "\n", $sdp);
        if(!str_starts_with($normalized, "v=0\n") || substr_count("\n" . $normalized, "\nm=") !== 1 ||
            preg_match('/^m=application [0-9]+ UDP\/DTLS\/SCTP webrtc-datachannel$/m', $normalized) !== 1){
            ($this->answer)($requestId, 400, ''); return;
        }
        // Do not let a remote SDP cause native outbound probes to arbitrary LAN addresses.
        // Client-initiated checks discover the authenticated peer through the public mux.
        $clean = preg_replace('/^a=(?:identity|candidate|end-of-candidates):?[^\n]*\n?/m', '', $normalized);
        $id = ++$this->sequence;
        $peer = new PeerConnection('127.0.0.1', $this->backendPort, true);
        try{
            $peer->setRemoteOffer(str_replace("\n", "\r\n", $clean));
            $peer->beginAnswer();
        }catch(\Throwable $e){
            $peer->close(); ($this->answer)($requestId, 400, '');
            ($this->log)('NetherNet native peer rejected the SDP offer.'); return;
        }
        $this->connections[$id] = [
            'peer'=>$peer, 'request'=>$requestId, 'networkId'=>$networkId, 'publicIp'=>$publicIp,
            'httpIp'=>$httpIp, 'identity'=>$verified, 'ufrag'=>null, 'paths'=>[], 'channels'=>[],
            'gathered'=>false, 'connected'=>false, 'accepted'=>false, 'answered'=>false,
            'created'=>microtime(true), 'last'=>microtime(true), 'disconnectedAt'=>null,
            'incoming'=>[], 'incomingBytes'=>0, 'outgoing'=>new \SplQueue(), 'outgoingBytes'=>0
        ];
        $this->requests[$requestId] = $id;
        $this->networkIds[$networkId] = $id;
        ($this->log)("NetherNet connection $id: authenticated offer accepted; gathering ICE.");
    }

    /** Match an observation to the selected native 5-tuple before creating a game session. */
    public function observePaths(array $events) : void{
        foreach($events as $event){
            $id = $this->ufrags[$event['ufrag']] ?? null;
            if($id !== null && isset($this->connections[$id])){
                $this->connections[$id]['paths'][$event['relayPort']] = [$event['ip'], $event['port']];
            }
        }
    }

    public function cancelRequest(int $requestId) : void{
        $id = $this->requests[$requestId] ?? null;
        if($id !== null){$this->close($id, 'Signaling connection closed before answer');}
    }

    public function tick() : void{
        foreach($this->anchor->pollEvents() as $event){
            if($event['type'] === 'gathering' && $event['data'] === '2'){$this->ready = true;}
            if($event['type'] === 'overflow' || ($event['type'] === 'state' && in_array($event['data'], ['4','5'], true))){
                throw new \RuntimeException('Private RyzeRTC ICE mux failed');
            }
        }
        $now = microtime(true);
        foreach(array_keys($this->connections) as $id){
            if(!isset($this->connections[$id])){continue;}
            try{
                foreach($this->connections[$id]['peer']->pollEvents(128) as $event){
                    if(!isset($this->connections[$id])){break;}
                    $this->event($id, $event);
                }
                if(!isset($this->connections[$id])){continue;}
                $c = &$this->connections[$id];
                if(!$c['answered'] && $c['gathered']){
                    $sdp = $c['peer']->localDescription();
                    if($sdp === null){unset($c); continue;}
                    $signed = $this->identity->signAnswer($sdp);
                    $answer = $this->bridge->preparedAnswer((string)$id, $signed, $c['publicIp'], $this->publicPort);
                    $parsed = NetherNetSharedPortSdp::rewriteAnswer($answer, $c['publicIp'], $this->publicPort);
                    $c['ufrag'] = $parsed['ufrag']; $this->ufrags[$c['ufrag']] = $id;
                    if(!($this->answer)($c['request'], 200, $answer)){$this->close($id, 'Signaling client left'); unset($c); continue;}
                    unset($this->requests[$c['request']]);
                    $c['answered'] = true;
                    ($this->log)("NetherNet connection $id: signed full-ICE answer sent on the original server port.");
                }
                if(!$c['accepted'] && $c['connected'] && $this->channelsOpen($c)){
                    $remote = $c['peer']->remoteAddress();
                    if($remote !== null && preg_match('/\A127\.0\.0\.1:([0-9]+)\z/D', $remote, $m) === 1 && isset($c['paths'][(int)$m[1]])){
                        [$ip, $port] = $c['paths'][(int)$m[1]];
                        $c['accepted'] = true;
                        ($this->accept)($id, $ip, $port, $c['identity'],
                            fn(string $batch, bool $immediate, ?int $receipt) => $this->send($id, $batch, $receipt),
                            fn(string $reason) => $this->close($id, $reason));
                        ($this->log)("NetherNet connection $id: ICE/DTLS/SCTP verified; Minecraft session started.");
                        $pending = $c['incoming']; $c['incoming'] = []; $c['incomingBytes'] = 0;
                        foreach($pending as $batch){($this->receive)($id, $batch);}
                    }
                }
                if(!isset($this->connections[$id])){unset($c); continue;}
                if((!$c['accepted'] && $now - $c['created'] > 30) || $now - $c['last'] > 120 ||
                    ($c['disconnectedAt'] !== null && $now - $c['disconnectedAt'] > 5)){
                    $this->close($id, 'NetherNet handshake or peer timeout'); unset($c); continue;
                }
                $this->flush($id);
                unset($c);
            }catch(\Throwable $e){
                ($this->log)("NetherNet connection $id closed after transport failure: " . get_class($e));
                $this->close($id, 'NetherNet transport error');
            }
        }
    }

    private function channelsOpen(array $c) : bool{
        return isset($c['channels']['ReliableDataChannel'], $c['channels']['UnreliableDataChannel']) &&
            $c['channels']['ReliableDataChannel']['open'] && $c['channels']['UnreliableDataChannel']['open'];
    }

    private function event(int $id, array $event) : void{
        $c = &$this->connections[$id];
        if($event['type'] === 'overflow'){throw new \RuntimeException('Native event queue overflow');}
        if($event['type'] === 'gathering' && $event['data'] === '2'){$c['gathered'] = true;}
        if($event['type'] === 'state'){
            if($event['data'] === '2'){$c['connected'] = true; $c['disconnectedAt'] = null;}
            elseif($event['data'] === '3'){$c['disconnectedAt'] ??= microtime(true);}
            elseif(in_array($event['data'], ['4','5'], true)){$this->close($id, 'WebRTC peer closed'); return;}
        }
        if(in_array($event['type'], ['channel','channel_open'], true)){
            $info = $c['peer']->channelInfo($event['channel']);
            $label = $info['label'];
            $reliable = $label === 'ReliableDataChannel';
            if(!in_array($label, ['ReliableDataChannel','UnreliableDataChannel'], true) ||
                $info['ordered'] !== $reliable || $info['reliable'] !== $reliable ||
                (!$reliable && $info['maxRetransmits'] !== 0) ||
                (isset($c['channels'][$label]) && $c['channels'][$label]['id'] !== $event['channel'])){
                throw new \UnexpectedValueException('Unexpected Minecraft DataChannel');
            }
            $c['channels'][$label] ??= ['id'=>$event['channel'], 'codec'=>new NetherNetFrameCodec(), 'open'=>false];
            $c['channels'][$label]['open'] = $info['open'];
        }
        if($event['type'] === 'channel_closed'){$this->close($id, 'Minecraft DataChannel closed'); return;}
        if($event['type'] === 'message'){
            if($event['detail'] !== 'binary'){throw new \UnexpectedValueException('Text message on Minecraft channel');}
            $label = null;
            foreach($c['channels'] as $name=>$channel){if($channel['id'] === $event['channel']){$label = $name; break;}}
            if($label === null){throw new \UnexpectedValueException('Unknown Minecraft DataChannel');}
            $c['last'] = microtime(true);
            $batch = $c['channels'][$label]['codec']->receive($event['data'], $label === 'ReliableDataChannel');
            if($batch !== null){
                if($c['accepted']){($this->receive)($id, $batch);}
                else{
                    if(count($c['incoming']) >= 64 || $c['incomingBytes'] + strlen($batch) > 262144){throw new \LengthException('Pending Minecraft queue exceeded');}
                    $c['incoming'][] = $batch; $c['incomingBytes'] += strlen($batch);
                }
            }
        }
    }

    private function send(int $id, string $batch, ?int $receipt) : void{
        if(!isset($this->connections[$id])){return;}
        if($receipt !== null){throw new \LogicException('SCTP transport receipts are not exposed; do not synthesize ACKs');}
        $c = &$this->connections[$id];
        if($c['outgoingBytes'] + strlen($batch) > 8 * 1024 * 1024){$this->close($id, 'Minecraft send queue exceeded'); return;}
        $info = $c['peer']->channelInfo($c['channels']['ReliableDataChannel']['id']);
        $limit = $info['maxMessageSize'] > 1 ? min(16384, $info['maxMessageSize']) : 16384;
        foreach(NetherNetFrameCodec::fragment($batch, $limit, true) as $frame){$c['outgoing']->enqueue($frame); $c['outgoingBytes'] += strlen($frame);}
    }

    private function flush(int $id) : void{
        $c = &$this->connections[$id];
        if(!$c['accepted']){return;}
        $channel = $c['channels']['ReliableDataChannel']['id'];
        $budget = 262144;
        while(!$c['outgoing']->isEmpty() && $budget > 0){
            if($c['peer']->channelInfo($channel)['bufferedAmount'] > 1048576){break;}
            $frame = $c['outgoing']->dequeue();
            $c['peer']->sendMessage($channel, $frame);
            $size = strlen($frame); $c['outgoingBytes'] -= $size; $budget -= $size;
        }
    }

    public function close(int $id, string $reason) : void{
        if(!isset($this->connections[$id])){return;}
        $c = $this->connections[$id];
        unset($this->connections[$id], $this->requests[$c['request']], $this->networkIds[$c['networkId']]);
        if($c['ufrag'] !== null){unset($this->ufrags[$c['ufrag']]);}
        $this->bridge->close((string)$id);
        if(!$c['answered']){($this->answer)($c['request'], 504, '');}
        if($c['accepted']){($this->disconnected)($id, $reason);}
        $c['peer']->close();
        ($this->log)("NetherNet connection $id closed: $reason");
    }

    public function shutdown() : void{
        foreach(array_keys($this->connections) as $id){$this->close($id, 'Server shutting down');}
        $this->bridge->shutdown(); $this->anchor->close(); $this->ready = false;
    }
}
