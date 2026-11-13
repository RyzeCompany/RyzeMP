<?php
declare(strict_types=1);

namespace pocketmine\entity\ai;

use PHPUnit\Framework\TestCase;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\GoalSelector;

/** No live world needed: validates exclusivity and preemption of goal flags. */
final class GoalSelectorTest extends TestCase{
	private static function goal(int $flags, bool $ready) : Goal{
		return new class($flags, $ready) extends Goal{
			public int $starts = 0;
			public int $stops = 0;
			public int $ticks = 0;
			public function __construct(private int $flags, public bool $ready){}
			public function getFlags() : int{ return $this->flags; }
			public function canStart() : bool{ return $this->ready; }
			public function canContinue() : bool{ return $this->ready; }
			public function start() : void{ ++$this->starts; }
			public function stop() : void{ ++$this->stops; }
			public function tick() : void{ ++$this->ticks; }
		};
	}

	public function testHigherPriorityPreemptsMoveWithoutInterruptingUnrelatedLook() : void{
		$selector = new GoalSelector(1);
		$wander = self::goal(Goal::FLAG_MOVE, true);
		$look = self::goal(Goal::FLAG_LOOK, true);
		$panic = self::goal(Goal::FLAG_MOVE, false);
		$selector->addGoal(10, $wander);
		$selector->addGoal(12, $look);
		$selector->addGoal(1, $panic);
		$selector->tick();
		self::assertTrue($selector->isRunning($wander));
		self::assertTrue($selector->isRunning($look));
		self::assertFalse($selector->isRunning($panic));
		$panic->ready = true;
		$selector->tick();
		self::assertTrue($selector->isRunning($panic));
		self::assertFalse($selector->isRunning($wander));
		self::assertTrue($selector->isRunning($look));
		self::assertSame(1, $wander->stops);
		$panic->ready = false;
		$selector->tick();
		self::assertFalse($selector->isRunning($panic));
		self::assertTrue($selector->isRunning($wander));
		self::assertSame(2, $wander->starts);
	}

	public function testDisabledFlagsStopOwnersAndPreventRestartUntilEnabled() : void{
		$selector = new GoalSelector(1);
		$move = self::goal(Goal::FLAG_MOVE, true);
		$selector->addGoal(1, $move);
		$selector->tick();
		$selector->setFlagDisabled(Goal::FLAG_MOVE, true);
		$selector->tick();
		self::assertFalse($selector->isRunning($move));
		$selector->tick();
		self::assertSame(1, $move->starts);
		$selector->setFlagDisabled(Goal::FLAG_MOVE, false);
		$selector->tick();
		self::assertTrue($selector->isRunning($move));
		$selector->removeGoal($move);
		self::assertSame([], $selector->getRunningGoals());
		self::assertSame(2, $move->stops);
	}

	public function testNoNewGoalStartsWhenEvaluationBudgetExhausted() : void{
		$selector = new GoalSelector(1);
		$goal = self::goal(Goal::FLAG_MOVE, true);
		$selector->addGoal(5, $goal);
		$selector->tick(false);
		self::assertFalse($selector->isRunning($goal));
		$selector->tick(true);
		self::assertTrue($selector->isRunning($goal));
		$selector->tick(false);
		self::assertSame(2, $goal->ticks);
	}
}
