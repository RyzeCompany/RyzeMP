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

namespace pocketmine\network\mcpe\handler;

use pocketmine\block\Jigsaw;
use pocketmine\block\StructureBlock;
use pocketmine\block\tile\Jigsaw as TileJigsaw;
use pocketmine\block\tile\StructureBlock as TileStructureBlock;
use pocketmine\block\utils\GameMasterBlocks;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\StructureBlockUpdatePacket;
use pocketmine\network\mcpe\protocol\StructureTemplateDataRequestPacket;
use pocketmine\network\mcpe\protocol\StructureTemplateDataResponsePacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\player\Player;
use pocketmine\world\structure\StructureMirror;
use pocketmine\world\structure\StructureRotation;
use pocketmine\world\structure\StructureTemplate;
use pocketmine\world\structure\StructureTemplateBuilder;
use pocketmine\world\structure\StructureTemplateException;
use pocketmine\world\structure\StructureTemplateStorage;
use function mb_strlen;
use function preg_match;

/**
 * Requests of the structure block and jigsaw block screens: the edited settings of a structure block
 * (StructureBlockUpdatePacket, which also carries the Save and Load buttons), the template queries and exports of the
 * structure block screen (StructureTemplateDataRequestPacket), and the edited jigsaw data (block entity data sent
 * back by the jigsaw screen). Only operators in creative mode may use them.
 *
 * @internal
 */
final class StructureEditorRequests{
	/** Squared distance from the player within which blocks can be edited (same as block entity data updates) */
	private const MAX_DISTANCE_SQUARED = 10000;

	/** @phpstan-var \WeakMap<StructureTemplate, CacheableNbt<CompoundTag>>|null */
	private static ?\WeakMap $networkNbt = null;

	private function __construct(){
		//NOOP
	}

	private static function toVector(BlockPosition $pos) : Vector3{
		return new Vector3($pos->getX(), $pos->getY(), $pos->getZ());
	}

	private static function resendBlock(NetworkSession $session, Player $player, Vector3 $pos) : void{
		foreach($player->getWorld()->createBlockUpdatePackets([$pos]) as $packet){
			$session->sendDataPacket($packet);
		}
	}

	private static function isValidText(string $text) : bool{
		return mb_strlen($text) <= TileStructureBlock::MAX_NAME_LENGTH && preg_match('/[\x00-\x1f\x7f]/u', $text) === 0;
	}

