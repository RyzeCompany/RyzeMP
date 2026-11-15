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

namespace pocketmine\world\raid;

use pocketmine\block\Leaves;
use pocketmine\block\Scaffolding;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\EntitySpawnRegistry;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Evoker;
use pocketmine\entity\monster\Pillager;
use pocketmine\entity\monster\Raider;
use pocketmine\entity\monster\Ravager;
use pocketmine\entity\monster\Vindicator;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\SpawnReason;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use pocketmine\world\format\Chunk;
use pocketmine\world\sound\BellRingSound;
use pocketmine\world\sound\RaidHornSound;
use pocketmine\world\World;
use function abs;
use function cos;
use function count;
use function floor;
use function is_array;
use function is_bool;
use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function mt_rand;
use function sin;
use function sqrt;
use const M_PI;

/**
 * A raid on a village (Bedrock rules, see https://minecraft.wiki/w/Raid):
 *
 * - it starts when the Raid Omen of a player in the village runs out; the red "Raid" bar charges for 15 seconds while
 *   the village bells ring, then the first wave comes, and 15 seconds pass between waves;
 * - each wave ({@link RaidWaves}) spawns at once on a free spot at the edge of the village (up to 64 blocks from its
 *   centre horizontally, 16 vertically) with the raid horn, led by a captain wearing the ominous banner (its first
 *   pillager or vindicator); illagers, witches and ravagers already within 96 blocks
 *   join it; raiders walk to the village centre and attack villagers, players and iron golems;
 * - the bar shows the raiders left in the wave (and their number once fewer than 3 remain), for players within
 *   64 blocks horizontally and 44 vertically of the centre;
 * - when the last wave is defeated, players who killed a raider get Hero of the Village (40 minutes) and villagers
 *   celebrate; when the village stops being a village (beds gone, or all its villagers dead) the raiders win and
 *   celebrate; after 48000 ticks (or when the difficulty is set to peaceful) the raid expires.
 *
 * Waves only progress while a player is near the village (the raiders would be in unloaded chunks otherwise); the
 * expiry time keeps running.
 */
final class Raid{
	/**
	 * Bar titles, translated by each client, as BDS 1.26.60 sends them: "raid.name" while preparing and fighting,
	 * "%raid.name - %raid.progress 2" with fewer than 3 raiders left, "%raid.name - %raid.victory" / "- %raid.defeat".
	 * The expired title stays in English: BDS sent none in a capture (peaceful only freezes its bar).
	 */
	private const TITLE = "raid.name";
	private const TITLE_PREFIX = "%raid.name";
	public const PREPARE_TICKS = 300;
	public const MAX_TICKS = 48000;
	public const END_TICKS = 600;
	public const HORN_INTERVAL = 600;
	public const HERO_DURATION = 48000;
	public const BAR_RANGE_HORIZONTAL = 64.0;
	public const BAR_RANGE_VERTICAL = 44.0;
	public const SPAWN_RANGE_HORIZONTAL = 64;
	public const SPAWN_RANGE_VERTICAL = 16;
	public const JOIN_RANGE = 96.0;
	public const LEAVE_RANGE = 112.0;
	private const SPAWN_ATTEMPTS = 40;
	private const VILLAGE_CHECK_INTERVAL = 40;
	private const RAIDER_COUNT_INTERVAL = 20;

	private RaidStatus $status = RaidStatus::ONGOING;
	private int $wave = 0;
	private int $totalWaves;
	/** Ticks until the next wave comes, or -1 while a wave is being fought. */
	private int $cooldown = self::PREPARE_TICKS;
	private int $ticks = 0;
	private int $endTicks = self::END_TICKS;
	private int $waveSize = 0;
	private int $raidersAlive = 0;
	private int $hornTicks = 0;
	/** Riders put on their ravager by {@link Raid::tryMountRider()}; the others raid on foot. */
	private int $mountedRiders = 0;
	/** Ticks to wait after loading a saved raid, so that its raiders are back before they are counted. */
	private int $graceTicks = 0;
	/**
	 * UUIDs of the players who killed a raider.
	 * @var true[]
	 * @phpstan-var array<string, true>
	 */
	private array $heroes = [];
	/** @phpstan-var list<Vector3> */
	private array $beds = [];
	private bool $hadVillagers;
	private RaidBossBar $bar;

