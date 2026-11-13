<?php
declare(strict_types=1);
namespace RyzeTests\Prespawn;

use pocketmine\entity\Skin;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\handler\InGamePacketHandler;
use pocketmine\network\mcpe\nethernet\NetherNetPacketSender;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\RequestChunkRadiusPacket;
use pocketmine\network\mcpe\protocol\SetLocalPlayerAsInitializedPacket;
use pocketmine\player\XboxLivePlayerInfo;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use Ramsey\Uuid\Uuid;

/** Synthetic server-side session ONLY in a temporary test plugin; never distributed in the PHAR. */
final class FixtureSession extends NetworkSession{
    public array $packets = [];
    public function sendDataPacket(ClientboundPacket $packet, bool $immediate = false) : bool{
        $this->packets[$packet::class] = true;
        return parent::sendDataPacket($packet, $immediate);
    }
}

final class Main extends PluginBase{
    private bool $finished = false;
    private int $batches = 0;
    protected function onEnable() : void{
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask($this->run(...)), 20);
    }
    private function fail(\Throwable $e) : void{
        $this->finished = true;
        $this->getLogger()->error('PRESPAWN FIXTURE FAILED: ' . get_class($e) . ' ' . $e->getMessage());
        $this->getLogger()->error($e->getTraceAsString());
        $this->getServer()->shutdown();
    }
    private function run() : void{
        try{
            $server = $this->getServer();
            $converter = TypeConverter::getInstance(2225);
            $broadcaster = $server->getPacketBroadcaster(2225);
            $session = new FixtureSession($server, $server->getNetwork()->getSessionManager(), PacketPool::getInstance(),
                new NetherNetPacketSender(function(string $payload, bool $immediate, ?int $receipt) : void{
                    if($payload === '' || $receipt !== null){throw new \RuntimeException('Invalid fixture game batch');}
                    ++$this->batches;
                }, static function(string $reason) : void{}), $broadcaster,
                $server->getEntityEventBroadcaster($broadcaster, $converter), ZlibCompressor::getInstance(), $converter, '127.0.0.1', 12345);
            $key = openssl_pkey_new(['ec' => ['curve_name' => 'secp384r1']]);
            $der = base64_decode(preg_replace('~-----[^-]+-----|\s+~', '', openssl_pkey_get_details($key)['key']), true);
            $session->useNetherNetTransport($der, '987654');
            $session->setProtocolId(2225);
            $info = new XboxLivePlayerInfo('987654', 'SpawnFixture', Uuid::uuid4(),
                new Skin('fixture', str_repeat("\x80", 64 * 64 * 4)), 'en_US');
            // Authentication is tested separately with real signatures. This fixture
            // starts at the already-authenticated player-creation boundary, on-server.
            foreach(['info'=>$info, 'authenticated'=>true, 'loggedIn'=>true, 'enableCompression'=>true, 'gameVersion'=>'1.26.60'] as $name=>$value){
                (new \ReflectionProperty(NetworkSession::class, $name))->setValue($session, $value);
            }
            $server->createPlayer($session, $info, true, null)->onCompletion(
                function($player) use($session, $server) : void{
                    try{
                        (new \ReflectionMethod(NetworkSession::class, 'onPlayerCreated'))->invoke($session, $player);
                        foreach(['JigsawStructureDataPacket','StartGamePacket','ItemRegistryPacket','CreativeContentPacket','CraftingDataPacket'] as $name){
                            if(!isset($session->packets['pocketmine\\network\\mcpe\\protocol\\' . $name])){
                                throw new \RuntimeException('Missing encoded pre-spawn packet ' . $name);
                            }
                        }
                        $session->getHandler()->handleRequestChunkRadius(RequestChunkRadiusPacket::create(4, 4));
                        $session->getHandler()->handleSetLocalPlayerAsInitialized(SetLocalPlayerAsInitializedPacket::create($player->getId()));
                        $deadline = microtime(true) + 45;
                        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() use($session, $player, $server, $deadline) : void{
                            if($this->finished){return;}
                            try{
                                if(!$session->isConnected()){throw new \RuntimeException('Session disconnected before world spawn');}
                                if($player->spawned && $session->getHandler() instanceof InGamePacketHandler){
                                    if($this->batches === 0){throw new \RuntimeException('No actual game batches encoded');}
                                    $session->onClientDisconnect('Fixture completed');
                                    $this->finished = true;
                                    $this->getLogger()->info('PASS: synthetic 2225 player completed real pre-spawn, creative inventory, recipes, terrain generation, world spawn and disconnect; batches=' . $this->batches);
                                    $server->shutdown();
                                }elseif(microtime(true) >= $deadline){throw new \RuntimeException('World spawn fixture timed out');}
                            }catch(\Throwable $e){$this->fail($e);}
                        }), 1);
                    }catch(\Throwable $e){$this->fail($e);}
                }, function() : void{$this->fail(new \RuntimeException('Fixture player creation failed'));});
        }catch(\Throwable $e){$this->fail($e);}
    }
}
