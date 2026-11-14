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

use PHPUnit\Framework\MockObject\MockObject;
use pocketmine\block\tile\ChiseledBookshelf as TileChiseledBookshelf;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\inventory\transaction\CartographyRenameTransaction;
use pocketmine\item\FilledMap;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\PotionType;
use pocketmine\item\TippedArrow;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use function str_repeat;

/**
 * Cauldron, candle cake, chiseled bookshelf and cartography table details (vanilla audit batch "containers").
 */
final class ContainersInteractionTest extends MobTestCase{
	private const Y = 5;

	private function potionCauldron(int $fillLevel, Item $potion) : PotionCauldron{
		$this->set(0, self::Y, 0, VanillaBlocks::POTION_CAULDRON()->setFillLevel($fillLevel)->setPotionItem($potion));
		$block = $this->get(0, self::Y, 0);
		self::assertInstanceOf(PotionCauldron::class, $block);
		return $block;
	}

	/**
	 * @return int[][]
	 * @phpstan-return list<array{int, int, int, int}> fill level, arrows, arrows tipped, fill level left
	 */
	public static function arrowTippingProvider() : array{
		return [
			[2, 64, 16, 0], //a third of a cauldron tips 16 arrows
			[4, 64, 32, 0],
			[6, 64, 64, 0], //a full cauldron tips a whole stack
			[6, 10, 10, 4], //a few arrows use one bottle's worth
			[6, 20, 20, 2],
			[3, 64, 32, 0], //odd levels round up to whole bottles
		];
	}

	/**
	 * @dataProvider arrowTippingProvider
	 */
	public function testArrowTippingAmounts(int $fillLevel, int $arrowCount, int $tippedCount, int $levelLeft) : void{
		$cauldron = $this->potionCauldron($fillLevel, VanillaItems::POTION()->setType(PotionType::SWIFTNESS));
		$arrows = VanillaItems::ARROW()->setCount($arrowCount);
		$returned = [];
		$cauldron->onInteract($arrows, Facing::UP, Vector3::zero(), null, $returned);

		self::assertSame($arrowCount - $tippedCount, $arrows->getCount());
		self::assertCount(1, $returned);
		self::assertSame($tippedCount, $returned[0]->getCount());
		$after = $this->get(0, self::Y, 0);
		if($levelLeft === 0){
			self::assertSame(BlockTypeIds::CAULDRON, $after->getTypeId());
		}else{
			self::assertInstanceOf(PotionCauldron::class, $after);
			self::assertSame($levelLeft, $after->getFillLevel());
		}
	}

	public function testFullPotionCauldronTipsArrows() : void{
		$cauldron = $this->potionCauldron(6, VanillaItems::POTION()->setType(PotionType::SWIFTNESS));
		$arrows = VanillaItems::ARROW()->setCount(20);
		$returned = [];
		self::assertTrue($cauldron->onInteract($arrows, Facing::UP, Vector3::zero(), null, $returned));

		self::assertSame(0, $arrows->getCount());
		self::assertCount(1, $returned);
		$tipped = $returned[0];
		self::assertInstanceOf(TippedArrow::class, $tipped);
		self::assertSame(PotionType::SWIFTNESS, $tipped->getType());
		self::assertSame(20, $tipped->getCount());

		$after = $this->get(0, self::Y, 0);
		self::assertInstanceOf(PotionCauldron::class, $after);
		self::assertSame(2, $after->getFillLevel(), "20 arrows use two bottles' worth");
	}

	public function testLingeringPotionGivesTheBaseArrowAndEmpties() : void{
		$cauldron = $this->potionCauldron(2, VanillaItems::LINGERING_POTION()->setType(PotionType::POISON));
		$arrows = VanillaItems::ARROW()->setCount(64);
		$returned = [];
		$cauldron->onInteract($arrows, Facing::UP, Vector3::zero(), null, $returned);

		self::assertSame(48, $arrows->getCount());
		self::assertCount(1, $returned);
		$tipped = $returned[0];
		self::assertInstanceOf(TippedArrow::class, $tipped);
		self::assertSame(PotionType::POISON, $tipped->getType());
		self::assertSame(16, $tipped->getCount());
		self::assertSame(BlockTypeIds::CAULDRON, $this->get(0, self::Y, 0)->getTypeId());
	}

	public function testIncompatibleMixEmptiesTheCauldron() : void{
		$cauldron = $this->potionCauldron(4, VanillaItems::POTION()->setType(PotionType::SWIFTNESS));
		$potion = VanillaItems::POTION()->setType(PotionType::HEALING);
		$returned = [];
		$cauldron->onInteract($potion, Facing::UP, Vector3::zero(), null, $returned);
		self::assertSame(BlockTypeIds::CAULDRON, $this->get(0, self::Y, 0)->getTypeId());
		self::assertSame(0, $potion->getCount());
		self::assertCount(1, $returned);
		self::assertSame(ItemTypeIds::GLASS_BOTTLE, $returned[0]->getTypeId());
	}

