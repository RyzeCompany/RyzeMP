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

namespace pocketmine\world;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\InfestedStone;
use pocketmine\block\Liquid;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\TNT;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\block\utils\SupportType;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Silverfish;
use pocketmine\entity\object\PrimedTNT;
use pocketmine\entity\vehicle\MinecartTNT;
use pocketmine\event\block\BlockExplodeEvent;
use pocketmine\event\entity\EntityDamageByBlockEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\math\VoxelRayTrace;
use pocketmine\player\Player;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Utils;
use pocketmine\world\format\SubChunk;
use pocketmine\world\particle\ExplosionParticle;
use pocketmine\world\sound\ExplodeSound;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\utils\SubChunkExplorerStatus;
use pocketmine\world\vibration\GameEvent;
use function array_fill_keys;
use function ceil;
use function count;
use function floor;
use function max;
use function min;
use function mt_rand;
use function sqrt;

class Explosion{
	public const DEFAULT_FIRE_CHANCE = 1.0 / 3.0;
	/** Blast resistance of water, which waterlogged blocks get from the water in their liquid layer. */
	private const WATER_BLAST_RESISTANCE = 500.0;
	/** Vanilla ServerExplosion.MAX_DROPS_PER_COMBINED_STACK */
	private const MAX_DROP_STACK = 16;
	/** A block hit closer than this (squared) to an end of an exposure ray is only touched there, see getExposure(). */
	private const RAY_END_EPSILON_SQUARED = 1.0e-10;

	private int $rays = 16;
	public World $world;

	/**
	 * @var Block[]
	 * @phpstan-var array<int, Block>
	 */
	public array $affectedBlocks = [];
	public float $stepLen = 0.3;
	/** @var Block[] */
	private array $fireIgnitions = [];

	private SubChunkExplorer $subChunkExplorer;

	/**
	 * @var true[]
	 * @phpstan-var array<int, true>
	 */
	private array $excludedBlockTypeIds = [];

	/** Cap applied to the blast resistance of breakable blocks (vanilla explode max_resistance), or null for none. */
	private ?float $maxBlastResistance = null;

	/**
	 * @param int[] $excludedBlockTypeIds
	 * @phpstan-param array<int, int> $excludedBlockTypeIds
	 */
	public function __construct(
		public Position $source,
		public float $radius,
		private Entity|Block|null $what = null,
		private float $fireChance = 0.0,
		array $excludedBlockTypeIds = []
	){
		if(!$this->source->isValid()){
			throw new \InvalidArgumentException("Position does not have a valid world");
		}
		$this->world = $this->source->getWorld();
		Utils::checkFloatNotInfOrNaN("fireChance", $fireChance);
		if($fireChance < 0.0 || $fireChance > 1.0){
			throw new \InvalidArgumentException("Fire chance must be a number between 0 and 1.");
		}
		if($radius <= 0){
			throw new \InvalidArgumentException("Explosion radius must be greater than 0, got $radius");
		}
		$this->subChunkExplorer = new SubChunkExplorer($this->world);

		$this->excludedBlockTypeIds = array_fill_keys($excludedBlockTypeIds, true);
	}

