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

namespace pocketmine\block;

use pocketmine\block\utils\Ageable;
use pocketmine\block\utils\AgeableTrait;
use pocketmine\block\utils\BlockEventHelper;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\event\block\BlockBurnEvent;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use pocketmine\world\BlockTransaction;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\GameRules;
use pocketmine\world\weather\BiomeClimate;
use pocketmine\world\World;
use function count;
use function intdiv;
use function max;
use function min;
use function mt_rand;

class Fire extends BaseFire implements Ageable{
	use AgeableTrait {
		describeBlockOnlyState as describeAge;
	}

	public const MAX_AGE = 15;

	/**
	 * Horizontal sides the fire is drawn on (facing => facing), plus the ceiling. Since Bedrock 1.26.60 these are
	 * block states (minecraft:connection_*): fire that doesn't stand on a full or flammable block clings to the
	 * flammable blocks around it.
	 *
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	protected array $connections = [];
	protected bool $connectedUp = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$this->describeAge($w);
		$w->horizontalFacingFlags($this->connections);
		$w->bool($this->connectedUp);
	}

	public function hasConnection(int $facing) : bool{
		return $facing === Facing::UP ? $this->connectedUp : isset($this->connections[$facing]);
	}

	/** @return $this */
	public function setConnection(int $facing, bool $connected) : self{
		if($facing === Facing::UP){
			$this->connectedUp = $connected;
		}elseif(Facing::axis($facing) !== Axis::Y){
			if($connected){
				$this->connections[$facing] = $facing;
			}else{
				unset($this->connections[$facing]);
			}
		}else{
			throw new \InvalidArgumentException("Fire can't connect downwards");
		}
		return $this;
	}

	/**
	 * Recomputes the connections from the blocks around the fire, like vanilla: fire on a full or flammable block
	 * has none, otherwise it connects to every flammable neighbour. Returns true if anything changed.
	 *
	 * @param Block|null $down the block below, when the caller already has it
	 */
	protected function recalculateConnections(?Block $down = null) : bool{
		$down ??= $this->getSide(Facing::DOWN);
		$onGround = $down->isFlammable() || $down->getSupportType(Facing::UP) === SupportType::FULL;
		$changed = false;
		foreach([...Facing::HORIZONTAL, Facing::UP] as $facing){
			$connect = !$onGround && $this->getSide($facing)->isFlammable();
			if($connect !== $this->hasConnection($facing)){
				$this->setConnection($facing, $connect);
				$changed = true;
			}
		}
		return $changed;
	}