	/**
	 * @param int $omenLevel level of the Raid Omen that started it (1-5); Bedrock doesn't change the waves with it
	 */
	public function __construct(
		private int $id,
		private Vector3 $center,
		private int $omenLevel,
		int $difficulty,
		bool $hadVillagers
	){
		$this->totalWaves = RaidWaves::getWaveCount($difficulty);
		$this->hadVillagers = $hadVillagers;
		$this->bar = new RaidBossBar();
	}

	public function getId() : int{ return $this->id; }

	public function getCenter() : Vector3{ return $this->center; }

	public function getOmenLevel() : int{ return $this->omenLevel; }

	public function getStatus() : RaidStatus{ return $this->status; }

	public function isOngoing() : bool{ return $this->status === RaidStatus::ONGOING; }

	/**
	 * Number of waves spawned so far.
	 */
	public function getWave() : int{ return $this->wave; }

	public function getTotalWaves() : int{ return $this->totalWaves; }

	/**
	 * Ticks before the next wave, or -1 while a wave is being fought.
	 */
	public function getCooldown() : int{ return $this->cooldown; }

	/**
	 * Changes the wait before the next wave (only between waves).
	 */
	public function setCooldown(int $ticks) : void{
		if($this->cooldown > 0){
			$this->cooldown = max(1, $ticks);
		}
	}

	public function getTicks() : int{ return $this->ticks; }

	public function getRaidersAlive() : int{ return $this->raidersAlive; }

	public function getMountedRiders() : int{ return $this->mountedRiders; }

	public function getBossBar() : RaidBossBar{ return $this->bar; }

	/**
	 * @return Vector3[]
	 * @phpstan-return list<Vector3>
	 */
	public function getBeds() : array{ return $this->beds; }

	/**
	 * @return string[] UUIDs of the players who killed a raider
	 */
	public function getHeroes() : array{
		$result = [];
		foreach(Utils::stringifyKeys($this->heroes) as $uuid => $_){
			$result[] = $uuid;
		}
		return $result;
	}

	public function addHero(Player $player) : void{
		$this->heroes[$player->getUniqueId()->toString()] = true;
	}

	/**
	 * @phpstan-param list<Vector3> $beds
	 */
	public function setBeds(array $beds) : void{
		$this->beds = $beds;
	}

	/**
	 * Whether the position is close enough to the centre to see the raid bar.
	 */
	public function isInBarRange(Vector3 $pos) : bool{
		return abs($pos->x - $this->center->x) <= self::BAR_RANGE_HORIZONTAL && abs($pos->z - $this->center->z) <= self::BAR_RANGE_HORIZONTAL && abs($pos->y - $this->center->y) <= self::BAR_RANGE_VERTICAL;
	}