	/**
	 * Calculates which blocks will be destroyed by this explosion. If explodeB() is called without calling this, no blocks
	 * will be destroyed.
	 */
	public function explodeA() : bool{
		if($this->radius < 0.1){
			return false;
		}

		$blockFactory = RuntimeBlockStateRegistry::getInstance();

		$mRays = $this->rays - 1;
		$incendiary = $this->fireChance > 0;
		for($i = 0; $i < $this->rays; ++$i){
			for($j = 0; $j < $this->rays; ++$j){
				for($k = 0; $k < $this->rays; ++$k){
					if($i === 0 || $i === $mRays || $j === 0 || $j === $mRays || $k === 0 || $k === $mRays){
						//this could be written as new Vector3(...)->normalize()->multiply(stepLen), but we're avoiding Vector3 for performance here
						[$shiftX, $shiftY, $shiftZ] = [$i / $mRays * 2 - 1, $j / $mRays * 2 - 1, $k / $mRays * 2 - 1];
						$len = sqrt($shiftX ** 2 + $shiftY ** 2 + $shiftZ ** 2);
						[$shiftX, $shiftY, $shiftZ] = [($shiftX / $len) * $this->stepLen, ($shiftY / $len) * $this->stepLen, ($shiftZ / $len) * $this->stepLen];
						$pointerX = $this->source->x;
						$pointerY = $this->source->y;
						$pointerZ = $this->source->z;

						for($blastForce = $this->radius * (mt_rand(700, 1300) / 1000); $blastForce > 0; $blastForce -= $this->stepLen * 0.75){
							$x = (int) $pointerX;
							$y = (int) $pointerY;
							$z = (int) $pointerZ;
							$vBlockX = $pointerX >= $x ? $x : $x - 1;
							$vBlockY = $pointerY >= $y ? $y : $y - 1;
							$vBlockZ = $pointerZ >= $z ? $z : $z - 1;

							$pointerX += $shiftX;
							$pointerY += $shiftY;
							$pointerZ += $shiftZ;

							if($this->subChunkExplorer->moveTo($vBlockX, $vBlockY, $vBlockZ) === SubChunkExplorerStatus::INVALID){
								continue;
							}
							$subChunk = $this->subChunkExplorer->currentSubChunk;
							if($subChunk === null){
								throw new AssumptionFailedError("SubChunkExplorer subchunk should not be null here");
							}

							$state = $subChunk->getBlockStateId($vBlockX & SubChunk::COORD_MASK, $vBlockY & SubChunk::COORD_MASK, $vBlockZ & SubChunk::COORD_MASK);
							if(count($this->excludedBlockTypeIds) > 0 && isset($this->excludedBlockTypeIds[$blockFactory->fromStateId($state)->getTypeId()])){
								continue;
							}

							$blastResistance = $blockFactory->blastResistance[$state] ?? 0;
							if($this->maxBlastResistance !== null && $blastResistance > $this->maxBlastResistance && self::isResistanceCappable($blockFactory->fromStateId($state))){
								$blastResistance = $this->maxBlastResistance;
							}
							if(
								$blastResistance < self::WATER_BLAST_RESISTANCE &&
								LiquidLayer::isWaterInSubChunk($subChunk, $vBlockX & SubChunk::COORD_MASK, $vBlockY & SubChunk::COORD_MASK, $vBlockZ & SubChunk::COORD_MASK) &&
								LiquidLayer::canContainWater($state)
							){
								//a waterlogged block resists like the water it holds (vanilla takes the higher of the block's and the fluid's)
								$blastResistance = self::WATER_BLAST_RESISTANCE;
							}
							if($blastResistance >= 0){
								$blastForce -= ($blastResistance / 5 + 0.3) * $this->stepLen;
								if($blastForce > 0){
									if(!isset($this->affectedBlocks[World::blockHash($vBlockX, $vBlockY, $vBlockZ)])){
										$_block = $this->world->getBlockAt($vBlockX, $vBlockY, $vBlockZ, true, false);
										foreach($_block->getAffectedBlocks() as $_affectedBlock){
											$_affectedBlockPos = $_affectedBlock->getPosition();
											$posHash = World::blockHash($_affectedBlockPos->x, $_affectedBlockPos->y, $_affectedBlockPos->z);
											$this->affectedBlocks[$posHash] = $_affectedBlock;

											if($incendiary && Utils::getRandomFloat() <= $this->fireChance){
												$this->fireIgnitions[$posHash] = $_affectedBlock;
											}
										}
									}
								}
							}
						}
					}
				}
			}
		}

		return true;
	}