	/**
	 * Gives the fire at the given position the shape (connections) that fits its surroundings. {@link World::setBlock()}
	 * calls it for new fire (where there was none); use it after replacing fire with fire of another shape.
	 */
	public static function refreshShapeAt(World $world, Vector3 $pos) : void{
		$block = $world->getBlock($pos);
		if($block instanceof Fire && $block->recalculateConnections()){
			$world->setBlock($block->position, $block);
		}
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$this->recalculateConnections();
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	private static ?int $airStateId = null;
	/**
	 * @var int[] block state ID => flame encouragement
	 * @phpstan-var array<int, int>
	 */
	private static array $flameEncouragementByStateId = [];

	/** At most this many entries in $encouragesFlamesByStateId (emptied when full: an entry is cheap to compute again). */
	private const ENCOURAGES_FLAMES_CACHE_SIZE = 1024;
	/**
	 * @var bool[] block state ID => whether its flame encouragement is above 0
	 * @phpstan-var array<int, bool>
	 */
	private static array $encouragesFlamesByStateId = [];

	public function getLightLevel() : int{
		return 15;
	}

	protected function getFireDamage() : int{
		return 1;
	}

	private function canBeSupportedBy(Block $block) : bool{
		return $block->getSupportType(Facing::UP) === SupportType::FULL;
	}

	public function onNearbyBlockChange() : void{
		$world = $this->position->getWorld();
		$down = $this->getSide(Facing::DOWN);
		if(SoulFire::canBeSupportedBy($down)){
			$world->setBlock($this->position, VanillaBlocks::SOUL_FIRE());
		}elseif(!$this->canBeSupportedBy($this->getSide(Facing::DOWN)) && !$this->hasAdjacentFlammableBlocks()){
			$world->setBlock($this->position, VanillaBlocks::AIR());
		}else{
			if($this->recalculateConnections()){
				$world->setBlock($this->position, $this);
			}
			$world->scheduleDelayedBlockUpdate($this->position, mt_rand(30, 40));
		}
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		if($this->position->getWorld()->getGameRule(GameRules::DO_FIRE_TICK) === false){
			//dofiretick: fire neither spreads nor burns out
			return;
		}
		$down = $this->getSide(Facing::DOWN);

		if(!$down->burnsForever() && Utils::getRandomFloat() < 0.2 + $this->age * 0.03 && self::isNearRain($this->position->getWorld(), $this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ())){
			//rain puts out fire (vanilla), except on blocks that burn forever (netherrack)
			$this->position->getWorld()->setBlock($this->position, VanillaBlocks::AIR());
			return;
		}

		$result = null;
		//fire placed without block updates (or loaded from an older world) gets its 1.26.60 shape here; on full or
		//flammable ground (most fire) this reads no other block
		if($this->recalculateConnections($down)){
			$result = $this;
		}
		if($this->age < self::MAX_AGE && mt_rand(0, 2) === 0){
			$this->age++;
			$result = $this;
		}
		$canSpread = true;

		if(!$down->burnsForever()){
			if($this->age === self::MAX_AGE){
				if(!$down->isFlammable() && mt_rand(0, 3) === 3){ //1/4 chance to extinguish
					$canSpread = false;
					$result = VanillaBlocks::AIR();
				}
			}elseif(!$this->hasAdjacentFlammableBlocks()){
				$canSpread = false;
				if($down->isTransparent() || $this->age > 3){
					$result = VanillaBlocks::AIR();
				}
			}
		}

		$world = $this->position->getWorld();
		if($result !== null){
			$world->setBlock($this->position, $result);
		}

		$world->scheduleDelayedBlockUpdate($this->position, mt_rand(30, 40));

		if($canSpread){
			$humid = $this->isInHumidBiome();
			$this->burnBlocksAround($humid);
			//nether fires on netherrack usually have nothing around that encourages flames: spreadFire() would then
			//neither change a block nor draw a number, after reading 200 blocks
			if($this->mayEncourageFlamesInReach()){
				$this->spreadFire($humid);
			}
		}
	}

	public function onScheduledUpdate() : void{
		$this->onRandomTick();
	}

	public function pausesScheduledUpdatesOutsideTickingChunks() : bool{
		//fires reschedule themselves forever (on netherrack they never go out): without this, every nether fire of
		//every loaded chunk would keep updating after the players moved on
		return true;
	}

	private function hasAdjacentFlammableBlocks() : bool{
		foreach(Facing::ALL as $face){
			if($this->getSide($face)->isFlammable()){
				return true;
			}
		}

		return false;
	}

	private function isInHumidBiome() : bool{
		$pos = $this->position;
		return BiomeClimate::isHumid($pos->getWorld()->getBiomeId($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()));
	}

	private function burnBlocksAround(bool $humid) : void{
		//vanilla: blocks burn up faster in humid biomes (lower bound), and the blocks above and below more often than
		//the ones beside the fire
		$offset = $humid ? -50 : 0;

		foreach($this->getHorizontalSides() as $side){
			$this->burnBlock($side, 300 + $offset);
		}

		$this->burnBlock($this->getSide(Facing::UP), 250 + $offset);
		$this->burnBlock($this->getSide(Facing::DOWN), 250 + $offset);
	}

	private function burnBlock(Block $block, int $chanceBound) : void{
		if(mt_rand(0, $chanceBound) < $block->getFlammability()){
			$cancelled = false;
			if(BlockBurnEvent::hasHandlers()){
				$ev = new BlockBurnEvent($block, $this);
				$ev->call();
				$cancelled = $ev->isCancelled();
			}
			if(!$cancelled){
				$block->onIncinerate();

				$world = $this->position->getWorld();
				if($world->getBlock($block->position)->isSameState($block)){
					$spreadedFire = false;
					if(mt_rand(0, $this->age + 9) < 5 && !$world->getWeather()->isRainingAt($block->position->getFloorX(), $block->position->getFloorY(), $block->position->getFloorZ())){
						$fire = clone $this;
						$fire->age = min(self::MAX_AGE, $fire->age + (mt_rand(0, 4) >> 2));
						$spreadedFire = $this->spreadBlock($block, $fire);
					}
					if(!$spreadedFire){
						$world->setBlock($block->position, VanillaBlocks::AIR());
					}
				}
			}
		}
	}

	private function spreadFire(bool $humid) : void{
		$world = $this->position->getWorld();
		$difficultyChanceIncrease = $world->getDifficulty() * 7;
		$ageDivisor = $this->age + 30;
		$fireX = (int) $this->position->x;
		$fireY = (int) $this->position->y;
		$fireZ = (int) $this->position->z;
		//fires on netherrack update forever: the 5x8x5 blocks around the ~50 candidate positions are read once into a
		//grid instead of up to 6 times each; read again after a spread, which may have changed blocks (events)
		$grid = null;

		for($y = -1; $y <= 4; ++$y){
			$targetY = $y + $fireY;
			if($targetY < World::Y_MIN || $targetY >= World::Y_MAX){
				continue;
			}
			//Higher blocks have a lower chance of catching fire
			$randomBound = 100 + ($y > 1 ? ($y - 1) * 100 : 0);

			for($z = -1; $z <= 1; ++$z){
				$targetZ = $z + $fireZ;
				for($x = -1; $x <= 1; ++$x){
					if($x === 0 && $y === 0 && $z === 0){
						continue;
					}
					$targetX = $x + $fireX;
					if(!$world->isInWorld($targetX, $targetY, $targetZ)){
						continue;
					}

					$grid ??= self::readSpreadGrid($world, $fireX, $fireY, $fireZ);
					$i = ($x + 2) * 40 + ($z + 2) * 8 + ($y + 2);
					if($grid[$i] !== self::GRID_AIR){
						continue; //not air, or in an unloaded chunk
					}

					$encouragement = max(
						$grid[$i - 1], $grid[$i + 1], //y -1, +1
						$grid[$i - 8], $grid[$i + 8], //z -1, +1
						$grid[$i - 40], $grid[$i + 40] //x -1, +1
					);

					if($encouragement <= 0){
						continue;
					}
					$block = $world->getBlockAt($targetX, $targetY, $targetZ);

					$maxChance = intdiv($encouragement + 40 + $difficultyChanceIncrease, $ageDivisor);
					if($humid){
						$maxChance = intdiv($maxChance, 2);
					}

					//fire can't spread where it rains, in the block or horizontally next to it
					if($maxChance > 0 && mt_rand(0, $randomBound - 1) <= $maxChance && !self::isNearRain($world, $targetX, $targetY, $targetZ)){
						$new = clone $this;
						$new->age = min(self::MAX_AGE, $this->age + (mt_rand(0, 4) >> 2));
						$this->spreadBlock($block, $new);
						$grid = null;
					}
				}
			}
		}
	}

	/** Grid value of air in a loaded chunk (a fire spread target); other cells hold the flame encouragement. */
	private const GRID_AIR = -1;

	/**
	 * Reads x-2..x+2, y-2..y+5, z-2..z+2 around the fire: GRID_AIR for air in loaded chunks, otherwise the flame
	 * encouragement (0 outside the world and in unloaded chunks). Index: (dx + 2) * 40 + (dz + 2) * 8 + (dy + 2).
	 *
	 * @return int[]
	 * @phpstan-return array<int, int>
	 */
	private static function readSpreadGrid(World $world, int $fireX, int $fireY, int $fireZ) : array{
		$airStateId = self::airStateId();
		$grid = [];
		for($dx = -2; $dx <= 2; ++$dx){
			$x = $fireX + $dx;
			for($dz = -2; $dz <= 2; ++$dz){
				$z = $fireZ + $dz;
				$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
				$base = ($dx + 2) * 40 + ($dz + 2) * 8;
				for($dy = -2; $dy <= 5; ++$dy){
					$y = $fireY + $dy;
					if($chunk === null || !$world->isInWorld($x, $y, $z)){
						$grid[$base + $dy + 2] = 0;
						continue;
					}
					$stateId = $chunk->getBlockStateId($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK);
					$grid[$base + $dy + 2] = $stateId === $airStateId ? self::GRID_AIR :
						(self::$flameEncouragementByStateId[$stateId] ??= RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId)->getFlameEncouragement());
				}
			}
		}
		return $grid;
	}

