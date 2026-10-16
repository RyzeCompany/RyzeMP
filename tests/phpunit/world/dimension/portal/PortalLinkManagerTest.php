<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\portal;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PortalLinkManagerTest extends TestCase{

	private function area(string $world = "world", PortalType $type = PortalType::NETHER) : PortalArea{
		return new PortalArea($world, $type, 10, 78, -90, 11, 80, -90);
	}

	public function testEntirePortalAreaResolvesByWorldAndType() : void{
		$manager = new PortalLinkManager();
		$link = new PortalLink("hcf:nether", $this->area(), new PortalDestination("world_nether", 0, 46, 0));
		$manager->register($link);
		foreach([10, 11] as $x){
			foreach([78, 79, 80] as $y){
				self::assertSame($link, $manager->find("world", PortalType::NETHER, $x, $y, -90));
			}
		}
		self::assertNull($manager->find("other", PortalType::NETHER, 10, 78, -90));
		self::assertNull($manager->find("world", PortalType::END, 10, 78, -90));
		self::assertNull($manager->find("world", PortalType::NETHER, 12, 78, -90));
	}

	public function testRegisterGetAndRemove() : void{
		$manager = new PortalLinkManager();
		$link = new PortalLink("hcf:nether", $this->area(), new PortalDestination("world_nether", 0, 46, 0));
		$manager->register($link);
		self::assertSame($link, $manager->get($link->id));
		self::assertSame([$link->id => $link], $manager->getLinks());
		self::assertSame($link, $manager->unregister($link->id));
		self::assertNull($manager->get($link->id));
		self::assertNull($manager->find("world", PortalType::NETHER, 10, 78, -90));
		self::assertNull($manager->unregister($link->id));
	}

	public function testDuplicateIdCannotSilentlyReplaceLink() : void{
		$manager = new PortalLinkManager();
		$first = new PortalLink("same", $this->area(), new PortalDestination("world_nether", 0, 46, 0));
		$manager->register($first);
		try{
			$manager->register(new PortalLink("same", $this->area("other"), new PortalDestination("world", 0, 70, 0)));
			self::fail("Duplicate link ID accepted");
		}catch(InvalidArgumentException){
			self::assertSame($first, $manager->get("same"));
		}
	}

	public function testOverlappingAreaIsRejected() : void{
		$manager = new PortalLinkManager();
		$manager->register(new PortalLink("first", $this->area(), new PortalDestination("world_nether", 0, 46, 0)));
		$this->expectException(InvalidArgumentException::class);
		$manager->register(new PortalLink("second", new PortalArea("world", PortalType::NETHER, 11, 79, -90, 12, 81, -90), new PortalDestination("world", 0, 70, 0)));
	}

	public function testSameCoordinatesInDifferentWorldOrPortalTypeAreIndependent() : void{
		$manager = new PortalLinkManager();
		foreach([$this->area(), $this->area("other"), $this->area("world", PortalType::END)] as $i => $area){
			$manager->register(new PortalLink("link:$i", $area, new PortalDestination("destination", 0, 70, 0)));
		}
		self::assertCount(3, $manager->getLinks());
	}

	public function testBidirectionalLinkKeepsIndependentArrivals() : void{
		$manager = new PortalLinkManager();
		$overworld = $this->area();
		$nether = new PortalArea("world_nether", PortalType::NETHER, 0, 46, 0, 1, 48, 0);
		$atOverworld = new PortalDestination("world", 10, 78, -90, 180, 15);
		$atNether = new PortalDestination("world_nether", 0, 46, 0, 90);
		[$forward, $reverse] = $manager->registerBidirectional("hcf:spawn", $overworld, $atOverworld, $nether, $atNether);
		self::assertSame($atNether, $forward->destination);
		self::assertSame($atOverworld, $reverse->destination);
		self::assertSame($reverse, $manager->find("world_nether", PortalType::NETHER, 1, 48, 0));
	}

	public function testBidirectionalFailureDoesNotRegisterHalfThePair() : void{
		$manager = new PortalLinkManager();
		$manager->register(new PortalLink("existing", $this->area("world_nether"), new PortalDestination("world", 0, 70, 0)));
		try{
			$manager->registerBidirectional("pair", $this->area(), new PortalDestination("world", 0, 70, 0), $this->area("world_nether"), new PortalDestination("world_nether", 0, 46, 0));
			self::fail("Overlapping reverse link accepted");
		}catch(InvalidArgumentException){
			self::assertCount(1, $manager->getLinks());
			self::assertNull($manager->get("pair:forward"));
		}
	}

	public function testBidirectionalArrivalWorldMustMatchItsEndpoint() : void{
		$manager = new PortalLinkManager();
		$this->expectException(InvalidArgumentException::class);
		$manager->registerBidirectional("pair", $this->area(), new PortalDestination("wrong", 0, 70, 0), $this->area("world_nether"), new PortalDestination("world_nether", 0, 46, 0));
	}

	/** @return iterable<string, array{string, float, float, float, float, float}> */
	public static function invalidDestinations() : iterable{
		yield "unsafe path" => ["../world", 0, 70, 0, 0, 0];
		yield "NaN" => ["world", NAN, 70, 0, 0, 0];
		yield "infinite" => ["world", 0, INF, 0, 0, 0];
		yield "infinite rotation" => ["world", 0, 70, 0, INF, 0];
		yield "outside world" => ["world", 30000000, 70, 0, 0, 0];
		yield "outside height" => ["world", 0, 319, 0, 0, 0];
		yield "outside pitch" => ["world", 0, 70, 0, 0, 91];
	}

	#[DataProvider("invalidDestinations")]
	public function testInvalidDestinationsAreRejected(string $world, float $x, float $y, float $z, float $yaw, float $pitch) : void{
		$this->expectException(InvalidArgumentException::class);
		new PortalDestination($world, $x, $y, $z, $yaw, $pitch);
	}

	public function testReversedAreaCornersAreRejected() : void{
		$this->expectException(InvalidArgumentException::class);
		new PortalArea("world", PortalType::NETHER, 11, 78, -90, 10, 80, -90);
	}
}
