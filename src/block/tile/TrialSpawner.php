<?php

declare(strict_types=1);

namespace pocketmine\block\tile;

use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use function in_array;
use function preg_match;

/** Preserves the configuration of a generated trial spawner in LevelDB. */
final class TrialSpawner extends Spawnable{
	public const TAG_NORMAL_CONFIG = "normal_config";
	public const TAG_OMINOUS_CONFIG = "ominous_config";
	public const TAG_REQUIRED_PLAYER_RANGE = "required_player_range";
	public const TAG_TARGET_COOLDOWN_LENGTH = "target_cooldown_length";

	private ?CompoundTag $configuration = null;

	public static function copyConfiguration(CompoundTag $from, CompoundTag $to) : void{
		foreach([self::TAG_NORMAL_CONFIG, self::TAG_OMINOUS_CONFIG] as $name){
			$tag = $from->getTag($name);
			if($tag instanceof StringTag || $tag instanceof CompoundTag){
				$to->setTag($name, clone $tag);
			}
		}
		foreach([self::TAG_REQUIRED_PLAYER_RANGE, self::TAG_TARGET_COOLDOWN_LENGTH] as $name){
			$tag = $from->getTag($name);
			if($tag instanceof IntTag){
				$to->setTag($name, clone $tag);
			}
		}
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->configuration = CompoundTag::create();
		self::copyConfiguration($nbt, $this->configuration);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		if($this->configuration !== null){
			self::copyConfiguration($this->configuration, $nbt);
		}
	}

	protected function getRequiredNetworkBlockName() : string{
		return BlockTypeNames::TRIAL_SPAWNER;
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt, TypeConverter $typeConverter) : void{
		$config = $this->configuration?->getTag(self::TAG_NORMAL_CONFIG);
		if($config instanceof StringTag &&
			preg_match('~^minecraft:trial_chamber/(?:[^/]+/)*([a-z_]+)/normal$~D', $config->getValue(), $matches) === 1 &&
			(!in_array($matches[1], ["breeze", "poison_skeleton"], true) || $typeConverter->getProtocolId() >= ProtocolInfo::PROTOCOL_1_21_0)){
			$entity = "minecraft:" . ($matches[1] === "poison_skeleton" ? "bogged" : $matches[1]);
			$nbt->setTag("spawn_data", CompoundTag::create()
				->setString("TypeId", $entity)
				->setTag("entity", CompoundTag::create()->setString("id", $entity)));
		}
	}
}
