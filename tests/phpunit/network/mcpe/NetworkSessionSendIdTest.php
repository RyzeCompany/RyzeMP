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

namespace pocketmine\network\mcpe;

use PHPUnit\Framework\TestCase;
use pocketmine\lang\Language;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\NetworkSessionManager;
use pocketmine\Server;
use pocketmine\ServerConfigGroup;
use pocketmine\timings\Timings;
use pocketmine\utils\Config;
use pocketmine\world\redstone\NullThreadSafeLogger;
use function array_shift;
use function class_exists;
use function count;
use function method_exists;
use function strlen;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Every session gets a send id which is open in the native send buffers for the session's lifetime and never valid
 * again afterwards, so that stale viewer lists can't reach another session: chosen by
 * NativeSendBuffers::openNew() (chunkutils2 >= 0.3.10), by PHP's counter otherwise.
 */
final class NetworkSessionSendIdTest extends TestCase{
	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $tempFiles = [];

	private ?\Logger $globalLogger = null;

	/**
	 * @var string[][] what each recording sender (see sender()) got, by its name
	 * @phpstan-var array<string, list<string>>
	 */
	private array $sent = [];

	protected function setUp() : void{
		parent::setUp();
		Timings::init();
		//the packet handler inspection logs at debug level
		$this->globalLogger = \GlobalLogger::get();
		\GlobalLogger::set(new NullThreadSafeLogger());
	}

	protected function tearDown() : void{
		if($this->globalLogger !== null){
			\GlobalLogger::set($this->globalLogger);
			$this->globalLogger = null;
		}
		NetworkSession::setNativeSendBuffersEnabled(true);
		foreach($this->tempFiles as $file){
			@unlink($file);
		}
		$this->tempFiles = [];
		parent::tearDown();
	}

