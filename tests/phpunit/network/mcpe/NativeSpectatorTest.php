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
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\VarInt;
use pocketmine\entity\effect\EffectManager;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\types\AbilitiesLayer;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\network\mcpe\protocol\types\GameMode as ProtocolGameMode;
use pocketmine\network\mcpe\protocol\UpdateAbilitiesPacket;
use pocketmine\network\mcpe\protocol\UpdatePlayerGameTypePacket;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\world\redstone\NullThreadSafeLogger;
use function array_keys;
use function bin2hex;
use function hex2bin;
use function ksort;
use function spl_object_id;
use function str_repeat;

/**
 * Spectator is sent to the client as the native game type 6. The expected bytes are payloads BDS 1.26.52 sent to a
 * client (gophertunnel) when "/gamemode spectator" was run and when a spectator joined.
 */
final class NativeSpectatorTest extends TestCase{

	/** UpdatePlayerGameType: spectator, actor unique ID -8589934591, tick 123 */
	private const BDS_UPDATE_PLAYER_GAME_TYPE = "0cfdffffff3f7b";
	/** UpdateAbilities sent to a player joining in spectator: spectator layer, then the base layer */
	private const BDS_UPDATE_ABILITIES_SPECTATOR = "01000000feffffff02010202003f0f0200000302000000000000000000000000000100ffff0f00ff0d0000cdcc4c3d0000803fcdcccc3d";

	public function testCoreGameModeToProtocol() : void{
		$converter = TypeConverter::getInstance();
		self::assertSame(ProtocolGameMode::SURVIVAL, $converter->coreGameModeToProtocol(GameMode::SURVIVAL));
		self::assertSame(ProtocolGameMode::CREATIVE, $converter->coreGameModeToProtocol(GameMode::CREATIVE));
		self::assertSame(ProtocolGameMode::ADVENTURE, $converter->coreGameModeToProtocol(GameMode::ADVENTURE));
		self::assertSame(6, $converter->coreGameModeToProtocol(GameMode::SPECTATOR)); //ProtocolGameMode::SPECTATOR
	}

	public function testProtocolGameModeToCore() : void{
		$converter = TypeConverter::getInstance();
		self::assertSame(GameMode::SURVIVAL, $converter->protocolGameModeToCore(ProtocolGameMode::SURVIVAL));
		self::assertSame(GameMode::CREATIVE, $converter->protocolGameModeToCore(ProtocolGameMode::CREATIVE));
		self::assertSame(GameMode::ADVENTURE, $converter->protocolGameModeToCore(ProtocolGameMode::ADVENTURE));
		self::assertSame(GameMode::SPECTATOR, $converter->protocolGameModeToCore(ProtocolGameMode::SPECTATOR));
		self::assertSame(GameMode::SPECTATOR, $converter->protocolGameModeToCore(ProtocolGameMode::SURVIVAL_VIEWER));
		self::assertSame(GameMode::SPECTATOR, $converter->protocolGameModeToCore(ProtocolGameMode::CREATIVE_VIEWER));
		self::assertNull($converter->protocolGameModeToCore(ProtocolGameMode::DEFAULT));
	}

	public function testUpdatePlayerGameTypeMatchesBds() : void{
		$packet = UpdatePlayerGameTypePacket::create(ProtocolGameMode::SPECTATOR, -8589934591, 123);
		$out = new ByteBufferWriter();
		$packet->encode($out, \pocketmine\network\mcpe\protocol\ProtocolInfo::CURRENT_PROTOCOL);

		$header = new ByteBufferWriter();
		VarInt::writeUnsignedInt($header, UpdatePlayerGameTypePacket::NETWORK_ID);
		self::assertSame(bin2hex($header->getData()) . self::BDS_UPDATE_PLAYER_GAME_TYPE, bin2hex($out->getData()));
	}

