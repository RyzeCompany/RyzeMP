<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\cache;

use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\crafting\CraftingManager;
use pocketmine\crafting\CraftingManagerFromDataHelper;
use pocketmine\crafting\ExactRecipeIngredient;
use pocketmine\crafting\ShapelessRecipe;
use pocketmine\crafting\ShapelessRecipeType;
use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\event\RegisteredListener;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\item\VanillaItems;
use pocketmine\lang\Language;
use pocketmine\network\mcpe\ClientVersionSupport;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketSender;
use pocketmine\network\mcpe\protocol\CraftingDataPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\network\mcpe\StandardEntityEventBroadcaster;
use pocketmine\network\mcpe\StandardPacketBroadcaster;
use pocketmine\network\NetworkSessionManager;
use pocketmine\plugin\Plugin;
use pocketmine\Server;
use pocketmine\ServerConfigGroup;
use pocketmine\timings\Timings;
use pocketmine\timings\TimingsHandler;
use pocketmine\utils\Config;
use pocketmine\world\redstone\NullThreadSafeLogger;
use function count;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Every join sends the CraftingDataPacket bytes encoded once (CraftingDataCache::getEncodedCache()): they must be the
 * bytes encoding the packet gives, before and after recipes change, and what the session sends must not change.
 */
final class CraftingDataCacheTest extends TestCase{
	private static ?CraftingManager $manager = null;

	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $tempFiles = [];

	/**
	 * @var string[][] payloads sent by each recording session, by name
	 * @phpstan-var array<string, list<string>>
	 */
	private array $sent = [];

	private ?\Logger $globalLogger = null;

	protected function setUp() : void{
		parent::setUp();
		Timings::init();
		$this->globalLogger = \GlobalLogger::get();
		\GlobalLogger::set(new NullThreadSafeLogger());
	}

	protected function tearDown() : void{
		if($this->globalLogger !== null){
			\GlobalLogger::set($this->globalLogger);
			$this->globalLogger = null;
		}
		foreach($this->tempFiles as $file){
			@unlink($file);
		}
		$this->tempFiles = [];
		parent::tearDown();
	}

	private static function manager() : CraftingManager{
		return self::$manager ??= CraftingManagerFromDataHelper::make(BedrockDataFiles::RECIPES);
	}

	private static function encode(CraftingDataPacket $packet) : string{
		return NetworkSession::encodePacketTimed(new ByteBufferWriter(), $packet);
	}

	public function testEncodedCacheIsTheEncodingOfTheCachedPacket() : void{
		$cache = CraftingDataCache::getInstance();
		$manager = self::manager();
		$encoded = $cache->getEncodedCache($manager, TypeConverter::getInstance());
		self::assertSame(self::encode($cache->getCache($manager)), $encoded);
		self::assertSame($encoded, $cache->getEncodedCache($manager, TypeConverter::getInstance()), "built once");

		//forgotten on demand (the packet went to event handlers): encoded again from the cached packet
		$cache->clearEncodedCache($manager);
		self::assertSame($encoded, $cache->getEncodedCache($manager, TypeConverter::getInstance()));
	}

	public function testRecipesCanBeEncodedForEveryAcceptedClientProtocol() : void{
		$cache = CraftingDataCache::getInstance();
		foreach(ClientVersionSupport::getVersions() as $protocolId => $version){
			self::assertNotSame("", $cache->getEncodedCache(self::manager(), TypeConverter::getInstance($protocolId)), "Recipes failed for $version ($protocolId)");
		}
	}

	public function testRegisteringARecipeInvalidatesTheEncodedPacket() : void{
		$cache = CraftingDataCache::getInstance();
		$manager = CraftingManagerFromDataHelper::make(BedrockDataFiles::RECIPES);
		$packet = $cache->getCache($manager);
		$encoded = $cache->getEncodedCache($manager, TypeConverter::getInstance());

		//a plugin registers a recipe after startup
		$manager->registerShapelessRecipe(new ShapelessRecipe(
			[new ExactRecipeIngredient(VanillaItems::DIAMOND()), new ExactRecipeIngredient(VanillaItems::STICK())],
			[VanillaItems::NETHER_STAR()],
			ShapelessRecipeType::CRAFTING
		));
		$newPacket = $cache->getCache($manager);
		self::assertNotSame($packet, $newPacket);
		self::assertCount(count($packet->recipesWithTypeIds) + 1, $newPacket->recipesWithTypeIds);
		$newEncoded = $cache->getEncodedCache($manager, TypeConverter::getInstance());
		self::assertNotSame($encoded, $newEncoded);
		self::assertSame(self::encode($newPacket), $newEncoded);
	}