	/**
	 * Called every tick by the {@link RaidManager}. Returns false when the raid is over and can be forgotten.
	 */
	public function tick(World $world) : bool{
		++$this->ticks;
		$players = [];
		foreach($world->getPlayers() as $player){
			if($player->isConnected() && $this->isInBarRange($player->getPosition())){
				$players[] = $player;
			}
		}
		$this->bar->setViewers($players);

		if($this->status !== RaidStatus::ONGOING){
			if(--$this->endTicks <= 0){
				$this->finish($world);
				return false;
			}
			if($this->status === RaidStatus::LOSS || $this->status === RaidStatus::VICTORY){
				$this->tickCelebration($world);
			}
			return true;
		}

		if($this->ticks >= self::MAX_TICKS || $world->getDifficulty() === World::DIFFICULTY_PEACEFUL){
			$this->end($world, RaidStatus::EXPIRED);
			return true;
		}
		if(count($players) === 0){
			return true; //nobody around: wait
		}
		if($this->graceTicks > 0){
			--$this->graceTicks;
			return true;
		}

		if($this->ticks % self::RAIDER_COUNT_INTERVAL === 0 || $this->cooldown === -1 && $this->raidersAlive === 0){
			$this->raidersAlive = $this->countRaiders($world, true);
		}
		if($this->ticks % self::VILLAGE_CHECK_INTERVAL === 0 && !$this->checkVillage($world)){
			$this->end($world, RaidStatus::LOSS);
			return true;
		}

		if($this->cooldown > 0){
			if($this->cooldown === self::PREPARE_TICKS){
				foreach($this->bells($world) as $bell){
					$world->addSound($bell->add(0.5, 0.5, 0.5), new BellRingSound());
				}
			}
			--$this->cooldown;
			$this->bar->setTitle(self::TITLE);
			$this->bar->setPercent((self::PREPARE_TICKS - $this->cooldown) / self::PREPARE_TICKS);
			if($this->cooldown === 0){
				$this->spawnNextWave($world, $players);
			}
			return true;
		}

		//a wave is being fought
		if($this->raidersAlive <= 0){
			if($this->wave >= $this->totalWaves){
				$this->end($world, RaidStatus::VICTORY);
			}else{
				$this->cooldown = self::PREPARE_TICKS;
			}
			return true;
		}
		$this->bar->setTitle($this->raidersAlive < 3 ? self::TITLE_PREFIX . " - %raid.progress " . $this->raidersAlive : self::TITLE);
		$this->bar->setPercent($this->waveSize > 0 ? min(1.0, $this->raidersAlive / $this->waveSize) : 0.0);
		if(--$this->hornTicks <= 0){
			$this->hornTicks = self::HORN_INTERVAL;
			$this->playHorn($world, $players);
		}
		return true;
	}

	/**
	 * Called when a raider of this raid dies.
	 */
	public function onRaiderDeath(?Player $killer) : void{
		if($killer !== null){
			$this->addHero($killer);
		}
		$this->raidersAlive = max(0, $this->raidersAlive - 1);
	}

	/**
	 * Counts the living raiders of this raid in the world; with $leaveFar, raiders too far from the village (or in
	 * another world) leave the raid.
	 */
	public function countRaiders(World $world, bool $leaveFar = false) : int{
		$count = 0;
		foreach($world->getEntities() as $entity){
			if($entity instanceof Raider && $entity->getRaidId() === $this->id && $entity->isAlive() && !$entity->isClosed()){
				if($leaveFar && $entity->getPosition()->distanceSquared($this->center) > self::LEAVE_RANGE ** 2){
					$entity->leaveRaid();
					continue;
				}
				++$count;
			}
		}
		return $count;
	}

	/**
	 * @return Vector3[]
	 * @phpstan-return list<Vector3>
	 */
	private function bells(World $world) : array{
		$village = $this->findVillage($world);
		return $village !== null ? $village->getBells() : [];
	}

	private function findVillage(World $world) : ?Village{
		$best = null;
		foreach(VillageScanner::findVillages($world, $this->center) as $village){
			if($village->contains($this->center) && ($best === null || $village->getCenter()->distanceSquared($this->center) < $best->getCenter()->distanceSquared($this->center))){
				$best = $village;
			}
		}
		return $best;
	}

	/**
	 * Returns whether the village still stands (and remembers its beds for the villagers to hide in).
	 */
	public function checkVillage(World $world) : bool{
		if(!$world->isChunkLoaded(((int) floor($this->center->x)) >> Chunk::COORD_BIT_SIZE, ((int) floor($this->center->z)) >> Chunk::COORD_BIT_SIZE)){
			return true; //can't tell
		}
		$village = $this->findVillage($world);
		if($village === null || ($this->hadVillagers && $village->getVillagerCount() === 0)){
			return false;
		}
		$this->beds = $village->getBeds();
		return true;
	}