	/**
	 * Executes the explosion's effects on the world. This includes destroying blocks (if any), harming and knocking back entities,
	 * and creating sounds and particles.
	 */
	public function explodeB() : bool{
		$source = new Vector3($this->source->x, $this->source->y, $this->source->z);
		$tileDrops = $this->world->getGameRule(GameRules::DO_TILE_DROPS) !== false;
		if(!$tileDrops){
			$yield = 0;
		}elseif(($this->what instanceof PrimedTNT || $this->what instanceof MinecartTNT) && $this->world->getGameRule(GameRules::TNT_EXPLOSION_DROP_DECAY) === false){
			//Bedrock: every block broken by TNT drops, unless tntexplosiondropdecay is on
			$yield = 100;
		}else{
			$yield = min(100, (1 / $this->radius) * 100);
		}
		if($this->what instanceof Entity){
			if(($this->what instanceof PrimedTNT || $this->what instanceof MinecartTNT) && $this->world->getGameRule(GameRules::TNT_EXPLODES) === false){
				return false;
			}
			if(!GameRules::allowsExplosionGriefing($this->world, $this->what)){
				//mobgriefing: creepers, withers, fireballs... harm entities but leave blocks alone
				$this->affectedBlocks = [];
				$this->fireIgnitions = [];
			}
			$ev = new EntityExplodeEvent($this->what, $this->source, $this->affectedBlocks, $yield, $this->fireIgnitions);

			$ev->call();
			if($ev->isCancelled()){
				return false;
			}

			$yield = $ev->getYield();
			$this->affectedBlocks = $ev->getBlockList();
			$this->fireIgnitions = $ev->getIgnitions();
		}elseif($this->what instanceof Block){
			$ev = new BlockExplodeEvent(
				$this->what,
				$this->source,
				$this->affectedBlocks,
				$yield,
				$this->fireIgnitions,
			);

			$ev->call();
			if($ev->isCancelled()){
				return false;
			}else{
				$yield = $ev->getYield();
				$this->affectedBlocks = $ev->getAffectedBlocks();
				$this->fireIgnitions = $ev->getIgnitions();
			}
		}

		$explosionSize = $this->radius * 2;
		$minX = (int) floor($this->source->x - $explosionSize - 1);
		$maxX = (int) ceil($this->source->x + $explosionSize + 1);
		$minY = (int) floor($this->source->y - $explosionSize - 1);
		$maxY = (int) ceil($this->source->y + $explosionSize + 1);
		$minZ = (int) floor($this->source->z - $explosionSize - 1);
		$maxZ = (int) ceil($this->source->z + $explosionSize + 1);

		$explosionBB = new AxisAlignedBB($minX, $minY, $minZ, $maxX, $maxY, $maxZ);

		/** @var Entity[] $list */
		$list = $this->world->getNearbyEntities($explosionBB, $this->what instanceof Entity ? $this->what : null);
		$difficulty = $this->what instanceof Mob ? $this->world->getDifficulty() : World::DIFFICULTY_NORMAL;
		foreach($list as $entity){
			$entityPos = $entity->getPosition();
			$distance = $entityPos->distance($this->source) / $explosionSize;

			if($distance <= 1){
				//vanilla pushes away from the explosion towards the eyes (TNT: its position), so an entity standing at
				//the centre is thrown upwards
				$motion = ($entity instanceof PrimedTNT ? $entityPos : $entity->getEyePos())->subtractVector($this->source)->normalize();
				$exposure = $this->getExposure($this->source, $entity);

				$impact = (1 - $distance) * $exposure;

				//vanilla ExplosionDamageCalculator, checked on BDS 1.26.52 (a creeper at point blank deals 43 on Normal)
				$damage = (($impact * $impact + $impact) / 2) * 7 * $explosionSize + 1;
				if($entity instanceof Player && $difficulty !== World::DIFFICULTY_NORMAL){
					//like a mob's melee attack: BDS scales creeper explosions but not TNT
					$damage = Mob::scaleDamageForDifficulty($damage, $difficulty);
				}

				if($damage > 0){
					if($this->what instanceof Entity){
						$ev = new EntityDamageByEntityEvent($this->what, $entity, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION, $damage);
					}elseif($this->what instanceof Block){
						$ev = new EntityDamageByBlockEvent($this->what, $entity, EntityDamageEvent::CAUSE_BLOCK_EXPLOSION, $damage);
					}else{
						$ev = new EntityDamageEvent($entity, EntityDamageEvent::CAUSE_BLOCK_EXPLOSION, $damage);
					}

					$entity->attack($ev);
				}
				if($entity instanceof Living && ($blastProtection = $entity->getHighestArmorEnchantmentLevel(VanillaEnchantments::BLAST_PROTECTION())) > 0){
					//Blast Protection dampens the push by 15% per level
					$impact *= max(0.0, 1 - 0.15 * $blastProtection);
				}
				if($entity->setMotion($entity->getMotion()->addVector($motion->multiply($impact))) && $entity instanceof Mob && $impact > 0){
					$entity->carryPush();
				}
			}
		}

		$air = VanillaItems::AIR();
		$airBlock = VanillaBlocks::AIR();
		$fireBlock = VanillaBlocks::FIRE();
		/**
		 * @var Vector3[]|Item[][] $dropStacks
		 * @phpstan-var list<array{Vector3, Item}> $dropStacks
		 */
		$dropStacks = [];

		foreach($this->affectedBlocks as $hash => $block){
			$pos = $block->getPosition();
			if($block instanceof TNT){
				$block->ignite(mt_rand(10, 40)); //vanilla tnt.json from_explosion: 0.5-2 s
			}else{
				if($yield >= 100 || mt_rand(0, 99) < $yield){
					$drops = $block->getBreakInfo()->isExplosionHarvestable() ?
						$block->getDropsForCompatibleTool($air) :
						$block->getDrops($air);
					foreach($drops as $drop){
						self::addDropStack($dropStacks, $pos, $drop);
					}
				}
				if(($t = $this->world->getTileAt($pos->x, $pos->y, $pos->z)) !== null){
					$t->onBlockDestroyed(); //needed to create drops for inventories
				}
				$targetBlock =
					isset($this->fireIgnitions[$hash]) &&
					$block->getSide(Facing::DOWN)->getSupportType(Facing::UP) === SupportType::FULL ?
						$fireBlock :
						$airBlock;

				$this->world->setBlockAt($pos->x, $pos->y, $pos->z, $targetBlock);
				if($block instanceof InfestedStone && $tileDrops){
					//the silverfish hiding in it gets out (vanilla InfestedBlock.spawnAfterBreak)
					Silverfish::spawnFromBlock($this->world, $pos);
				}
			}
		}

		foreach($dropStacks as [$dropPos, $drop]){
			$this->world->dropItem($dropPos->add(0.5, 0.5, 0.5), $drop);
		}

		$this->world->addParticle($source, new ExplosionParticle($this->radius));
		$this->world->addSound($source, new ExplodeSound());
		$this->world->addGameEvent($source, GameEvent::EXPLODE, $this->what instanceof Entity ? $this->what : null);

		return true;
	}

