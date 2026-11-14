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

namespace pocketmine\item;

use PHPUnit\Framework\TestCase;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;

/**
 * Furnace fuel burn times, in ticks (furnace rate; blast furnaces and smokers burn twice as fast).
 */
final class FuelTimeTest extends TestCase{

	/**
	 * @phpstan-return \Generator<string, array{\Closure() : Item, int}, void, void>
	 */
	public static function fuelProvider() : \Generator{
		yield "ladder" => [fn() => VanillaBlocks::LADDER()->asItem(), 300];
		yield "lectern" => [fn() => VanillaBlocks::LECTERN()->asItem(), 300];
		yield "loom" => [fn() => VanillaBlocks::LOOM()->asItem(), 300];
		yield "chiseled bookshelf" => [fn() => VanillaBlocks::CHISELED_BOOKSHELF()->asItem(), 300];
		yield "fishing rod" => [fn() => VanillaItems::FISHING_ROD(), 300];
		yield "mangrove roots" => [fn() => VanillaBlocks::MANGROVE_ROOTS()->asItem(), 300];
		yield "oak shelf" => [fn() => VanillaBlocks::OAK_SHELF()->asItem(), 300];
		yield "wool" => [fn() => VanillaBlocks::WOOL()->setColor(DyeColor::RED)->asItem(), 100];
		yield "azalea" => [fn() => VanillaBlocks::AZALEA()->asItem(), 100];
		yield "flowering azalea" => [fn() => VanillaBlocks::FLOWERING_AZALEA()->asItem(), 100];
		yield "dead bush" => [fn() => VanillaBlocks::DEAD_BUSH()->asItem(), 100];
		yield "carpet" => [fn() => VanillaBlocks::CARPET()->setColor(DyeColor::BLUE)->asItem(), 67];
		yield "scaffolding" => [fn() => VanillaBlocks::SCAFFOLDING()->asItem(), 50];
		yield "oak pressure plate" => [fn() => VanillaBlocks::OAK_PRESSURE_PLATE()->asItem(), 300];
		yield "oak trapdoor" => [fn() => VanillaBlocks::OAK_TRAPDOOR()->asItem(), 300];
		yield "oak hanging sign" => [fn() => VanillaItems::OAK_HANGING_SIGN(), 200];
		//kept as they are (Bedrock values, also in Dragonfly; Java differs)
		yield "bow" => [fn() => VanillaItems::BOW(), 200];
		yield "bowl" => [fn() => VanillaItems::BOWL(), 200];
		yield "oak slab" => [fn() => VanillaBlocks::OAK_SLAB()->asItem(), 300];
		yield "coal" => [fn() => VanillaItems::COAL(), 1600];
		//nether wood doesn't burn
		yield "crimson pressure plate" => [fn() => VanillaBlocks::CRIMSON_PRESSURE_PLATE()->asItem(), 0];
		yield "warped pressure plate" => [fn() => VanillaBlocks::WARPED_PRESSURE_PLATE()->asItem(), 0];
		yield "crimson trapdoor" => [fn() => VanillaBlocks::CRIMSON_TRAPDOOR()->asItem(), 0];
		yield "warped trapdoor" => [fn() => VanillaBlocks::WARPED_TRAPDOOR()->asItem(), 0];
		yield "crimson hanging sign" => [fn() => VanillaItems::CRIMSON_HANGING_SIGN(), 0];
		yield "warped hanging sign" => [fn() => VanillaItems::WARPED_HANGING_SIGN(), 0];
		yield "crimson shelf" => [fn() => VanillaBlocks::CRIMSON_SHELF()->asItem(), 0];
	}

	/**
	 * @dataProvider fuelProvider
	 * @phpstan-param \Closure() : Item $item
	 */
	public function testFuelTime(\Closure $item, int $ticks) : void{
		self::assertSame($ticks, $item()->getFuelTime());
	}

	public function testNetherWoodHangingSignBlocksDontBurn() : void{
		self::assertSame(0, VanillaBlocks::CRIMSON_CEILING_CENTER_HANGING_SIGN()->getFuelTime());
		self::assertSame(200, VanillaBlocks::OAK_WALL_HANGING_SIGN()->getFuelTime());
	}
}
