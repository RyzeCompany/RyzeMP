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

namespace pocketmine\world\generator\vanilla\surface\rule;

use pocketmine\world\generator\vanilla\surface\MaterialContext;
use pocketmine\world\generator\vanilla\surface\MaterialRuleCompiler;

/** "minecraft:sequence": the first rule that gives a result. */
final class SequenceRule extends MaterialRule{

	/**
	 * @param MaterialRule[] $rules
	 * @phpstan-param list<MaterialRule> $rules
	 */
	public function __construct(
		private readonly array $rules
	){}

	public function apply(MaterialContext $c) : int{
		foreach($this->rules as $rule){
			$result = $rule->apply($c);
			if($result !== self::NONE){
				return $result;
			}
		}
		return self::NONE;
	}

	public function compile(MaterialRuleCompiler $compiler) : string{
		$code = "";
		foreach($this->rules as $rule){
			$code .= $rule->compile($compiler);
		}
		return $code;
	}
}