	/**
	 * Adds a block's drop to the explosion's drops, topping up an earlier stack of the same item first (up to 16 per
	 * stack, spawned where its first block was), like vanilla ServerExplosion.addOrAppendStack. A TNT blast that drops
	 * every block then spawns a handful of item entities instead of one per block.
	 *
	 * @phpstan-param list<array{Vector3, Item}> $stacks
	 */
	private static function addDropStack(array &$stacks, Vector3 $pos, Item $drop) : void{
		foreach($stacks as [, $stack]){
			if($stack->canStackWith($drop)){
				$room = min(self::MAX_DROP_STACK, $stack->getMaxStackSize()) - $stack->getCount();
				if($room > 0){
					$moved = min($room, $drop->getCount());
					$stack->setCount($stack->getCount() + $moved);
					if($moved === $drop->getCount()){
						return;
					}
					$drop->setCount($drop->getCount() - $moved);
				}
			}
		}
		$stacks[] = [$pos, $drop];
	}

	/**
	 * Returns the explosion exposure of an entity, used to calculate explosion impact.
	 */
	private function getExposure(Vector3 $origin, Entity $entity) : float{
		$bb = $entity->getBoundingBox();

		$diff = (new Vector3($bb->getXLength(), $bb->getYLength(), $bb->getZLength()))->multiply(2)->add(1, 1, 1);
		$step = new Vector3(1.0 / $diff->x, 1.0 / $diff->y, 1.0 / $diff->z);

		$xOffset = (1.0 - (floor($diff->x) / $diff->x)) / 2.0;
		$zOffset = (1.0 - (floor($diff->z) / $diff->z)) / 2.0;

		$checks = 0.0;
		$hits = 0.0;

		for($x = 0.0; $x <= 1.0; $x += $step->x){
			for($y = 0.0; $y <= 1.0; $y += $step->y){
				for($z = 0.0; $z <= 1.0; $z += $step->z){
					$point = new Vector3(
						self::lerp($x, $bb->minX, $bb->maxX) + $xOffset,
						self::lerp($y, $bb->minY, $bb->maxY),
						self::lerp($z, $bb->minZ, $bb->maxZ) + $zOffset
					);

					$intercepted = false;

					//traced from the entity to the explosion like vanilla. The points on the entity's feet and the centre of
					//a creeper's blast lie on the top face of the floor: a block the ray only touches at one of its ends
					//doesn't hide it (a quarter of a standing player hidden by the floor, half the damage of a creeper)
					foreach(VoxelRayTrace::betweenPoints($point, $origin) as $pos){
						$block = $this->world->getBlock($pos);
						$hit = $block->calculateIntercept($point, $origin);
						if($hit !== null){
							$at = $hit->getHitVector();
							if($at->distanceSquared($point) > self::RAY_END_EPSILON_SQUARED && $at->distanceSquared($origin) > self::RAY_END_EPSILON_SQUARED){
								$intercepted = true;
								break;
							}
						}
					}

					if(!$intercepted){
						$hits++;
					}
					$checks++;
				}
			}
		}

		return $checks > 0.0 ? $hits / $checks : 0.0;
	}