	public static function handleStructureBlockUpdate(NetworkSession $session, Player $player, StructureBlockUpdatePacket $packet) : void{
		$pos = self::toVector($packet->blockPosition);
		$world = $player->getWorld();
		if($pos->distanceSquared($player->getLocation()) > self::MAX_DISTANCE_SQUARED || !$world->isInWorld($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){
			return;
		}
		$block = $world->getBlock($pos);
		$tile = $world->getTile($pos);
		if(!$block instanceof StructureBlock || !$tile instanceof TileStructureBlock){
			return;
		}
		$data = $packet->structureEditorData;
		$mode = TileStructureBlock::modeFromId($data->structureBlockType);
		if(!GameMasterBlocks::canUse($player) || $mode === null || !self::isValidText($data->structureName) || !self::isValidText($data->structureDataField)){
			self::resendBlock($session, $player, $pos);
			return;
		}

		$settings = $data->structureSettings;
		$tile->setStructureName($data->structureName);
		$tile->setDataField($data->structureDataField);
		$tile->setIncludePlayers($data->includePlayers);
		$tile->setShowBoundingBox($data->showBoundingBox);
		$tile->setRedstoneSaveMode($data->structureRedstoneSaveMode);
		$tile->setIgnoreEntities($settings->ignoreEntities);
		$tile->setRemoveBlocks($settings->ignoreBlocks);
		$tile->setSize($settings->dimensions->getX(), $settings->dimensions->getY(), $settings->dimensions->getZ());
		$tile->setOffset($settings->offset->getX(), $settings->offset->getY(), $settings->offset->getZ());
		$tile->setLastTouchedPlayerId($settings->lastTouchedByPlayerID);
		$tile->setRotation(StructureRotation::tryFrom($settings->rotation) ?? StructureRotation::NONE);
		$tile->setMirror(StructureMirror::tryFrom($settings->mirror) ?? StructureMirror::NONE);
		$tile->setAnimationMode($settings->animationMode);
		$tile->setAnimationSeconds($settings->animationSeconds);
		$tile->setIntegrity($settings->integrityValue);
		$tile->setSeed($settings->integritySeed);
		$tile->clearSpawnCompoundCache();
		//sets the mode of the block entity too, and sends both to the viewers
		$world->setBlock($pos, $block->setMode($mode));

		if($packet->isPowered){
			//the Save and Load buttons
			$error = $tile->trigger();
			if($error !== null){
				$player->sendMessage($error);
			}
		}
	}

	public static function handleStructureTemplateDataRequest(NetworkSession $session, Player $player, StructureTemplateDataRequestPacket $packet) : void{
		$name = $packet->structureTemplateName;
		$template = null;
		$responseType = $packet->requestType === StructureTemplateDataRequestPacket::TYPE_QUERY_SAVED_STRUCTURE ?
			StructureTemplateDataResponsePacket::TYPE_QUERY :
			StructureTemplateDataResponsePacket::TYPE_EXPORT;
		if(GameMasterBlocks::canUse($player)){
			$template = match($packet->requestType){
				StructureTemplateDataRequestPacket::TYPE_EXPORT_FROM_SAVE_MODE => self::captureForExport($player, $packet),
				StructureTemplateDataRequestPacket::TYPE_EXPORT_FROM_LOAD_MODE,
				StructureTemplateDataRequestPacket::TYPE_QUERY_SAVED_STRUCTURE => self::loadTemplate($player, $name),
				default => null
			};
		}
		$session->sendDataPacket(StructureTemplateDataResponsePacket::create(
			$name,
			$template !== null ? self::networkNbt($template) : null,
			$template !== null ? $responseType : StructureTemplateDataResponsePacket::TYPE_FAILURE
		));
	}

	/**
	 * The template's NBT for the network, encoded once per template (templates never change; the screen queries the
	 * same one again and again, and encoding the biggest takes hundreds of milliseconds).
	 *
	 * @phpstan-return CacheableNbt<CompoundTag>
	 */
	private static function networkNbt(StructureTemplate $template) : CacheableNbt{
		self::$networkNbt ??= new \WeakMap();
		return self::$networkNbt[$template] ??= new CacheableNbt($template->toNbt());
	}

	private static function loadTemplate(Player $player, string $name) : ?StructureTemplate{
		if(!TileStructureBlock::isValidStructureName($name)){
			return null;
		}
		try{
			return StructureTemplateStorage::get($player->getWorld())->load($name);
		}catch(StructureTemplateException){
			return null;
		}
	}

	/**
	 * The export button of save mode: the area of the screen's settings, as a template.
	 */
	private static function captureForExport(Player $player, StructureTemplateDataRequestPacket $packet) : ?StructureTemplate{
		$pos = self::toVector($packet->structureBlockPosition);
		if($pos->distanceSquared($player->getLocation()) > self::MAX_DISTANCE_SQUARED){
			return null;
		}
		$settings = $packet->structureSettings;
		$size = $settings->dimensions;
		$offset = $settings->offset;
		if(
			$size->getX() < 1 || $size->getY() < 1 || $size->getZ() < 1 ||
			$size->getX() > TileStructureBlock::MAX_SIZE_X || $size->getY() > TileStructureBlock::MAX_SIZE_Y || $size->getZ() > TileStructureBlock::MAX_SIZE_Z
		){
			return null;
		}
		$template = StructureTemplateBuilder::fromWorld(
			$player->getWorld(),
			$pos->getFloorX() + $offset->getX(), $pos->getFloorY() + $offset->getY(), $pos->getFloorZ() + $offset->getZ(),
			$size->getX(), $size->getY(), $size->getZ(),
			!$settings->ignoreEntities,
			!$settings->ignoreBlocks
		);
		return $template->isEmpty() ? null : $template;
	}

	/**
	 * Block entity data sent back by the jigsaw screen. Returns false if the block is not a jigsaw block.
	 */
	public static function handleJigsawData(NetworkSession $session, Player $player, Vector3 $pos, CompoundTag $nbt) : bool{
		$world = $player->getWorld();
		$block = $world->getBlock($pos);
		if(!$block instanceof Jigsaw){
			return false;
		}
		$tile = $world->getTile($pos);
		if(!$tile instanceof TileJigsaw || !GameMasterBlocks::canUse($player)){
			self::resendBlock($session, $player, $pos);
			return true;
		}
		if($tile->readJigsawData($nbt)){
			$tile->clearSpawnCompoundCache();
			$world->setBlock($pos, $block);
		}
		return true;
	}
}
