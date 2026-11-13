<?php
declare(strict_types=1);
namespace pocketmine\entity\ai;

/** Shared budget for all worlds; FIFO-aged requests prevent AI starvation. */
final class AiBudget{
	private static ?FairTickBudget $goals = null;
	private static ?FairTickBudget $paths = null;
	private static int $pathNodes = 160;
	private static bool $enabled = true;
	/** @var list<string> */
	private static array $disabledWorlds = [];
	private function __construct(){}
	public static function initialize(int $goals = 120, int $paths = 8, int $nodes = 160, bool $enabled = true) : void{
		if(self::$goals === null){ self::configure($goals,$paths,$nodes,$enabled); }
	}
	public static function configure(int $goals, int $paths, int $nodes = 160, bool $enabled = true) : void{
		self::$enabled = $enabled;
		self::$goals = new FairTickBudget(max(1,$goals));
		self::$paths = new FairTickBudget(max(1,$paths));
		self::$pathNodes = max(16,min(1024,$nodes));
	}
	public static function isEnabled() : bool{ return self::$enabled; }
	/** @param list<string> $worldFolders */
	public static function setDisabledWorlds(array $worldFolders) : void{
		self::$disabledWorlds = $worldFolders;
	}
	public static function isEnabledInWorld(string $worldFolder) : bool{
		return self::$enabled && !in_array($worldFolder, self::$disabledWorlds, true);
	}
	public static function getPathNodeLimit() : int{ return self::$pathNodes; }
	public static function takeGoalUpdate(int $tick,int &$waitingSince) : bool{
		return self::take(self::$goals ??= new FairTickBudget(120),$tick,$waitingSince);
	}
	public static function takePathSearch(int $tick,int &$waitingSince) : bool{
		return self::take(self::$paths ??= new FairTickBudget(8),$tick,$waitingSince);
	}
	private static function take(FairTickBudget $budget,int $tick,int &$waitingSince) : bool{
		if($budget->tryTake($tick,$waitingSince)){ $waitingSince = -1; return true; }
		if($waitingSince < 0){ $waitingSince = $tick; }
		return false;
	}
}