	/**
	 * Sets a chance between 0 and 1 of creating a fire.
	 * For example, if the chance is 1/3, then that amount of affected blocks will be ignited.
	 *
	 * @param float $fireChance 0 ... 1
	 */
	public function setFireChance(float $fireChance) : void{
		Utils::checkFloatNotInfOrNaN("fireChance", $fireChance);
		if($fireChance < 0.0 || $fireChance > 1.0){
			throw new \InvalidArgumentException("Fire chance must be a number between 0 and 1.");
		}
		$this->fireChance = $fireChance;
	}

	/**
	 * Caps the blast resistance of the breakable blocks the explosion meets (vanilla explode max_resistance, used by
	 * the blue wither skull to break obsidian). Unbreakable and wither-immune blocks keep their resistance.
	 * Null removes the cap.
	 */
	public function setMaxBlastResistance(?float $maxBlastResistance) : void{
		if($maxBlastResistance !== null){
			Utils::checkFloatNotInfOrNaN("maxBlastResistance", $maxBlastResistance);
		}
		$this->maxBlastResistance = $maxBlastResistance;
	}

	public function getMaxBlastResistance() : ?float{
		return $this->maxBlastResistance;
	}

	private static function isResistanceCappable(Block $block) : bool{
		return $block->getBreakInfo()->isBreakable() && !$block instanceof Liquid && $block->getTypeId() !== BlockTypeIds::REINFORCED_DEEPSLATE;
	}

	private static function lerp(float $scale, float $a, float $b) : float{
		return $a + $scale * ($b - $a);
	}
}