	/**
	 * Spawns the next wave. If no spawn spot is found, the wave is skipped (Bedrock).
	 *
	 * @param Player[] $players players near the village, who hear the horn
	 */
	public function spawnNextWave(World $world, array $players) : void{
		++$this->wave;
		$this->cooldown = -1;
		$spot = $this->findSpawnSpot($world);
		if($spot !== null){
			$captain = null;
			foreach(RaidWaves::getWave($this->wave) as [$type, $riderType, $count]){
				for($i = 0; $i < $count; ++$i){
					$mob = $this->spawnRaider($world, $type, $spot);
					if($captain === null && self::canLeadWave($mob)){
						$captain = $mob;
					}
					if($mob !== null && $riderType !== null){
						$rider = $this->spawnRaider($world, $riderType, $spot);
						if($captain === null && self::canLeadWave($rider)){
							$captain = $rider;
						}
						if($rider !== null && $mob instanceof Ravager && self::tryMountRider($mob, $rider)){
							++$this->mountedRiders;
						}
					}
				}
			}
			//each wave has a captain wearing the ominous banner (Bedrock spawn_as_illager_captain), the first pillager
			//or vindicator spawned (Java also makes the first raider of the wave that can lead it its leader)
			if($captain instanceof Raider){
				$captain->setPatrolLeader(true);
			}
			$this->joinNearbyRaiders($world);
			$this->hornTicks = self::HORN_INTERVAL;
			$this->playHorn($world, $players, $spot);
		}
		$this->raidersAlive = $this->countRaiders($world);
		$this->waveSize = $this->raidersAlive;
	}

	/**
	 * Hook for ravager riders (a pillager or an evoker on some ravagers of waves 5 and 7). Returns whether the rider
	 * was mounted; if not, it raids on foot next to the ravager.
	 */
	public static function tryMountRider(Ravager $ravager, Living $rider) : bool{
		return $rider->mount($ravager);
	}

	/**
	 * Whether the raider can be the captain of its wave: pillagers and vindicators have the illager_squad_captain
	 * component group in Bedrock (evokers, witches and ravagers don't).
	 */
	private static function canLeadWave(?Mob $mob) : bool{
		return $mob instanceof Pillager || $mob instanceof Vindicator;
	}

	private function spawnRaider(World $world, string $type, Vector3 $spot) : ?Mob{
		$location = new Location($spot->x + mt_rand(-20, 20) / 10, $spot->y, $spot->z + mt_rand(-20, 20) / 10, $world, mt_rand(0, 359), 0);
		$entity = EntitySpawnRegistry::getInstance()->create($type, $location);
		if(!$entity instanceof Raider || !$entity instanceof Mob){
			$entity?->close();
			return null;
		}
		if(!$entity instanceof Evoker){
			//they despawn normally once the raid is over (Bedrock raid_persistence), but evokers are always persistent
			$entity->setPersistent(false);
		}
		$entity->joinRaid($this->id, $this->wave, $this->center);
		$entity->setSpawnReason(SpawnReason::RAID);
		$entity->spawnToAll();
		return $entity;
	}

	/**
	 * Illagers, witches and ravagers near the village that are not in a raid join this one (Bedrock: when a wave
	 * spawns).
	 */
	private function joinNearbyRaiders(World $world) : void{
		$r = self::JOIN_RANGE;
		$c = $this->center;
		foreach($world->getNearbyEntities(new AxisAlignedBB($c->x - $r, $c->y - $r, $c->z - $r, $c->x + $r, $c->y + $r, $c->z + $r)) as $entity){
			if($entity instanceof Raider && $entity->getRaidId() === null && $entity->isAlive() && $entity->getPosition()->distanceSquared($c) <= $r * $r){
				$entity->joinRaid($this->id, $this->wave, $this->center);
			}
		}
	}

	/**
	 * Finds where a wave spawns: a free spot on any block but leaves or scaffolding, at the edge of the village if
	 * possible, at most 64 blocks away horizontally and 16 vertically.
	 */
	public function findSpawnSpot(World $world) : ?Vector3{
		$cx = $this->center->x;
		$cy = (int) floor($this->center->y);
		$cz = $this->center->z;
		for($attempt = 0; $attempt < self::SPAWN_ATTEMPTS; ++$attempt){
			//start far away and come closer as attempts fail
			$maxDist = self::SPAWN_RANGE_HORIZONTAL - (int) floor($attempt * (self::SPAWN_RANGE_HORIZONTAL - 8) / self::SPAWN_ATTEMPTS);
			$dist = mt_rand(max(8, $maxDist - 16), $maxDist);
			$angle = mt_rand(0, 359) / 180 * M_PI;
			$x = (int) floor($cx + cos($angle) * $dist);
			$z = (int) floor($cz + sin($angle) * $dist);
			$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
			if($chunk === null){
				continue;
			}
			for($dy = self::SPAWN_RANGE_VERTICAL; $dy >= -self::SPAWN_RANGE_VERTICAL; --$dy){
				$y = $cy + $dy;
				if($y <= $world->getMinY() || $y >= $world->getMaxY() - 2){
					continue;
				}
				if(!PathBlockTypes::isStandable($world, $x, $y, $z, 1, false)){
					continue;
				}
				$below = $world->getBlockAt($x, $y - 1, $z);
				if($below instanceof Leaves || $below instanceof Scaffolding){
					continue;
				}
				return new Vector3($x + 0.5, $y, $z + 0.5);
			}
		}
		return null;
	}

