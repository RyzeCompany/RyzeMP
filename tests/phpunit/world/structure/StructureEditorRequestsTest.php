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

namespace pocketmine\world\structure;

use pocketmine\block\tile\StructureBlock as TileStructureBlock;
use pocketmine\block\utils\GameMasterBlocks;
use pocketmine\block\utils\StructureBlockType;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\handler\StructureEditorRequests;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\StructureBlockUpdatePacket;
use pocketmine\network\mcpe\protocol\StructureTemplateDataRequestPacket;
use pocketmine\network\mcpe\protocol\StructureTemplateDataResponsePacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\StructureEditorData;
use pocketmine\network\mcpe\protocol\types\StructureSettings;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\Player;
use pocketmine\world\redstone\RedstoneTestCase;

/**
 * Only operators in creative mode may edit structure blocks or query structure templates; template responses are
 * encoded once.
 */
final class StructureEditorRequestsTest extends RedstoneTestCase{

	private function player(bool $creative, bool $operator) : Player{
		$player = $this->createMock(Player::class);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("getLocation")->willReturn(Location::fromObject(new Vector3(0.5, 1, 0.5), $this->world));
		$player->method("isCreative")->willReturn($creative);
		$player->method("hasPermission")->willReturnCallback(fn($permission) => $operator && $permission === DefaultPermissions::ROOT_OPERATOR);
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	/**
	 * @param ClientboundPacket[] $sent
	 */
	private function session(array &$sent) : NetworkSession{
		$session = $this->createMock(NetworkSession::class);
		$session->method("sendDataPacket")->willReturnCallback(function(ClientboundPacket $packet) use (&$sent) : bool{
			$sent[] = $packet;
			return true;
		});
		return $session;
	}

	private static function settings() : StructureSettings{
		$settings = new StructureSettings();
		$settings->paletteName = "default";
		$settings->ignoreEntities = false;
		$settings->ignoreBlocks = false;
		$settings->allowNonTickingChunks = false;
		$settings->dimensions = new BlockPosition(3, 3, 3);
		$settings->offset = new BlockPosition(1, 0, 1);
		$settings->lastTouchedByPlayerID = 1;
		$settings->rotation = StructureRotation::CLOCKWISE_90->value;
		$settings->mirror = StructureMirror::NONE->value;
		$settings->animationMode = StructurePlaceSettings::ANIMATION_NONE;
		$settings->animationSeconds = 0.0;
		$settings->integrityValue = 100.0;
		$settings->integritySeed = 0;
		$settings->pivot = new Vector3(0, 0, 0);
		return $settings;
	}

	private static function updatePacket(string $name) : StructureBlockUpdatePacket{
		$data = new StructureEditorData();
		$data->structureName = $name;
		$data->filteredStructureName = $name;
		$data->structureDataField = "";
		$data->includePlayers = false;
		$data->showBoundingBox = true;
		$data->structureBlockType = StructureEditorData::TYPE_LOAD;
		$data->structureSettings = self::settings();
		$data->structureRedstoneSaveMode = TileStructureBlock::REDSTONE_SAVE_MEMORY;
		//with the Load button pressed
		return StructureBlockUpdatePacket::create(new BlockPosition(2, 1, 2), $data, true, false);
	}

	public function testCanUse() : void{
		self::assertFalse(GameMasterBlocks::canUse($this->player(false, true)), "survival operator");
		self::assertFalse(GameMasterBlocks::canUse($this->player(true, false)), "creative player who is not an operator");
		self::assertTrue(GameMasterBlocks::canUse($this->player(true, true)));
	}

	public function testOnlyCreativeOperatorsEditStructureBlocks() : void{
		$this->set(2, 1, 2, VanillaBlocks::STRUCTURE_BLOCK());
		$tile = $this->world->getTileAt(2, 1, 2);
		self::assertInstanceOf(TileStructureBlock::class, $tile);

		foreach([[false, true], [true, false]] as [$creative, $operator]){
			$sent = [];
			StructureEditorRequests::handleStructureBlockUpdate($this->session($sent), $this->player($creative, $operator), self::updatePacket("test:refused"));
			self::assertSame("", $tile->getStructureName(), "the settings are not changed");
			self::assertSame(StructureBlockType::SAVE, $tile->getMode());
			self::assertSame(StructureRotation::NONE, $tile->getRotation());
			self::assertNotEmpty($sent, "the block is sent back to the player");
		}

		$sent = [];
		StructureEditorRequests::handleStructureBlockUpdate($this->session($sent), $this->player(true, true), self::updatePacket("test:allowed"));
		self::assertSame("test:allowed", $tile->getStructureName());
		self::assertSame(StructureBlockType::LOAD, $tile->getMode());
		self::assertSame(StructureRotation::CLOCKWISE_90, $tile->getRotation());
		self::assertTrue($tile->getOffset()->equals(new Vector3(1, 0, 1)));
	}

	private function query(Player $player, string $name) : StructureTemplateDataResponsePacket{
		$sent = [];
		StructureEditorRequests::handleStructureTemplateDataRequest($this->session($sent), $player, StructureTemplateDataRequestPacket::create(
			$name,
			new BlockPosition(2, 1, 2),
			self::settings(),
			StructureTemplateDataRequestPacket::TYPE_QUERY_SAVED_STRUCTURE
		));
		self::assertCount(1, $sent);
		self::assertInstanceOf(StructureTemplateDataResponsePacket::class, $sent[0]);
		return $sent[0];
	}

	public function testTemplateQueries() : void{
		$builder = new StructureTemplateBuilder(2, 2, 2);
		$builder->setBlock(0, 0, 0, VanillaBlocks::STONE()->getStateId());
		StructureTemplateStorage::get($this->world)->save("test:query", $builder->build(), false);

		$refused = $this->query($this->player(true, false), "test:query");
		self::assertSame(StructureTemplateDataResponsePacket::TYPE_FAILURE, $refused->responseType);
		self::assertNull($refused->nbt);

		$first = $this->query($this->player(true, true), "test:query");
		self::assertSame(StructureTemplateDataResponsePacket::TYPE_QUERY, $first->responseType);
		self::assertNotNull($first->nbt);
		self::assertSame(2, $first->nbt->getRoot()->getListTag(StructureTemplate::TAG_SIZE)?->get(0)?->getValue());
		$second = $this->query($this->player(true, true), "test:query");
		self::assertSame($first->nbt, $second->nbt, "the template is encoded once");

		self::assertSame(StructureTemplateDataResponsePacket::TYPE_FAILURE, $this->query($this->player(true, true), "test:missing")->responseType);
	}
}
