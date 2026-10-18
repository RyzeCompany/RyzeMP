<?php

declare(strict_types=1);

namespace pocketmine\command\args;

use CortexPE\Commando\args\IntegerArgument;
use CortexPE\Commando\args\RawStringArgument;
use pocketmine\command\CommandSender;
use pocketmine\item\enchantment\StringToEnchantmentParser;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\types\command\CommandHardEnum;
use pocketmine\network\mcpe\protocol\types\command\CommandParameter;
use pocketmine\network\mcpe\protocol\types\command\CommandSoftEnum;

/** String-preserving adapters for the server's existing gameplay handlers. */
final class NativeArgument extends RawStringArgument{
	/** @param list<string> $values */
	public function __construct(string $name, private string $kind = "string", bool $optional = false, private array $values = []){
		parent::__construct($name, $optional);
	}

	public function getSpanLength() : int{return match($this->kind){"position" => 3, "text" => PHP_INT_MAX, default => 1};}
	public function getTypeName() : string{return $this->kind;}
	public function getNetworkType() : int{
		return match($this->kind){
			"integer" => AvailableCommandsPacket::ARG_TYPE_INT,
			"number" => AvailableCommandsPacket::ARG_TYPE_FLOAT,
			"position" => AvailableCommandsPacket::ARG_TYPE_POSITION,
			"text" => AvailableCommandsPacket::ARG_TYPE_RAWTEXT,
			default => AvailableCommandsPacket::ARG_TYPE_STRING
		};
	}

	public function canParse(string $testString, CommandSender $sender) : bool{
		return match($this->kind){
			"integer" => (new IntegerArgument($this->name))->canParse($testString, $sender),
			"number" => is_numeric($testString) && is_finite((float) $testString),
			"position" => self::isPosition($testString),
			"enum" => in_array(strtolower($testString), array_map("strtolower", $this->values), true),
			"duration" => strtolower($testString) === "infinite" || (new IntegerArgument($this->name))->canParse($testString, $sender),
			"time" => in_array($testString, ["day", "noon", "sunset", "night", "midnight", "sunrise"], true) || (new IntegerArgument($this->name))->canParse($testString, $sender),
			"xp" => (new IntegerArgument($this->name))->canParse(str_ends_with($testString, "L") ? substr($testString, 0, -1) : $testString, $sender),
			default => $testString !== ""
		};
	}

	private static function isPosition(string $value) : bool{
		$coordinates = explode(" ", $value);
		if(count($coordinates) !== 3){return false;}
		foreach($coordinates as $coordinate){
			if(str_starts_with($coordinate, "~")){$coordinate = substr($coordinate, 1);if($coordinate === ""){continue;}}
			if(!is_numeric($coordinate) || !is_finite((float) $coordinate)){return false;}
		}
		return true;
	}

	public function getNetworkParameterDataFor(CommandSender $sender) : CommandParameter{
		if($this->kind === "enum"){
			return CommandParameter::enum($this->name, new CommandHardEnum("native:" . $this->name . ":" . md5(implode("|", $this->values)), $this->values), 0, $this->optional);
		}
		$values = match($this->kind){
			"enchantment" => array_map("strval", StringToEnchantmentParser::getInstance()->getKnownAliases()),
			"player" => ["@s", ...array_map(static fn($player) : string => $player->getName(), array_values($sender->getServer()->getOnlinePlayers()))],
			"world" => array_map(static fn($world) : string => $world->getFolderName(), array_values($sender->getServer()->getWorldManager()->getWorlds())),
			default => null
		};
		return $values === null ? parent::getNetworkParameterDataFor($sender) :
			CommandParameter::softEnum($this->name, new CommandSoftEnum("native:" . $this->kind, $values), 0, $this->optional);
	}
}
