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

namespace pocketmine\world\generator\vanilla\structure\template;

use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\WorldgenData;
use function array_key_first;
use function array_key_last;
use function count;
use function file_exists;

/**
 * The structure templates of a data cache, loaded on demand and kept in a least-recently-used cache bounded by the
 * total number of template blocks (templates of big structures like ancient cities are tens of thousands of blocks),
 * so a generator worker stays within its memory limit. One instance per data directory and thread (see shared()).
 */
final class StructureTemplates{
	/** Default budget: about 10 MB of packed blocks (generator workers have a 256 MB memory limit). */
	public const DEFAULT_BLOCK_BUDGET = 600_000;

	/** @phpstan-var array<string, TemplateData> least recently used first */
	private array $templates = [];
	/** @phpstan-var array<string, array{int, int, int}> sizes survive eviction (needed for jigsaw bounding boxes) */
	private array $sizes = [];
	private int $blocks = 0;

	/** @phpstan-var array<string, self> */
	private static array $shared = [];

	public function __construct(
		private WorldgenData $data,
		private JavaBlocks $javaBlocks,
		private int $blockBudget = self::DEFAULT_BLOCK_BUDGET
	){}

	public static function shared(WorldgenData $data, JavaBlocks $blocks) : self{
		return self::$shared[$data->getRoot()] ??= new self($data, $blocks);
	}

	public function exists(string $id) : bool{
		return isset($this->templates[$id]) || isset($this->sizes[$id]) || file_exists($this->path($id));
	}

	private function path(string $id) : string{
		return $this->data->getRoot() . "/data/minecraft/structure/" . WorldgenData::stripNamespace($id) . ".nbt";
	}

	public function get(string $id) : TemplateData{
		$id = JavaBlocks::namespaced($id);
		$template = $this->templates[$id] ?? null;
		if($template !== null){
			//most recently used goes last (the jigsaw assembler asks for the same template over and over)
			if(array_key_last($this->templates) !== $id){
				unset($this->templates[$id]);
				$this->templates[$id] = $template;
			}
			return $template;
		}
		$path = $this->path($id);
		//like vanilla's StructureTemplateManager.getOrCreate(), a missing template is empty (some pools reference
		//templates the game does not have)
		$template = file_exists($path) ? TemplateData::load($this->javaBlocks, $id, $path) : new TemplateData($id, 0, 0, 0);
		$this->templates[$id] = $template;
		$this->sizes[$id] = [$template->sizeX, $template->sizeY, $template->sizeZ];
		$this->blocks += $template->blockCount;
		while($this->blocks > $this->blockBudget && count($this->templates) > 1){
			$oldest = array_key_first($this->templates);
			$this->blocks -= $this->templates[$oldest]->blockCount;
			unset($this->templates[$oldest]);
		}
		return $template;
	}

	/**
	 * @phpstan-return array{int, int, int}
	 */
	public function size(string $id) : array{
		$id = JavaBlocks::namespaced($id);
		return $this->sizes[$id] ?? (function() use ($id) : array{
			$template = $this->get($id);
			return [$template->sizeX, $template->sizeY, $template->sizeZ];
		})();
	}

	public function getLoadedBlockCount() : int{
		return $this->blocks;
	}
}
