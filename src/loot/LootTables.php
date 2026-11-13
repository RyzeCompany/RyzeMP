<?php

declare(strict_types=1);

namespace pocketmine\loot;

use pocketmine\world\generator\vanilla\feature\BlockEntityData;
use function array_key_exists;
use function preg_match;

/**
 * Java loot tables compiled from the same pinned cache as the generated world.
 */
final class LootTables{
	private readonly LootData $data;
	private readonly LootCompiler $compiler;
	/** @var array<string, LootTable|null> */
	private array $tables = [];
	/** @var array<string, true> */
	private array $warnings = [];

	public function __construct(string $root){
		$this->data = new LootData($root);
		$this->compiler = new LootCompiler($this->data, $this->get(...), function(string $key, string $message) : void{
			if(!isset($this->warnings[$key])){
				$this->warnings[$key] = true;
				\GlobalLogger::get()->debug("[Worldgen loot] $message");
			}
		});
	}

	public function get(string $name) : ?LootTable{
		$id = BlockEntityData::toJavaLootId($name);
		if(preg_match('~^minecraft:[a-z0-9_-]+(?:/[a-z0-9_-]+)*$~D', $id) !== 1){
			return null;
		}
		if(array_key_exists($id, $this->tables)){
			return $this->tables[$id];
		}
		$data = $this->data->lootTableJson($id);
		return $this->tables[$id] = $data !== null ? $this->compiler->table($id, $data) : null;
	}
}
