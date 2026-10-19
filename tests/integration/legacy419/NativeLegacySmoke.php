<?php

declare(strict_types=1);

namespace ryzemp\integration;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\encryption\EncryptionContext;
use pocketmine\network\mcpe\encryption\EncryptionUtils;
use pocketmine\network\mcpe\handler\InGamePacketHandler;
use pocketmine\network\mcpe\JwtUtils;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketSender;
use pocketmine\network\mcpe\protocol as P;
use pocketmine\network\mcpe\protocol\serializer\BitSet;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\types\command\CommandOriginData;
use pocketmine\network\mcpe\protocol\types\login\clientdata\ClientData;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use Ramsey\Uuid\Uuid;

/** Synthetic client against real native sessions, async auth, encryption, worlds and chunk workers. */
final class NativeLegacySmoke extends PluginBase{
	private NetworkSession $session;
	private \OpenSSLAsymmetricKey $key;
	private ?EncryptionContext $cipher = null;
	private array $queue = [];
	private ?string $closed = null;
	private int $chunks = 0;
	private int $probeTexts = 0;
	private int $ticks = 0;
	private ?int $probeTick = null;
	private ?Vector3 $probePosition = null;
	private bool $finished = false;
	private bool $started = false;

	protected function onEnable() : void{
		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
			if($this->finished){ return; }
			try{
				if(!$this->started){ $this->started = true; $this->startClient(); }
				$this->pump();
				if($this->closed !== null){ throw new \RuntimeException('Session closed: ' . $this->closed); }
				if(++$this->ticks > 1000){ throw new \RuntimeException('Login/spawn timed out, chunks=' . $this->chunks); }
				if($this->session->getHandler() instanceof InGamePacketHandler && $this->probeTick === null){
					if($this->chunks < 1){ throw new \RuntimeException('Spawned without chunks'); }
					$player = $this->session->getPlayer();
					$this->probePosition = $player->getPosition()->add(0.1, 0, 0);
					$this->send(P\PlayerAuthInputPacket::create($this->probePosition->add(0, 1.62, 0), 0, 0, 0, 0, 0, new BitSet(PlayerAuthInputFlags::NUMBER_OF_FLAGS), 1, 0, 0, null, new Vector2(0, 0), 1, new Vector3(0, 0, 0), null, null, null, null, 0, 0, new Vector3(0, 0, 0), new Vector2(0, 0)));
					$chat = new P\TextPacket(); $chat->type = P\TextPacket::TYPE_CHAT; $chat->sourceName = 'LegacyProbe'; $chat->message = 'native 419 smoke';
					$this->send($chat);
					$this->send(P\CommandRequestPacket::create('/help', new CommandOriginData(0, Uuid::fromString(Uuid::NIL), '', 0), false, '', 0));
					$this->probeTick = $this->ticks;
				}elseif($this->probeTick !== null && $this->ticks > $this->probeTick + 4){
					if($this->cipher === null || $this->probeTexts < 2){ throw new \RuntimeException('Encrypted chat/command exchange did not complete'); }
					if($this->session->getPlayer()->getPosition()->distance($this->probePosition) > 0.05){ throw new \RuntimeException('Native movement was not applied'); }
					$this->getLogger()->info('LEGACY419_JOIN_SMOKE_OK encrypted=cfb8 chunks=' . $this->chunks . ' movement=1 chat=1 command=1');
					$this->finished = true;
					$this->session->disconnect('Smoke completed');
					$this->getServer()->shutdown();
				}
			}catch(\Throwable $error){
				$this->finished = true;
				$this->getLogger()->critical('LEGACY419_JOIN_SMOKE_FAILED: ' . $error::class . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString());
				$this->getServer()->shutdown();
			}
		}), 1);
	}

	private function startClient() : void{
		$server = $this->getServer();
		if($server->requiresAuthentication()){ throw new \RuntimeException('Synthetic client requires an isolated offline test server'); }
		$sender = new class($this) implements PacketSender{
			public function __construct(private NativeLegacySmoke $owner){}
			public function send(string $payload, bool $immediate, ?int $receiptId) : void{ $this->owner->capture($payload, $receiptId); }
			public function close(string $reason = 'unknown reason') : void{ $this->owner->closed($reason); }
		};
		$converter = TypeConverter::getInstance();
		$broadcaster = $server->getPacketBroadcaster(P\ProtocolInfo::CURRENT_PROTOCOL);
		$this->session = new NetworkSession($server, $server->getNetwork()->getSessionManager(), P\PacketPool::getInstance(), $sender, $broadcaster, $server->getEntityEventBroadcaster($broadcaster, $converter), ZlibCompressor::getInstance(), $converter, '127.0.0.1', 40019);
		$key = openssl_pkey_new(['ec' => ['curve_name' => JwtUtils::BEDROCK_SIGNING_KEY_CURVE_NAME]]);
		if($key === false){ throw new \RuntimeException('Client key generation failed: ' . openssl_error_string()); }
		$this->key = $key;
		$public = base64_encode(JwtUtils::emitDerPublicKey($this->key));
		$header = ['alg' => 'ES384', 'x5u' => $public];
		$chain = JwtUtils::create($header, ['nbf' => time() - 10, 'exp' => time() + 300, 'identityPublicKey' => $public, 'extraData' => ['displayName' => 'LegacyProbe', 'identity' => '00000000-0000-4000-8000-000000000419', 'XUID' => '']], $this->key);
		$claims = [];
		foreach((new \ReflectionClass(ClientData::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property){
			$claims[$property->getName()] = match($property->getType()->getName()){ 'int' => 0, 'string' => '', 'bool' => false, 'array' => [] };
		}
		$claims = array_replace($claims, [
			'ClientRandomId' => 419, 'CurrentInputMode' => 1, 'DefaultInputMode' => 1,
			'DeviceOS' => 1, 'GameVersion' => '1.16.100', 'LanguageCode' => 'en_US',
			'SkinId' => 'LegacyProbe', 'SkinImageWidth' => 64, 'SkinImageHeight' => 32,
			'SkinData' => base64_encode(str_repeat("\x7f\x7f\x7f\xff", 64 * 32)),
			'SkinResourcePatch' => base64_encode('{"geometry":{"default":"geometry.humanoid.custom"}}'),
			'SkinGeometryData' => base64_encode('{}'), 'ArmSize' => 'wide'
		]);
		foreach(['CompatibleWithClientSideChunkGen', 'IsEditorMode', 'PlayFabId', 'SkinGeometryDataEngineVersion', 'TrustedSkin'] as $modernField){ unset($claims[$modernField]); }
		$this->send(P\LoginPacket::create(419, json_encode(['chain' => [$chain]], JSON_THROW_ON_ERROR), JwtUtils::create($header, $claims, $this->key)));
	}

	public function capture(string $payload, ?int $receipt) : void{ $this->queue[] = [$payload, $receipt]; }
	public function closed(string $reason) : void{ $this->closed = $reason; }

	private function send(P\ServerboundPacket $packet) : void{
		$out = new ByteBufferWriter(); $packet->encode($out, 419);
		$batch = new ByteBufferWriter(); PacketBatch::encodeRaw($batch, [$out->getData()]);
		$payload = ZlibCompressor::getInstance()->compress($batch->getData());
		$this->session->handleEncoded($this->cipher?->encrypt($payload) ?? $payload);
	}

	private function pump() : void{
		while($this->queue !== []){
			[$payload, $receipt] = array_shift($this->queue);
			$plain = $this->cipher?->decrypt($payload) ?? $payload;
			$in = new ByteBufferReader(ZlibCompressor::getInstance()->decompress($plain));
			foreach(PacketBatch::decodeRaw($in) as $raw){
				$packet = P\PacketPool::getInstance()->getPacket($raw) ?? throw new \RuntimeException('Unknown outgoing packet');
				$reader = new ByteBufferReader($raw); $packet->decode($reader, 419);
				if($reader->getUnreadLength() !== 0){ throw new \RuntimeException('Trailing bytes in ' . $packet->getName()); }
				if($packet instanceof P\TextPacket && $this->probeTick !== null){ ++$this->probeTexts; }
				if($packet instanceof P\DisconnectPacket){ throw new \RuntimeException('Disconnected: ' . ($packet->message ?? '')); }
				if($packet instanceof P\ServerToClientHandshakePacket){
					[$header, $claims] = JwtUtils::parse($packet->jwt);
					$secret = EncryptionUtils::generateSharedSecret($this->key, JwtUtils::parseDerPublicKey(base64_decode($header['x5u'], true)));
					$this->cipher = EncryptionContext::forProtocol(EncryptionUtils::generateKey($secret, base64_decode($claims['salt'], true)), 419);
					$this->send(P\ClientToServerHandshakePacket::create());
				}elseif($packet instanceof P\ResourcePacksInfoPacket){
					$this->send(P\ResourcePackClientResponsePacket::create(P\ResourcePackClientResponsePacket::STATUS_HAVE_ALL_PACKS, []));
				}elseif($packet instanceof P\ResourcePackStackPacket){
					$this->send(P\ResourcePackClientResponsePacket::create(P\ResourcePackClientResponsePacket::STATUS_COMPLETED, []));
				}elseif($packet instanceof P\StartGamePacket){
					if($packet->blockPalette !== [] || $packet->enableNewInventorySystem){ throw new \RuntimeException('Unexpected legacy bootstrap settings'); }
					$this->send(P\RequestChunkRadiusPacket::create(4, 4));
					$this->send(P\SetLocalPlayerAsInitializedPacket::create($packet->actorRuntimeId));
				}elseif($packet instanceof P\LevelChunkPacket){
					++$this->chunks;
				}
			}
			if($receipt !== null){ $this->session->handleAckReceipt($receipt); }
		}
	}
}