	public function testSpectatorLayerMatchesBds() : void{
		$out = new ByteBufferWriter();
		SpectatorAbilities::layer()->encode($out, \pocketmine\network\mcpe\protocol\ProtocolInfo::CURRENT_PROTOCOL);
		//layer 2, set mask 0x20f3f, values 0x20300 (invulnerable, flying, no clip), no speeds
		self::assertSame("02003f0f020000030200" . str_repeat("00", 12), bin2hex($out->getData()));

		$raw = hex2bin(self::BDS_UPDATE_ABILITIES_SPECTATOR);
		self::assertNotFalse($raw);
		$header = new ByteBufferWriter();
		VarInt::writeUnsignedInt($header, UpdateAbilitiesPacket::NETWORK_ID);
		$packet = new UpdateAbilitiesPacket();
		$packet->decode(new ByteBufferReader($header->getData() . $raw), \pocketmine\network\mcpe\protocol\ProtocolInfo::CURRENT_PROTOCOL);
		$layers = $packet->getData()->getAbilityLayers();
		self::assertCount(2, $layers);
		self::assertSame(AbilitiesLayer::LAYER_SPECTATOR, $layers[0]->getLayerId());
		self::assertSame(AbilitiesLayer::LAYER_BASE, $layers[1]->getLayerId());

		$expected = $layers[0]->getBoolAbilities();
		$actual = SpectatorAbilities::layer()->getBoolAbilities();
		ksort($expected);
		ksort($actual);
		self::assertSame($expected, $actual);
		self::assertNull($layers[0]->getFlySpeed());
		self::assertNull($layers[0]->getWalkSpeed());
	}

	/**
	 * @var int[][][] player entity ID => SetActorData sent to it, as the list of property IDs of each packet
	 * @phpstan-var array<int, list<list<int>>>
	 */
	private array $actorData = [];

	/**
	 * A Player built without its constructor whose session records the SetActorData it gets (property IDs only).
	 */
	private function player(int $id) : Player{
		$broadcaster = $this->createMock(EntityEventBroadcaster::class);
		$broadcaster->method("syncActorData")->willReturnCallback(function(array $recipients, Entity $entity, array $properties) : void{
			foreach($recipients as $session){
				$this->recordActorData($session, $properties);
			}
		});
		$session = (new \ReflectionClass(NetworkSession::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(NetworkSession::class, "entityEventBroadcaster"))->setValue($session, $broadcaster);
		$player = (new \ReflectionClass(Player::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(Player::class, "networkSession"))->setValue($player, $session);
		(new \ReflectionProperty(Entity::class, "id"))->setValue($player, $id);
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true); //never disposed like a real player
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new NullThreadSafeLogger());
		$properties = new EntityMetadataCollection();
		$properties->setGenericFlag(EntityMetadataFlags::CAN_SHOW_NAMETAG, true);
		$properties->setGenericFlag(EntityMetadataFlags::AFFECTED_BY_GRAVITY, true);
		$properties->setGenericFlag(EntityMetadataFlags::CRAWLING, false);
		$properties->setFloat(EntityMetadataProperties::BOUNDING_BOX_WIDTH, 0.6);
		$properties->setFloat(EntityMetadataProperties::BOUNDING_BOX_HEIGHT, 1.8);
		$properties->setFloat(EntityMetadataProperties::SCALE, 1.0);
		$properties->clearDirtyProperties();
		(new \ReflectionProperty(Entity::class, "networkProperties"))->setValue($player, $properties);
		$this->sessionOwners[spl_object_id($session)] = $id;
		return $player;
	}

	/** @var int[] session object ID => player entity ID */
	private array $sessionOwners = [];

	/**
	 * @param mixed[] $properties
	 * @internal
	 */
	public function recordActorData(NetworkSession $session, array $properties) : void{
		ksort($properties); //like StandardEntityEventBroadcaster
		$this->actorData[$this->sessionOwners[spl_object_id($session)]][] = array_keys($properties);
	}

	/**
	 * @return int[][]|null the property IDs of each SetActorData the player got, null if none
	 * @phpstan-return list<list<int>>|null
	 */
	private function actorDataOf(int $id) : ?array{
		return $this->actorData[$id] ?? null;
	}

	private function resetActorData() : void{
		$this->actorData = [];
	}

	/**
	 * @return Player[] the player, then its viewer
	 */
	private function playerWithViewer() : array{
		$player = $this->player(1);
		$viewer = $this->player(2);
		(new \ReflectionProperty(Entity::class, "hasSpawned"))->setValue($player, [spl_object_id($viewer) => $viewer]);
		return [$player, $viewer];
	}

	/**
	 * Flying down in spectator holds the sneak key without sneaking: that is not a state the server has to revert
	 * (it used to resend all the metadata to the player on every press and release, re-applying its appearance).
	 */
	public function testSneakKeyAloneIsNotReverted() : void{
		$player = $this->player(1);
		self::assertTrue($player->toggleSneak(false, true));
		self::assertTrue($player->isSneakPressed());
		self::assertFalse($player->isSneaking());
		self::assertTrue($player->toggleSneak(false, false));
		self::assertFalse($player->isSneakPressed());
	}

