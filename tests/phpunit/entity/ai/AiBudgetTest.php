<?php
declare(strict_types=1);

namespace pocketmine\entity\ai;

use PHPUnit\Framework\TestCase;

final class AiBudgetTest extends TestCase{
	protected function tearDown() : void{ AiBudget::configure(120,8,160); }

	public function testFairGoalBudgetAcrossTickBoundaries() : void{
		AiBudget::configure(1,1,16);
		$a=-1;$b=-1;
		self::assertTrue(AiBudget::takeGoalUpdate(100,$a));
		self::assertFalse(AiBudget::takeGoalUpdate(100,$b));
		self::assertSame(100,$b);
		self::assertTrue(AiBudget::takeGoalUpdate(101,$b),"A deferred request gets priority");
		self::assertSame(-1,$b);
		self::assertFalse(AiBudget::takeGoalUpdate(101,$a));
		self::assertTrue(AiBudget::takeGoalUpdate(102,$a));
	}
	public function testPathBudgetAndClampedNodes() : void{
		AiBudget::configure(4,1,1);
		self::assertSame(16,AiBudget::getPathNodeLimit());
		$a=-1;$b=-1;
		self::assertTrue(AiBudget::takePathSearch(200,$a));
		self::assertFalse(AiBudget::takePathSearch(200,$b));
		self::assertTrue(AiBudget::takePathSearch(201,$b));
		AiBudget::configure(1,1,2000);
		self::assertSame(1024,AiBudget::getPathNodeLimit());
	}
}