	public function testPowderSnowCauldronPutsOutFire() : void{
		$this->set(0, self::Y, 0, VanillaBlocks::POWDER_SNOW_CAULDRON()->setFillLevel(FillableCauldron::MAX_FILL_LEVEL));
		$cauldron = $this->get(0, self::Y, 0);
		self::assertInstanceOf(PowderSnowCauldron::class, $cauldron);
		self::assertTrue($cauldron->hasEntityCollision());

		$cow = new Cow(new Location(0.5, self::Y + 0.3, 0.5, $this->world, 0, 0));
		self::assertTrue($cauldron->onEntityInside($cow), "nothing happens to an entity that is not burning");
		$cow->setOnFire(5);
		self::assertFalse($cauldron->onEntityInside($cow));
		self::assertFalse($cow->isOnFire());
		$after = $this->get(0, self::Y, 0);
		self::assertInstanceOf(WaterCauldron::class, $after, "the snow melts into water");
		self::assertSame(FillableCauldron::MAX_FILL_LEVEL - 1, $after->getFillLevel());

		$this->set(0, self::Y, 0, VanillaBlocks::POWDER_SNOW_CAULDRON()->setFillLevel(1));
		$cow->setOnFire(5);
		$this->get(0, self::Y, 0)->onEntityInside($cow);
		self::assertSame(BlockTypeIds::CAULDRON, $this->get(0, self::Y, 0)->getTypeId(), "the last level leaves an empty cauldron");
		$cow->close();
	}

	private function candleCake(bool $lit) : CakeWithCandle{
		$this->set(0, self::Y - 1, 0, VanillaBlocks::STONE());
		$this->set(0, self::Y, 0, VanillaBlocks::CAKE_WITH_CANDLE()->setLit($lit));
		$cake = $this->get(0, self::Y, 0);
		self::assertInstanceOf(CakeWithCandle::class, $cake);
		return $cake;
	}

	private function mockPlayer() : Player&MockObject{
		$player = $this->createMock(Player::class);
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	private function eatingPlayer() : Player{
		$player = $this->mockPlayer();
		$player->expects(self::once())->method("consumeObject")->willReturnCallback(function(CakeWithCandle $cake) use ($player) : bool{
			$cake->onConsume($player);
			return true;
		});
		return $player;
	}

	public function testUnlitCandleCakeIsEatenWithAnEmptyHand() : void{
		$cake = $this->candleCake(false);
		$returned = [];
		self::assertTrue($cake->onInteract(VanillaItems::AIR(), Facing::UP, Vector3::zero(), $this->eatingPlayer(), $returned));
		$after = $this->get(0, self::Y, 0);
		self::assertInstanceOf(Cake::class, $after);
		self::assertSame(1, $after->getBites());
	}

	public function testLitCandleIsPutOutFromTheTopAndEatenFromTheSide() : void{
		$cake = $this->candleCake(true);
		$player = $this->mockPlayer();
		$player->expects(self::never())->method("consumeObject");
		self::assertTrue($cake->onInteract(VanillaItems::AIR(), Facing::UP, Vector3::zero(), $player));
		$after = $this->get(0, self::Y, 0);
		self::assertInstanceOf(CakeWithCandle::class, $after);
		self::assertFalse($after->isLit());

		$cake = $this->candleCake(true);
		$cake->onInteract(VanillaItems::AIR(), Facing::NORTH, Vector3::zero(), $this->eatingPlayer());
		self::assertInstanceOf(Cake::class, $this->get(0, self::Y, 0));
	}

	public function testFlintAndSteelStillLightsTheCandle() : void{
		$cake = $this->candleCake(false);
		$player = $this->mockPlayer();
		$player->expects(self::never())->method("consumeObject");
		self::assertTrue($cake->onInteract(VanillaItems::FLINT_AND_STEEL(), Facing::NORTH, Vector3::zero(), $player));
		$after = $this->get(0, self::Y, 0);
		self::assertInstanceOf(CakeWithCandle::class, $after);
		self::assertTrue($after->isLit());
	}

	public function testChiseledBookshelfStillStoresBooks() : void{
		$this->set(0, self::Y, 0, VanillaBlocks::CHISELED_BOOKSHELF()->setFacing(Facing::NORTH));
		$shelf = $this->get(0, self::Y, 0);
		self::assertInstanceOf(ChiseledBookshelf::class, $shelf);
		$returned = [];
		self::assertTrue($shelf->onInteract(VanillaItems::ENCHANTED_BOOK(), Facing::NORTH, new Vector3(0.1, 0.9, 0), null, $returned));
		$tile = $this->world->getTile($shelf->getPosition());
		self::assertInstanceOf(TileChiseledBookshelf::class, $tile);
		self::assertCount(1, $tile->getInventory()->getContents());

		$shelf = $this->get(0, self::Y, 0);
		self::assertTrue($shelf->onInteract(VanillaItems::AIR(), Facing::NORTH, new Vector3(0.1, 0.9, 0), null, $returned));
		self::assertCount(1, $returned);
		self::assertSame(ItemTypeIds::ENCHANTED_BOOK, $returned[0]->getTypeId());
	}

	public function testCartographyRename() : void{
		$map = VanillaItems::FILLED_MAP()->setMapId(5)->setCount(3);
		$renamed = CartographyRenameTransaction::calculateResult($map, "Base");
		self::assertInstanceOf(FilledMap::class, $renamed);
		self::assertSame("Base", $renamed->getCustomName());
		self::assertSame(3, $renamed->getCount(), "the whole stack is renamed");
		self::assertSame(5, $renamed->getMapId());

		self::assertNull(CartographyRenameTransaction::calculateResult($map, ""), "no custom name to remove");
		self::assertNull(CartographyRenameTransaction::calculateResult($renamed, "Base"), "same name");
		$cleared = CartographyRenameTransaction::calculateResult($renamed, "  ");
		self::assertNotNull($cleared);
		self::assertFalse($cleared->hasCustomName());
		self::assertNull(CartographyRenameTransaction::calculateResult(VanillaItems::PAPER(), "Base"), "only maps");

		$long = CartographyRenameTransaction::calculateResult($map, str_repeat("a", 80));
		self::assertNotNull($long);
		self::assertSame(str_repeat("a", 50), $long->getCustomName(), "same length limit as the anvil");
	}
}