	private function server() : Server{
		$ymlPath = sys_get_temp_dir() . "/pm-send-id-test-" . uniqid() . ".yml";
		$propertiesPath = sys_get_temp_dir() . "/pm-send-id-test-" . uniqid() . ".properties";
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

	/**
	 * A sender which records the payloads under $name (before the handshake there is no compression: the batches as
	 * they are).
	 */
	private function sender(string $name) : PacketSender{
		$this->sent[$name] = [];
		return new class($this, $name) implements PacketSender{
			public function __construct(private NetworkSessionSendIdTest $test, private string $name){}

			public function send(string $payload, bool $immediate, ?int $receiptId) : void{
				$this->test->record($this->name, $payload);
			}

			public function close(string $reason = "unknown reason") : void{}
		};
	}

	/** @internal */
	public function record(string $name, string $payload) : void{
		$this->sent[$name][] = $payload;
	}

	private function newSession(Server $server, NetworkSessionManager $manager, ?PacketSender $sender = null) : NetworkSession{
		$broadcaster = new StandardPacketBroadcaster($server);
		return new NetworkSession(
			$server,
			$manager,
			PacketPool::getInstance(),
			$sender ?? $this->createMock(PacketSender::class),
			$broadcaster,
			new StandardEntityEventBroadcaster($broadcaster, TypeConverter::getInstance()),
			ZlibCompressor::getInstance(),
			TypeConverter::getInstance(),
			"127.0.0.1",
			19132
		);
	}

	public function testSendIdsAreOpenForTheSessionLifetimeAndNeverReused() : void{
		$modes = class_exists(NativeSendBuffers::class) ? [true, false] : [false];
		foreach($modes as $nativeEnabled){
			NetworkSession::setNativeSendBuffersEnabled($nativeEnabled);
			$native = NetworkSession::getNativeSendBuffers();
			self::assertSame($nativeEnabled, $native !== null);
			$server = $this->server();
			$manager = new NetworkSessionManager();

			/** @var bool[] $seen */
			$seen = [];
			/** @var NetworkSession[] $open */
			$open = [];
			for($round = 0; $round < 60; ++$round){
				$session = $this->newSession($server, $manager);
				$id = $session->getSendId();
				self::assertArrayNotHasKey($id, $seen, "a send id is never given twice");
				$seen[$id] = true;
				if($native !== null){
					self::assertTrue($native->isOpen($id));
					/** @phpstan-ignore function.alreadyNarrowedType (chunkutils2 < 0.3.10 lacks it; the PHPStan stub always has it) */
					if(method_exists($native, "openNew")){
						self::assertGreaterThanOrEqual(1 << 62, $id, "ids of openNew()");
					}
					//the session's packets go to its own buffer
					$session->addToSendBuffer("abc");
					self::assertSame(NetworkSession::prefixPacketBuffer("abc"), $native->take($id));
				}
				$open[] = $session;

				//sessions come and go: the closed ones' ids are dead, the slots get reused under new ids
				if($round % 3 === 2){
					$closed = array_shift($open);
					self::assertNotNull($closed);
					$closedId = $closed->getSendId();
					$closed->onClientDisconnect("test");
					self::assertFalse($closed->isConnected());
					if($native !== null){
						self::assertFalse($native->isOpen($closedId));
						$native->append($closedId, "x");
						self::assertSame(0, $native->appendMany([$closedId], "x"));
						self::assertSame("", $native->take($closedId));
					}
				}
			}
			if($native !== null){
				self::assertSame(count($open), $native->count());
				$ids = [];
				foreach($open as $session){
					$ids[] = $session->getSendId();
				}
				self::assertSame(count($ids), $native->appendMany($ids, "y"));
				foreach($ids as $id){
					self::assertSame("y", $native->take($id));
				}
			}
			foreach($open as $session){
				$session->onClientDisconnect("test");
			}
			if($native !== null){
				self::assertSame(0, $native->count());
			}
		}
	}

	/**
	 * Tests swap the native send buffers (setNativeSendBuffersEnabled()), and openNew() gives the same ids again in a
	 * new instance: a session made before the swap keeps the send buffers which gave its id for its own packets, so it
	 * neither writes to, takes from nor closes the buffer of the new instance's session with the same id. Sessions made
	 * without native send buffers keep their PHP buffer when they are enabled, and the other way round.
	 */
	public function testSessionsKeepTheirSendBuffersWhenTestsSwapThem() : void{
		if(!class_exists(NativeSendBuffers::class)){
			self::markTestSkipped("chunkutils2 >= 0.3.9 is not loaded");
		}
		$server = $this->server();
		$manager = new NetworkSessionManager();
		$prefixed = static fn(string $buffer) : string => NetworkSession::prefixPacketBuffer($buffer);

		NetworkSession::setNativeSendBuffersEnabled(true);
		$oldBuffers = NetworkSession::getNativeSendBuffers();
		self::assertNotNull($oldBuffers);
		$old = $this->newSession($server, $manager, $this->sender("old"));
		$oldId = $old->getSendId();

		NetworkSession::setNativeSendBuffersEnabled(true);
		$newBuffers = NetworkSession::getNativeSendBuffers();
		self::assertNotNull($newBuffers);
		self::assertNotSame($oldBuffers, $newBuffers);
		$new = $this->newSession($server, $manager, $this->sender("new"));
		$newId = $new->getSendId();
		/** @phpstan-ignore function.alreadyNarrowedType (chunkutils2 < 0.3.10 lacks it; the PHPStan stub always has it) */
		if(method_exists($newBuffers, "openNew")){
			self::assertSame($oldId, $newId, "the case this test is about: two live sessions with the same id");
		}

		$new->addToSendBuffer("new");
		$old->addToSendBuffer("old");
		self::assertSame(strlen($prefixed("new")), $newBuffers->length($newId));
		self::assertSame(strlen($prefixed("old")), $oldBuffers->length($oldId));

		//the old session's batch is its own packet, and the new session's buffer stays open with its packet
		$old->onClientDisconnect("test");
		self::assertSame([$prefixed("old")], $this->sent["old"]);
		self::assertFalse($oldBuffers->isOpen($oldId));
		self::assertTrue($newBuffers->isOpen($newId));
		self::assertSame(strlen($prefixed("new")), $newBuffers->length($newId));
		$new->onClientDisconnect("test");
		self::assertSame([$prefixed("new")], $this->sent["new"]);
		self::assertSame(0, $newBuffers->count());

		//made without native send buffers, enabled afterwards: the PHP buffer
		NetworkSession::setNativeSendBuffersEnabled(false);
		$php = $this->newSession($server, $manager, $this->sender("php"));
		NetworkSession::setNativeSendBuffersEnabled(true);
		$enabledBuffers = NetworkSession::getNativeSendBuffers();
		self::assertNotNull($enabledBuffers);
		//made with native send buffers, disabled afterwards: its native buffer
		$native = $this->newSession($server, $manager, $this->sender("native"));
		NetworkSession::setNativeSendBuffersEnabled(false);
		$php->addToSendBuffer("php");
		$native->addToSendBuffer("native");
		self::assertSame(1, $enabledBuffers->count());
		self::assertSame(strlen($prefixed("native")), $enabledBuffers->length($native->getSendId()));
		$php->onClientDisconnect("test");
		$native->onClientDisconnect("test");
		self::assertSame([$prefixed("php")], $this->sent["php"]);
		self::assertSame([$prefixed("native")], $this->sent["native"]);
		self::assertSame(0, $enabledBuffers->count());
	}
}