	private function server() : Server{
		$ymlPath = sys_get_temp_dir() . "/pm-cdc-test-" . uniqid() . ".yml";
		$propertiesPath = sys_get_temp_dir() . "/pm-cdc-test-" . uniqid() . ".properties";
		$this->tempFiles[] = $ymlPath;
		$this->tempFiles[] = $propertiesPath;
		$server = $this->createMock(Server::class);
		$server->method("getLogger")->willReturn(new NullThreadSafeLogger());
		$server->method("getLanguage")->willReturn($this->createMock(Language::class));
		$server->method("getConfigGroup")->willReturn(new ServerConfigGroup(
			new Config($ymlPath, Config::YAML, []),
			new Config($propertiesPath, Config::PROPERTIES, [])
		));
		return $server;
	}

	/** @internal */
	public function record(string $name, string $payload) : void{
		$this->sent[$name][] = $payload;
	}

	/**
	 * A logged in session recording what it sends under $name (no compression before the handshake).
	 */
	private function session(Server $server, NetworkSessionManager $manager, string $name) : NetworkSession{
		$this->sent[$name] = [];
		$sender = new class($this, $name) implements PacketSender{
			public function __construct(private CraftingDataCacheTest $test, private string $name){}

			public function send(string $payload, bool $immediate, ?int $receiptId) : void{
				$this->test->record($this->name, $payload);
			}

			public function close(string $reason = "unknown reason") : void{}
		};
		$broadcaster = new StandardPacketBroadcaster($server);
		$session = new NetworkSession(
			$server,
			$manager,
			PacketPool::getInstance(),
			$sender,
			$broadcaster,
			new StandardEntityEventBroadcaster($broadcaster, TypeConverter::getInstance()),
			ZlibCompressor::getInstance(),
			TypeConverter::getInstance(),
			"127.0.0.1",
			19132
		);
		(new \ReflectionProperty(NetworkSession::class, "loggedIn"))->setValue($session, true);
		return $session;
	}

	public function testSessionSendsTheSameBytes() : void{
		$cache = CraftingDataCache::getInstance();
		$craftingManager = self::manager();
		$packet = $cache->getCache($craftingManager);
		$encoded = $cache->getEncodedCache($craftingManager, TypeConverter::getInstance());
		$server = $this->server();
		$manager = new NetworkSessionManager();

		$plain = $this->session($server, $manager, "plain");
		$cached = $this->session($server, $manager, "cached");
		self::assertTrue($plain->sendDataPacket($packet));
		self::assertTrue($cached->sendEncodedDataPacket($packet, $encoded));
		$plain->onClientDisconnect("test");
		$cached->onClientDisconnect("test");
		self::assertNotEmpty($this->sent["plain"]);
		self::assertSame($this->sent["plain"], $this->sent["cached"]);

		//with DataPacketSendEvent handlers the packet goes through them and what they leave is encoded
		$listener = new RegisteredListener(function(DataPacketSendEvent $ev) : void{
			$ev->setPackets([TextPacket::raw("replaced")]);
		}, EventPriority::NORMAL, $this->createConfiguredMock(Plugin::class, ["isEnabled" => true]), false, new TimingsHandler("test"));
		HandlerListManager::global()->getListFor(DataPacketSendEvent::class)->register($listener);
		try{
			$plain = $this->session($server, $manager, "plainEvent");
			$cached = $this->session($server, $manager, "cachedEvent");
			self::assertTrue($plain->sendDataPacket($packet));
			self::assertTrue($cached->sendEncodedDataPacket($packet, $encoded));
			$plain->onClientDisconnect("test");
			$cached->onClientDisconnect("test");
		}finally{
			HandlerListManager::global()->getListFor(DataPacketSendEvent::class)->unregister($listener);
		}
		self::assertSame($this->sent["plainEvent"], $this->sent["cachedEvent"]);
		self::assertNotSame($this->sent["plain"], $this->sent["plainEvent"]);
	}
}
