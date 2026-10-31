<?php

declare(strict_types=1);

// A consumer project deliberately installs RyzeMP below vendor/. Its own
// dependencies are siblings, rather than a second vendor/ directory inside it.
$mode = $argv[1] ?? '';
$directory = $argv[2] ?? '';
if($directory === '' || !in_array($mode, ['prepare', 'verify'], true)){
	fwrite(STDERR, "Usage: composer-api-consumer.php <prepare|verify> <directory>\n");
	exit(1);
}

if($mode === 'prepare'){
	$source = dirname(__DIR__);
	if(!preg_match('/public const PLUGIN_API_VERSION = "([0-9]+\\.[0-9]+\\.[0-9]+)";/', file_get_contents($source . '/src/VersionInfo.php'), $matches)){
		throw new RuntimeException('Cannot read the Composer API version.');
	}
	if(!is_dir($directory) && !mkdir($directory, 0777, true)){
		throw new RuntimeException('Cannot create the consumer project.');
	}
	$manifest = [
		'name' => 'ryzecompany/api-consumer-test',
		'repositories' => [[
			'type' => 'path',
			'url' => $source,
			'options' => ['symlink' => false, 'versions' => ['ryzemcbe/ryzemp' => $matches[1]]]
		]],
		'require-dev' => ['ryzemcbe/ryzemp' => $matches[1]],
		'minimum-stability' => 'beta',
		'prefer-stable' => true,
		'config' => ['allow-plugins' => false]
	];
	file_put_contents($directory . '/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
	exit(0);
}

require $directory . '/vendor/autoload.php';
if(\Composer\InstalledVersions::getInstallPath('pocketmine/pocketmine-mp') !== null){
	throw new RuntimeException('A second PocketMine implementation was installed.');
}
if(realpath(\pocketmine\COMPOSER_AUTOLOADER_PATH) !== realpath($directory . '/vendor/autoload.php')){
	throw new RuntimeException('Worker autoload path does not point to the consumer vendor directory.');
}
foreach([
	\pocketmine\BEDROCK_DATA_PATH . 'required_item_list.json',
	\pocketmine\BEDROCK_BLOCK_UPGRADE_SCHEMA_PATH,
	\pocketmine\BEDROCK_ITEM_UPGRADE_SCHEMA_PATH
] as $path){
	if(!file_exists($path)){
		throw new RuntimeException('Installed dependency path does not exist: ' . $path);
	}
}
$converter = \pocketmine\network\mcpe\convert\TypeConverter::getInstance(\pocketmine\network\mcpe\protocol\ProtocolInfo::CURRENT_PROTOCOL);
foreach([\pocketmine\block\VanillaBlocks::CHEST(), \pocketmine\block\VanillaBlocks::HOPPER()] as $block){
	$converter->getBlockTranslator()->internalIdToNetworkId($block->getStateId());
}
$inventory = new \pocketmine\inventory\SimpleInventory(27);
$inventory->setItem(0, \pocketmine\item\VanillaItems::DIAMOND()->setCount(3));
if($inventory->getItem(0)->getCount() !== 3){
	throw new RuntimeException('Real inventory API did not retain its contents.');
}
echo 'RyzeMP Composer API ' . \pocketmine\VersionInfo::PLUGIN_API_VERSION . ': autoload, dependency paths, protocol data and inventory verified.' . PHP_EOL;