	private static function airStateId() : int{
		return self::$airStateId ??= VanillaBlocks::AIR()->getStateId();
	}

	/**
	 * Returns false when spreadFire() certainly finds no flame encouragement: no state with a flame encouragement above 0
	 * (as readSpreadGrid() reads it, by state ID) is in the palettes of the subchunks covering the spread grid, x and z
	 * -2..+2, y -2..+5 around the fire. Every cell of the grid is then air or at most 0, so spreadFire() skips every
	 * target: it changes no block, fires no event and draws no number. Unloaded chunks and cells outside the world are 0
	 * in the grid. A palette may still list states that are no longer in the subchunk, which only makes this true more
	 * often (then spreadFire() runs as before).
	 */
	private function mayEncourageFlamesInReach() : bool{
		$world = $this->position->getWorld();
		$fireX = (int) $this->position->x;
		$fireY = (int) $this->position->y;
		$fireZ = (int) $this->position->z;
		$minSubChunkY = max($fireY - 2, $world->getMinY()) >> SubChunk::COORD_BIT_SIZE;
		$maxSubChunkY = min($fireY + 5, $world->getMaxY() - 1) >> SubChunk::COORD_BIT_SIZE;
		$maxChunkX = ($fireX + 2) >> Chunk::COORD_BIT_SIZE;
		$maxChunkZ = ($fireZ + 2) >> Chunk::COORD_BIT_SIZE;
		for($chunkX = ($fireX - 2) >> Chunk::COORD_BIT_SIZE; $chunkX <= $maxChunkX; ++$chunkX){
			for($chunkZ = ($fireZ - 2) >> Chunk::COORD_BIT_SIZE; $chunkZ <= $maxChunkZ; ++$chunkZ){
				$chunk = $world->getChunk($chunkX, $chunkZ);
				if($chunk === null){
					continue; //0 in the spread grid
				}
				for($subChunkY = $minSubChunkY; $subChunkY <= $maxSubChunkY; ++$subChunkY){
					$subChunk = $chunk->getSubChunk($subChunkY);
					$layers = $subChunk->getBlockLayers();
					//only layer 0 holds what Chunk::getBlockStateId() returns
					$palette = count($layers) === 0 ? [$subChunk->getEmptyBlockId()] : $layers[0]->getPalette();
					foreach($palette as $stateId){
						if(self::$encouragesFlamesByStateId[$stateId] ?? self::encouragesFlames($stateId)){
							return true;
						}
					}
				}
			}
		}
		return false;
	}

