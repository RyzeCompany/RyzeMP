<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use PHPUnit\Framework\TestCase;
use pocketmine\loot\LootContext;
use pocketmine\loot\LootItemConverter;
use pocketmine\loot\LootTables;
use pocketmine\world\generator\vanilla\structure\TrialChamberContent;
use function array_keys;
use function getenv;
use function implode;
use function is_dir;

/** Checks actual Trial Chamber rewards against the pinned Java data and Bedrock item mappings. */
final class VaultLootCoverageTest extends TestCase{
	public function testOminousVaultRewardsRemainRepresentable() : void{
		$directory = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		if($directory === false || !is_dir($directory . "/data/minecraft/loot_table")){
			self::markTestSkipped("Authentic Minecraft loot data required");
		}
		TrialChamberContent::registerRewardItems();
		$table = (new LootTables($directory))->get("minecraft:chests/trial_chambers/reward_ominous");
		self::assertNotNull($table, "Ominous vault loot table is missing from the pinned cache");
		$missing = [];
		$empty = [];
		for($seed = 1; $seed <= 256; ++$seed){
			$raw = $table->getRandomItems(LootContext::seeded($seed));
			$represented = 0;
			foreach($raw as $stack){
				if($stack->isEmpty()){
					continue;
				}
				if(LootItemConverter::toItem($stack, $directory) === null){
					$missing[$stack->id] = true;
				}else{
					++$represented;
				}
			}
			if($represented === 0){
				$empty[] = $seed;
			}
		}
		self::assertSame([], array_keys($missing), "Unrepresented ominous vault items");
		self::assertSame([], $empty, "Ominous vaults can roll no usable reward for seeds: " . implode(", ", $empty));
	}
}
