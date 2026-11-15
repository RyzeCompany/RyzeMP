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

namespace pocketmine\entity\ai;

use PHPUnit\Framework\TestCase;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\GoalSelector;

final class GoalSelectorTest extends TestCase{

	private function goal(int $flags, bool $wantsToRun = true, bool $interruptible = true) : TestGoal{
		return new TestGoal($flags, $wantsToRun, $interruptible);
	}

	public function testHigherPriorityTakesSharedFlag() : void{
		$selector = new GoalSelector(1);
		$wander = $this->goal(Goal::FLAG_MOVE);
		$selector->addGoal(5, $wander);
		$selector->tick();
		self::assertTrue($selector->isRunning($wander));

		$panic = $this->goal(Goal::FLAG_MOVE, false);
		$selector->addGoal(1, $panic);
		$selector->tick();
		self::assertFalse($selector->isRunning($panic));

		$panic->wantsToRun = true;
		$selector->tick();
		self::assertTrue($selector->isRunning($panic));
		self::assertFalse($selector->isRunning($wander));
		self::assertSame(1, $wander->stops);

		//the lower priority goal can't come back while the higher one holds the flag
		$selector->tick();
		self::assertFalse($selector->isRunning($wander));

		$panic->wantsToRun = false;
		$selector->tick(); //panic stops, then wander starts again in the same evaluation
		self::assertFalse($selector->isRunning($panic));
		self::assertTrue($selector->isRunning($wander));
	}

	public function testLowerPriorityCannotPreempt() : void{
		$selector = new GoalSelector(1);
		$attack = $this->goal(Goal::FLAG_MOVE | Goal::FLAG_LOOK);
		$look = $this->goal(Goal::FLAG_LOOK);
		$selector->addGoal(2, $attack);
		$selector->addGoal(8, $look);
		$selector->tick();
		self::assertTrue($selector->isRunning($attack));
		self::assertFalse($selector->isRunning($look));
	}

	public function testGoalsWithDifferentFlagsRunTogether() : void{
		$selector = new GoalSelector(1);
		$move = $this->goal(Goal::FLAG_MOVE);
		$look = $this->goal(Goal::FLAG_LOOK);
		$jump = $this->goal(Goal::FLAG_JUMP);
		$selector->addGoal(3, $move);
		$selector->addGoal(4, $look);
		$selector->addGoal(0, $jump);
		$selector->tick();
		self::assertCount(3, $selector->getRunningGoals());
		self::assertSame(1, $move->ticks);
		self::assertSame(1, $look->ticks);
	}

	public function testNonInterruptibleGoalKeepsFlags() : void{
		$selector = new GoalSelector(1);
		$float = $this->goal(Goal::FLAG_JUMP, true, false);
		$selector->addGoal(5, $float);
		$selector->tick();
		$leap = $this->goal(Goal::FLAG_JUMP);
		$selector->addGoal(1, $leap);
		$selector->tick();
		self::assertTrue($selector->isRunning($float));
		self::assertFalse($selector->isRunning($leap));
	}

	public function testEvaluationIntervalAndBudget() : void{
		$selector = new GoalSelector(3);
		$goal = $this->goal(Goal::FLAG_MOVE);
		$selector->addGoal(1, $goal);
		$selector->tick();
		$selector->tick();
		self::assertFalse($selector->isRunning($goal), "goals only start every 3 ticks");
		$selector->tick(false);
		self::assertFalse($selector->isRunning($goal), "no evaluation without AI budget");
		$selector->tick();
		self::assertTrue($selector->isRunning($goal));
		self::assertSame(1, $goal->starts);
	}

	public function testDisabledFlagStopsGoals() : void{
		$selector = new GoalSelector(1);
		$move = $this->goal(Goal::FLAG_MOVE);
		$selector->addGoal(1, $move);
		$selector->tick();
		$selector->setFlagDisabled(Goal::FLAG_MOVE, true);
		$selector->tick();
		self::assertFalse($selector->isRunning($move));
		$selector->setFlagDisabled(Goal::FLAG_MOVE, false);
		$selector->tick();
		self::assertTrue($selector->isRunning($move));
	}
}

final class TestGoal extends Goal{
	public int $starts = 0;
	public int $stops = 0;
	public int $ticks = 0;

	public function __construct(
		private int $flags,
		public bool $wantsToRun,
		private bool $interruptible
	){}

	public function getFlags() : int{ return $this->flags; }

	public function canStart() : bool{ return $this->wantsToRun; }

	public function isInterruptible() : bool{ return $this->interruptible; }

	public function start() : void{ ++$this->starts; }

	public function stop() : void{ ++$this->stops; }

	public function tick() : void{ ++$this->ticks; }
}