	/**
	 * Whether the flame encouragement of the state, as readSpreadGrid() reads it, is above 0.
	 */
	private static function encouragesFlames(int $stateId) : bool{
		if(count(self::$encouragesFlamesByStateId) >= self::ENCOURAGES_FLAMES_CACHE_SIZE){
			self::$encouragesFlamesByStateId = [];
		}
		return self::$encouragesFlamesByStateId[$stateId] =
			(self::$flameEncouragementByStateId[$stateId] ?? RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId)->getFlameEncouragement()) > 0;
	}

	/**
	 * Whether it rains on the block or on one of its horizontal neighbours (vanilla FireBlock.isNearRain).
	 */
	private static function isNearRain(World $world, int $x, int $y, int $z) : bool{
		$weather = $world->getWeather();
		if(!$weather->isRaining()){
			return false;
		}
		return $weather->isRainingAt($x, $y, $z) ||
			$weather->isRainingAt($x - 1, $y, $z) ||
			$weather->isRainingAt($x + 1, $y, $z) ||
			$weather->isRainingAt($x, $y, $z - 1) ||
			$weather->isRainingAt($x, $y, $z + 1);
	}

	private function spreadBlock(Block $block, Block $newState) : bool{
		//the new fire is a copy of this one: World::setBlock() gives it the shape that fits its own position
		return BlockEventHelper::spread($block, $newState, $this);
	}
}
