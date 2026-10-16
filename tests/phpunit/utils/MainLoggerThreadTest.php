<?php

/*
 * RyzeMP - log file lifecycle regression tests.
 *
 * This file is part of RyzeMP and is distributed under the terms of the
 * GNU Lesser General Public License, version 3 or later.
 */

declare(strict_types=1);

namespace pocketmine\utils;

use PHPUnit\Framework\TestCase;
use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function touch;
use function unlink;

final class MainLoggerThreadTest extends TestCase{

	private string $directory;

	protected function setUp() : void{
		$this->directory = sys_get_temp_dir() . "/ryzemp-logger-" . bin2hex(random_bytes(8));
		self::assertTrue(mkdir($this->directory));
	}

	protected function tearDown() : void{
		foreach(glob($this->directory . "/log_archive/*") ?: [] as $file){
			unlink($file);
		}
		if(is_dir($this->directory . "/log_archive")){
			rmdir($this->directory . "/log_archive");
		}elseif(is_file($this->directory . "/log_archive")){
			unlink($this->directory . "/log_archive");
		}
		if(is_file($this->directory . "/server.log")){
			unlink($this->directory . "/server.log");
		}
		rmdir($this->directory);
	}

	public function testArchivesPreviousRunAndStartsWithCleanLog() : void{
		$log = $this->directory . "/server.log";
		$archiveDir = $this->directory . "/log_archive";
		self::assertNotFalse(file_put_contents($log, "Server started\nPrior crash\n"));

		new MainLoggerThread($log, $archiveDir);

		self::assertSame("", file_get_contents($log));
		$archives = glob($archiveDir . "/server.*.log");
		self::assertIsArray($archives);
		self::assertCount(1, $archives);
		self::assertSame("Server started\nPrior crash\n", file_get_contents($archives[0]));
	}

	public function testEmptyPreviousLogIsNotArchived() : void{
		$log = $this->directory . "/server.log";
		$archiveDir = $this->directory . "/log_archive";
		self::assertTrue(touch($log));

		new MainLoggerThread($log, $archiveDir);

		self::assertSame("", file_get_contents($log));
		self::assertSame([], glob($archiveDir . "/server.*.log"));
	}

	public function testRepeatedStartupNeverOverwritesPreviousArchives() : void{
		$log = $this->directory . "/server.log";
		$archiveDir = $this->directory . "/log_archive";
		self::assertNotFalse(file_put_contents($log, "First run\n"));
		new MainLoggerThread($log, $archiveDir);

		self::assertNotFalse(file_put_contents($log, "Second run\n"));
		new MainLoggerThread($log, $archiveDir);

		$archives = glob($archiveDir . "/server.*.log");
		self::assertIsArray($archives);
		self::assertCount(2, $archives);
		$contents = [file_get_contents($archives[0]), file_get_contents($archives[1])];
		self::assertContains("First run\n", $contents);
		self::assertContains("Second run\n", $contents);
		self::assertSame("", file_get_contents($log));
	}

	public function testArchiveFailurePreservesOldLog() : void{
		$log = $this->directory . "/server.log";
		$archiveDir = $this->directory . "/log_archive";
		self::assertNotFalse(file_put_contents($log, "Unrecoverable crash details\n"));
		self::assertNotFalse(file_put_contents($archiveDir, "not a directory"));

		try{
			new MainLoggerThread($log, $archiveDir);
			self::fail("Logger must refuse to overwrite an unarchived prior run");
		}catch(\RuntimeException $e){
			self::assertSame("Unrecoverable crash details\n", file_get_contents($log));
			self::assertSame("not a directory", file_get_contents($archiveDir));
		}
	}
}