	/**
	 * Plays the raid horn to each player from the direction of the raiders (13 blocks away, like Java).
	 *
	 * @param Player[] $players
	 */
	private function playHorn(World $world, array $players, ?Vector3 $from = null) : void{
		if($from === null){
			foreach($world->getEntities() as $entity){
				if($entity instanceof Raider && $entity->getRaidId() === $this->id && $entity->isAlive()){
					$from = $entity->getPosition();
					break;
				}
			}
		}
		$from ??= $this->center;
		foreach($players as $player){
			$pos = $player->getPosition();
			$dx = $from->x - $pos->x;
			$dz = $from->z - $pos->z;
			$len = sqrt($dx * $dx + $dz * $dz);
			$soundPos = $len > 13 ? $pos->add($dx / $len * 13, 0, $dz / $len * 13) : $from;
			$world->addSound($soundPos, new RaidHornSound(), [$player]);
		}
	}

	/**
	 * Ends the raid: shows the result on the bar, rewards the heroes or makes the raiders celebrate.
	 */
	public function end(World $world, RaidStatus $status) : void{
		if($this->status !== RaidStatus::ONGOING){
			return;
		}
		$this->status = $status;
		$this->endTicks = self::END_TICKS;
		switch($status){
			case RaidStatus::VICTORY:
				$this->bar->setTitle(self::TITLE_PREFIX . " - %raid.victory");
				$this->bar->setPercent(0.0);
				foreach($world->getPlayers() as $player){
					if(isset($this->heroes[$player->getUniqueId()->toString()]) && $player->isAlive()){
						$player->getEffects()->add(new EffectInstance(VanillaEffects::VILLAGE_HERO(), self::HERO_DURATION, 0));
					}
				}
				foreach($this->villagers($world) as $villager){
					$villager->setCelebrating(true);
				}
				break;
			case RaidStatus::LOSS:
				$this->bar->setTitle(self::TITLE_PREFIX . " - %raid.defeat");
				$this->bar->setPercent(1.0);
				foreach($this->raiders($world) as $raider){
					$raider->setCelebrating(true);
				}
				break;
			case RaidStatus::EXPIRED:
				$this->bar->setTitle("Raid Expired");
				$this->endTicks = 100;
				foreach($this->raiders($world) as $raider){
					$raider->leaveRaid();
				}
				break;
			default:
				break;
		}
	}

	/**
	 * Celebration (vanilla "celebrate" component: sounds and jumps every few seconds).
	 */
	private function tickCelebration(World $world) : void{
		if($this->endTicks % 20 !== 0){
			return;
		}
		$celebrating = $this->status === RaidStatus::LOSS ? $this->raiders($world) : $this->villagers($world);
		foreach($celebrating as $mob){
			if($mob instanceof Living && mt_rand(0, 3) === 0 && $mob->isOnGround()){
				$mob->jump();
			}
			if($mob instanceof Raider && mt_rand(0, 4) === 0){
				$mob->setCelebrating(false);
				$mob->setCelebrating(true); //plays the celebration sound again
			}
		}
	}

	/**
	 * Called when the result has been shown long enough, or when the raid is dropped.
	 */
	public function finish(World $world) : void{
		$this->bar->hideAll();
		foreach($this->raiders($world) as $raider){
			$raider->leaveRaid();
		}
		foreach($this->villagers($world) as $villager){
			$villager->setCelebrating(false);
		}
	}

