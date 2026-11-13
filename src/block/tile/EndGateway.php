<?php

declare(strict_types=1);

namespace pocketmine\block\tile;

use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\convert\TypeConverter;
use function count;

final class EndGateway extends Spawnable{
	public const TAG_EXIT = "ExitPortal";
	public const TAG_EXACT = "ExactTeleport";

	private ?Vector3 $exit = null;
	private bool $exact = false;

	public function getExit() : ?Vector3{
		return $this->exit?->asVector3();
	}

	public function isExact() : bool{
		return $this->exact;
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$coords = $nbt->getListTag(self::TAG_EXIT, IntTag::class)?->getValue() ?? [];
		$this->exit = count($coords) === 3 ? new Vector3($coords[0]->getValue(), $coords[1]->getValue(), $coords[2]->getValue()) : null;
		$this->exact = $nbt->getByte(self::TAG_EXACT, 0) !== 0;
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		if($this->exit !== null){
			$nbt->setTag(self::TAG_EXIT, self::exitTag($this->exit));
			$nbt->setByte(self::TAG_EXACT, $this->exact ? 1 : 0);
		}
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt, TypeConverter $typeConverter) : void{
		$nbt->setInt("Age", 200)->setTag(self::TAG_EXIT, self::exitTag($this->exit ?? $this->position));
	}

	/** @return ListTag<IntTag> */
	public static function exitTag(Vector3 $position) : ListTag{
		return new ListTag([
			new IntTag($position->getFloorX()), new IntTag($position->getFloorY()), new IntTag($position->getFloorZ())
		]);
	}
}
