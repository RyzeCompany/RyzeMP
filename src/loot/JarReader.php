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

namespace pocketmine\loot;

use function class_exists;
use function fclose;
use function fopen;
use function is_resource;
use function realpath;
use function str_replace;
use function stream_copy_to_stream;
use function stream_get_contents;
use function strlen;
use function substr;

/**
 * Read-only access to the entries of a jar (zip) file: the zip extension when it is loaded, otherwise Phar (always
 * there, PocketMine-MP needs it), which reads zip archives too.
 * @internal
 */
final class JarReader{
	private ?\ZipArchive $zip = null;
	private ?string $pharRoot = null;

	public function __construct(
		private string $path,
		bool $allowZipExtension = true
	){
		if($allowZipExtension && class_exists(\ZipArchive::class)){
			$zip = new \ZipArchive();
			$result = $zip->open($path, \ZipArchive::RDONLY);
			if($result !== true){
				throw new \RuntimeException("Cannot open $path as a zip file (error $result)");
			}
			$this->zip = $zip;
		}else{
			$real = realpath($path);
			if($real === false){
				throw new \RuntimeException("$path does not exist");
			}
			try{
				new \PharData($real); //checks the archive
			}catch(\UnexpectedValueException $e){
				throw new \RuntimeException("Cannot open $path as a zip file: " . $e->getMessage(), 0, $e);
			}
			$this->pharRoot = "phar://" . str_replace("\\", "/", $real);
		}
	}

	/**
	 * Names of the file entries ("data/minecraft/loot_table/chests/simple_dungeon.json"); directories are skipped.
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getNames() : array{
		$names = [];
		if($this->zip !== null){
			for($i = 0, $count = $this->zip->numFiles; $i < $count; $i++){
				$name = $this->zip->getNameIndex($i);
				if($name !== false && $name !== "" && $name[strlen($name) - 1] !== "/"){
					$names[] = $name;
				}
			}
			return $names;
		}
		$prefixLength = strlen((string) $this->pharRoot) + 1;
		$iterator = new \RecursiveIteratorIterator(new \PharData((string) $this->pharRoot), \RecursiveIteratorIterator::LEAVES_ONLY);
		foreach($iterator as $entry){
			if($entry instanceof \SplFileInfo && $entry->isFile()){
				$names[] = substr(str_replace("\\", "/", $entry->getPathname()), $prefixLength);
			}
		}
		return $names;
	}

	/**
	 * @return resource
	 */
	private function open(string $name){
		$stream = $this->zip !== null ? $this->zip->getStream($name) : @fopen($this->pharRoot . "/" . $name, "rb");
		if(!is_resource($stream)){
			throw new \RuntimeException("Entry $name not found in $this->path");
		}
		return $stream;
	}

	public function read(string $name) : string{
		$stream = $this->open($name);
		try{
			$contents = stream_get_contents($stream);
		}finally{
			fclose($stream);
		}
		if($contents === false){
			throw new \RuntimeException("Failed to read $name from $this->path");
		}
		return $contents;
	}

	/**
	 * Streams an entry to a file (for large entries, such as a jar inside a jar).
	 */
	public function extractTo(string $name, string $target) : void{
		$stream = $this->open($name);
		try{
			$file = fopen($target, "wb");
			if($file === false){
				throw new \RuntimeException("Cannot write $target");
			}
			try{
				if(stream_copy_to_stream($stream, $file) === false){
					throw new \RuntimeException("Failed to extract $name from $this->path");
				}
			}finally{
				fclose($file);
			}
		}finally{
			fclose($stream);
		}
	}

	public function close() : void{
		$this->zip?->close();
		$this->zip = null;
	}
}