	/**
	 * @return Raider[]
	 * @phpstan-return list<Raider&Mob>
	 */
	private function raiders(World $world) : array{
		$result = [];
		foreach($world->getEntities() as $entity){
			if($entity instanceof Raider && $entity instanceof Mob && $entity->getRaidId() === $this->id){
				$result[] = $entity;
			}
		}
		return $result;
	}

	/**
	 * @return VillagerV2[]
	 * @phpstan-return list<VillagerV2>
	 */
	private function villagers(World $world) : array{
		$result = [];
		$r = self::BAR_RANGE_HORIZONTAL;
		$c = $this->center;
		foreach($world->getNearbyEntities(new AxisAlignedBB($c->x - $r, $c->y - 32, $c->z - $r, $c->x + $r, $c->y + 32, $c->z + $r)) as $entity){
			if($entity instanceof VillagerV2 && $entity->isAlive()){
				$result[] = $entity;
			}
		}
		return $result;
	}

	/**
	 * @return mixed[]
	 * @phpstan-return array<string, mixed>
	 */
	public function toArray() : array{
		$beds = [];
		foreach($this->beds as $bed){
			$beds[] = [(int) $bed->x, (int) $bed->y, (int) $bed->z];
		}
		return [
			"id" => $this->id,
			"center" => [$this->center->x, $this->center->y, $this->center->z],
			"omenLevel" => $this->omenLevel,
			"status" => $this->status->value,
			"wave" => $this->wave,
			"totalWaves" => $this->totalWaves,
			"cooldown" => $this->cooldown,
			"ticks" => $this->ticks,
			"endTicks" => $this->endTicks,
			"waveSize" => $this->waveSize,
			"hadVillagers" => $this->hadVillagers,
			"heroes" => $this->getHeroes(),
			"beds" => $beds
		];
	}

	/**
	 * @param mixed[] $data
	 * @phpstan-param array<string, mixed> $data
	 */
	public static function fromArray(array $data) : ?self{
		$center = $data["center"] ?? null;
		$id = $data["id"] ?? null;
		$totalWaves = $data["totalWaves"] ?? null;
		if(!is_int($id) || !is_int($totalWaves) || !is_array($center) || count($center) !== 3 || !is_numeric($center[0] ?? null) || !is_numeric($center[1] ?? null) || !is_numeric($center[2] ?? null)){
			return null;
		}
		$status = is_string($data["status"] ?? null) ? RaidStatus::tryFrom($data["status"]) : null;
		$raid = new self(
			$id,
			new Vector3((float) $center[0], (float) $center[1], (float) $center[2]),
			is_int($data["omenLevel"] ?? null) ? $data["omenLevel"] : 1,
			World::DIFFICULTY_NORMAL,
			is_bool($data["hadVillagers"] ?? null) ? $data["hadVillagers"] : false
		);
		$raid->totalWaves = $totalWaves;
		$raid->status = $status ?? RaidStatus::ONGOING;
		$raid->wave = is_int($data["wave"] ?? null) ? $data["wave"] : 0;
		$raid->cooldown = is_int($data["cooldown"] ?? null) ? $data["cooldown"] : self::PREPARE_TICKS;
		$raid->ticks = is_int($data["ticks"] ?? null) ? $data["ticks"] : 0;
		$raid->endTicks = is_int($data["endTicks"] ?? null) ? $data["endTicks"] : self::END_TICKS;
		$raid->waveSize = is_int($data["waveSize"] ?? null) ? $data["waveSize"] : 0;
		foreach((is_array($data["heroes"] ?? null) ? $data["heroes"] : []) as $uuid){
			if(is_string($uuid)){
				$raid->heroes[$uuid] = true;
			}
		}
		foreach((is_array($data["beds"] ?? null) ? $data["beds"] : []) as $bed){
			if(is_array($bed) && count($bed) === 3 && is_int($bed[0] ?? null) && is_int($bed[1] ?? null) && is_int($bed[2] ?? null)){
				$raid->beds[] = new Vector3($bed[0], $bed[1], $bed[2]);
			}
		}
		//a wave in progress resumes with whatever raiders are loaded
		$raid->raidersAlive = $raid->cooldown === -1 ? $raid->waveSize : 0;
		$raid->graceTicks = 100;
		return $raid;
	}
}
