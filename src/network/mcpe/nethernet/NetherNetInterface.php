<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

use amber\nethernet\Transport;
use amber\nethernet\TransportConfig;
use amber\nethernet\backend\ExtRtcPeer;
use amber\nethernet\identity\ServerIdentity;
use amber\nethernet\signaling\HttpServer;
use amber\nethernet\signaling\HttpSignaling;
use amber\nethernet\signaling\ServerMetadata;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\raklib\RakLibInterface;
use pocketmine\network\NetworkInterface;
use pocketmine\Server;

/**
 * Opt-in experimental NetherNet transport for Linux/PHP ext-rtc.
 *
 * It uses TCP on the ordinary server port and the existing RakLib UDP socket.
 * The WebRTC backend listens only on loopback and is never advertised.
 * Always require trusted official Minecraft identities.
 *
 * @internal
 */
final class NetherNetInterface implements NetworkInterface{
	private ?Transport $transport = null;
	private ?HttpServer $http = null;
	private ?NetherNetHttpAnswerAdapter $answer = null;
	private ?NetherNetSharedPortSignalingBridge $bridge = null;
	private ?NetherNetGameTransportListener $game = null;
	private string $motd;

	public function __construct(
		private Server $server,
		private RakLibInterface $raklib,
		private PacketBroadcaster $packetBroadcaster,
		private EntityEventBroadcaster $entityEventBroadcaster,
		private TypeConverter $typeConverter,
		private string $bindIp,
		private int $publicPort,
		private string $advertisedIp,
		private int $privateIcePort
	){
		$this->motd = $server->getMotd();
	}

	/** MUST be called before RakLib's thread/socket starts. */
	public function prepare() : void{
		if(!class_exists(Transport::class) || !class_exists(ExtRtcPeer::class)){
			throw new \RuntimeException("NetherNet requires amber-pm/nethernet in this PHAR");
		}
		ExtRtcPeer::assertAvailable(); // fail closed if ext-rtc is missing
		if(filter_var($this->bindIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ||
			filter_var($this->advertisedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ||
			$this->advertisedIp === "0.0.0.0" || $this->publicPort < 1 || $this->publicPort > 65535 ||
			$this->privateIcePort < 1 || $this->privateIcePort > 65535 ||
			$this->privateIcePort === $this->publicPort){
			throw new \InvalidArgumentException("NetherNet requires IPv4 bind, concrete advertised IP and private ICE port distinct from public game port");
		}
		$this->raklib->configureNetherNetSharedPort($this->privateIcePort);
	}

	public function start() : void{
		if($this->transport !== null){
			return;
		}
		$config = new TransportConfig();
		$identity = new NetherNetMinecraftIdentityHandler(
			$this->server->getAuthKeyProvider(),
			$this->loadServerIdentity(),
			$config
		);
		$gateway = new NetherNetSessionGateway(
			$this->server,
			$this->server->getNetwork(),
			$this->packetBroadcaster,
			$this->entityEventBroadcaster,
			$this->typeConverter
		);
		$game = new NetherNetGameTransportListener($gateway);
		$bridge = new NetherNetSharedPortSignalingBridge(
			$this->raklib->authorizeNetherNetUfrag(...),
			$this->raklib->revokeNetherNetUfrag(...)
		);
		$answer = new NetherNetHttpAnswerAdapter($bridge, $this->advertisedIp, $this->publicPort);
		$signaling = new HttpSignaling(
			$config,
			fn() => new ServerMetadata(
				$this->motd, 2225, "1.26.60", "world",
				count($this->server->getOnlinePlayers()),
				$this->server->getMaxPlayers(), 0
			),
			$game
		);
		$answer->bind($signaling);
		$transport = new Transport(
			$config,
			$identity,
			$answer,
			fn() => new ExtRtcPeer($config, $this->privateIcePort, $this->privateIcePort, "127.0.0.1", true)
		);
		$signaling->bind($transport);
		try{
			$http = new HttpServer($config, $signaling, "tcp://" . $this->bindIp . ":" . $this->publicPort);
		}catch(\Throwable $e){
			$bridge->shutdown();
			$transport->stop();
			throw $e;
		}
		$this->transport = $transport;
		$this->http = $http;
		$this->game = $game;
		$this->bridge = $bridge;
		$this->answer = $answer;
		$this->server->getLogger()->notice("EXPERIMENTAL NetherNet: TCP {$this->bindIp}:{$this->publicPort}, shared RakLib UDP {$this->publicPort}, private ICE UDP 127.0.0.1:{$this->privateIcePort}");
	}

	private function loadServerIdentity() : ServerIdentity{
		$path = $this->server->getDataPath() . "nethernet-identity.pem";
		if(!is_file($path)){
			$key = openssl_pkey_new(["private_key_type" => OPENSSL_KEYTYPE_EC, "curve_name" => "secp384r1"]);
			if($key === false || !openssl_pkey_export($key, $pem)){
				throw new \RuntimeException("Cannot create long-lived NetherNet operator identity");
			}
			if(file_put_contents($path, $pem, LOCK_EX) !== strlen($pem)){
				throw new \RuntimeException("Cannot persist NetherNet operator identity");
			}
			@chmod($path, 0600);
		}
		$pem = file_get_contents($path);
		if(!is_string($pem)){
			throw new \RuntimeException("Cannot read persistent NetherNet operator identity");
		}
		return new ServerIdentity($pem, "RyzeMP");
	}

	public function setName(string $name) : void{
		$this->motd = $name;
	}

	public function tick() : void{
		$this->transport?->poll();
		$this->http?->poll();
	}

	public function shutdown() : void{
		$this->http?->stop();
		$this->http = null;
		$this->transport?->stop();
		$this->transport = null;
		$this->bridge?->shutdown();
		$this->bridge = null;
		$this->game?->shutdown();
		$this->game = null;
		$this->answer = null;
	}
}
