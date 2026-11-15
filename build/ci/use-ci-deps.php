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

if(count($argv) !== 3){
	fwrite(STDERR, "usage: php use-ci-deps.php <composer.json> <deps dir>\n");
	exit(1);
}
[, $composerJson, $depsDir] = $argv;

$packages = [
	"pocketmine/bedrock-protocol" => "BedrockProtocol",
	"pocketmine/bedrock-data" => "BedrockData",
	"pocketmine/bedrock-block-upgrade-schema" => "BedrockBlockUpgradeSchema",
	"pocketmine/bedrock-item-upgrade-schema" => "BedrockItemUpgradeSchema",
	"pocketmine/raklib" => "RakLib",
];

//packages whose dependents use a normal version constraint (raklib-ipc requires ^1.0.0): the path repo
//presents them as this version instead of dev-<branch>, and the root constraint is left untouched
$fixedVersions = [
	"pocketmine/raklib" => "1.2.1",
];

/** @var array{require: array<string, string>, repositories?: list<array{type: string, url?: string}>} $data */
$data = json_decode((string) file_get_contents($composerJson), true, flags: JSON_THROW_ON_ERROR);
$repositories = [];
foreach($packages as $name => $dir){
	$path = rtrim(str_replace("\\", "/", $depsDir), "/") . "/" . $dir;
	if(!is_dir($path)){
		fwrite(STDERR, "missing $path\n");
		exit(1);
	}
	$options = ["symlink" => false];
	if(isset($fixedVersions[$name])){
		$options["versions"] = [$name => $fixedVersions[$name]];
	}else{
		$data["require"][$name] = "*@dev";
	}
	$repositories[] = ["type" => "path", "url" => $path, "options" => $options];
}
$kept = array_values(array_filter(
	$data["repositories"] ?? [],
	fn(array $repo) => !str_contains($repo["url"] ?? "", "github.com/PocketMine-MP-Reborn/")
));
$data["repositories"] = array_merge($repositories, $kept);

file_put_contents($composerJson, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo "composer.json now uses the dependencies in $depsDir\n";
