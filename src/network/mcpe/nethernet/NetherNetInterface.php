<?php
declare(strict_types=1);
namespace pocketmine\network\mcpe\nethernet;

use pocketmine\network\NetworkInterface;
use pocketmine\network\mcpe\raklib\RakLibInterface;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\Server;

/** Authenticated HTTP + RyzeRTC adapter using the existing RakLib UDP owner. */
final class NetherNetInterface implements NetworkInterface{
    private NetherNetHttpServer $http;
    private NetherNetTransport $transport;
    private NetherNetAuthKeys $keys;
    private bool $announced = false;
    private bool $stopped = false;
    private array $dns = [];
    private array $dnsPending = [];

    public function __construct(private Server $server, private string $ip, private int $port,
        private RakLibInterface $raklib, private NetherNetSessionGateway $gateway){
        $log = fn(string $message) => $this->server->getLogger()->info($message);
        $this->keys = new NetherNetAuthKeys($server->getAsyncPool(), $log);
        $this->http = new NetherNetHttpServer($ip, $port,
            fn() => !$this->transport->isReady() ? null : [
                'name'=>$server->getMotd(), 'protocol'=>ProtocolInfo::CURRENT_PROTOCOL, 'version'=>ProtocolInfo::MINECRAFT_VERSION_NETWORK,
                'level'=>$server->getWorldManager()->getDefaultWorld()?->getFolderName() ?? '',
                'players'=>count($server->getOnlinePlayers()), 'maxPlayers'=>$server->getMaxPlayers(),
                'gameType'=>match($server->getGamemode()->getEnglishName()){'Creative'=>1,'Adventure'=>2,'Spectator'=>3,default=>0}
            ], $this->offer(...), fn(int $request) => $this->transport->cancelRequest($request));
        $bridge = new NetherNetSharedPortSignalingBridge($raklib->authorizeNetherNetUfrag(...), $raklib->revokeNetherNetUfrag(...));
        $this->transport = new NetherNetTransport($port,
            new NetherNetServerIdentity($server->getDataPath() . 'nethernet/operator-key.pem'), $bridge,
            fn() => $this->keys->getVerifier(),
            function(int $id, int $status, string $sdp) : bool{
                if($status === 401){$this->keys->tick(true);}
                return $this->http->respond($id, $status, $sdp, $status === 200 ? 'application/sdp' : 'text/plain');
            },
            function(int $id, string $ip, int $port, array $identity, \Closure $send, \Closure $close) : void{
                $this->gateway->accept($id, $ip, $port, $identity['publicKeyDer'], $send, $close, false, $identity['xuid']);
            }, $gateway->receive(...), $gateway->close(...), $log);
        $raklib->configureNetherNetSharedPort($this->transport->getBackendPort());
    }

    public function start() : void{
        $this->http->start(); $this->keys->tick();
        $this->server->getLogger()->info("NetherNet starting on {$this->ip}:{$this->port} TCP + RakLib shared UDP; fetching official Minecraft keys.");
    }
    public function setName(string $name) : void{}

    private function offer(int $request, string $networkId, string $sdp, string $host, string $peerIp) : void{
        if(!$this->transport->isReady()){$this->http->respond($request, 503); return;}
        $ip = NetherNetHostAddress::resolve($this->ip, $host);
        if($ip !== null){$this->transport->offer($request, $networkId, $sdp, $ip, $peerIp); return;}
        $hostname = preg_replace('/:[0-9]{1,5}$/D', '', $host);
        if(strlen($hostname) > 253 || preg_match('/\A(?=.{1,253}\z)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\z/D', $hostname) !== 1){
            $this->http->respond($request, 400); return;
        }
        if(isset($this->dns[$hostname]) && $this->dns[$hostname][1] > time()){
            $this->transport->offer($request, $networkId, $sdp, $this->dns[$hostname][0], $peerIp); return;
        }
        if(count($this->dnsPending) >= 16){$this->http->respond($request, 429); return;}
        $this->dnsPending[$request] = true;
        $this->server->getAsyncPool()->submitTask(new NetherNetResolveHostTask($hostname,
            function(?string $ip) use($request, $networkId, $sdp, $hostname, $peerIp) : void{
                unset($this->dnsPending[$request]);
                if($this->stopped || !$this->http->isPending($request)){return;}
                if($ip === null){$this->http->respond($request, 400); return;}
                if(count($this->dns) >= 32){array_shift($this->dns);}
                $this->dns[$hostname] = [$ip, time() + 300];
                $this->transport->offer($request, $networkId, $sdp, $ip, $peerIp);
            }));
    }

    public function tick() : void{
        if($this->stopped){return;}
        $this->keys->tick();
        $this->transport->observePaths($this->raklib->pollNetherNetPeers());
        $this->transport->tick();
        $this->http->tick();
        if(!$this->announced && $this->transport->isReady()){
            $this->announced = true;
            $this->server->getLogger()->info("NetherNet READY: authenticated HTTP TCP and shared RakNet/WebRTC UDP on {$this->ip}:{$this->port}. Retail client verification remains required.");
        }
    }
    public function shutdown() : void{
        if($this->stopped){return;}
        $this->stopped = true;
        $this->http->shutdown(); $this->transport->shutdown(); $this->gateway->shutdown(); $this->keys->shutdown();
    }
}