	/**
	 * Sprinting, sneaking, swimming, gliding and crawling from the client's input go to the viewers only, like BDS:
	 * a moving spectator gets no SetActorData about itself.
	 */
	public function testClientPredictedStateIsNotEchoed() : void{
		[$player, $viewer] = $this->playerWithViewer();
		self::assertTrue($player->applyClientPredictedState(static function() use ($player) : bool{
			$player->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::SPRINTING, true);
			$player->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::SNEAKING, true);
			$player->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::CRAWLING, true);
			$player->getNetworkProperties()->setFloat(EntityMetadataProperties::BOUNDING_BOX_HEIGHT, 1.5);
			return true;
		}));
		self::assertNull($this->actorDataOf(1), "the player itself must not get its own predicted state");
		self::assertSame([[EntityMetadataProperties::FLAGS, EntityMetadataProperties::BOUNDING_BOX_HEIGHT, EntityMetadataProperties::FLAGS2]], $this->actorDataOf(2));
		self::assertSame([], $player->getNetworkProperties()->getDirty());
	}

	/**
	 * What the server changed besides the client's own state (pending data, event handlers) still reaches the
	 * player, and a rejected state gets the full metadata to revert it.
	 */
	public function testServerChangesAndRejectionsStillReachThePlayer() : void{
		[$player, $viewer] = $this->playerWithViewer();
		$player->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::ONFIRE, true);
		$player->applyClientPredictedState(static function() use ($player) : bool{
			$player->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::SPRINTING, true);
			$player->getNetworkProperties()->setString(EntityMetadataProperties::NAMETAG, "renamed by a plugin");
			$player->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::INVISIBLE, true);
			return true;
		});
		//pending fire to everyone first, then the event handler's changes (flags include a server-side bit)
		self::assertSame([[EntityMetadataProperties::FLAGS], [EntityMetadataProperties::FLAGS, EntityMetadataProperties::NAMETAG]], $this->actorDataOf(1));
		self::assertSame([[EntityMetadataProperties::FLAGS], [EntityMetadataProperties::FLAGS, EntityMetadataProperties::NAMETAG]], $this->actorDataOf(2));

		$this->resetActorData();
		self::assertFalse($player->applyClientPredictedState(static fn() : bool => false));
		$all = $player->getNetworkProperties()->getAll();
		ksort($all);
		self::assertSame([array_keys($all)], $this->actorDataOf(1), "rejected: full metadata to the player");
		self::assertNull($this->actorDataOf(2));
	}

	/**
	 * The metadata flags a player computes for itself.
	 * @return int[]
	 */
	private function computedFlags(GameMode $mode, bool $blockCollision, bool $silent) : array{
		$player = $this->player(1);
		$player->size = new EntitySizeInfo(1.8, 0.6, 1.62);
		(new \ReflectionProperty(Living::class, "effectManager"))->setValue($player, new EffectManager($player));
		(new \ReflectionProperty(Player::class, "gamemode"))->setValue($player, $mode);
		(new \ReflectionProperty(Player::class, "blockCollision"))->setValue($player, $blockCollision);
		(new \ReflectionProperty(Entity::class, "silent"))->setValue($player, $silent);
		$properties = new EntityMetadataCollection();
		(new \ReflectionMethod(Player::class, "syncNetworkData"))->invoke($player, $properties);
		$flags = [];
		foreach([EntityMetadataFlags::HAS_COLLISION, EntityMetadataFlags::SILENT] as $flag){
			$value = $properties->getAll()[EntityMetadataProperties::FLAGS] ?? null;
			if($value instanceof LongMetadataProperty && (($value->getValue() >> $flag) & 1) === 1){
				$flags[] = $flag;
			}
		}
		return $flags;
	}

	/**
	 * BDS 1.26.52 keeps HAS_COLLISION and doesn't set SILENT for a spectator (captured at join and on /gamemode,
	 * flags 19,35,48,49): it goes through blocks by its no_clip ability. A plugin's faux spectator (no block
	 * collision outside spectator mode) still shows no collision.
	 */
	public function testSpectatorMetadataFlagsMatchBds() : void{
		self::assertSame([EntityMetadataFlags::HAS_COLLISION], $this->computedFlags(GameMode::SPECTATOR, false, true));
		self::assertSame([EntityMetadataFlags::HAS_COLLISION], $this->computedFlags(GameMode::SURVIVAL, true, false));
		self::assertSame([EntityMetadataFlags::SILENT], $this->computedFlags(GameMode::CREATIVE, false, true));
	}
}
