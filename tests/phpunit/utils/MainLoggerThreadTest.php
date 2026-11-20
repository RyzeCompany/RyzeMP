<?php

declare(strict_types=1);

namespace pocketmine\utils;

use PHPUnit\Framework\TestCase;

final class MainLoggerThreadTest extends TestCase{
    private string $directory;

    protected function setUp() : void{
        $this->directory = sys_get_temp_dir() . "/ryzemp-log-" . bin2hex(random_bytes(8));
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

    public function testArchivesPreviousSession() : void{
        $log = $this->directory . "/server.log";
        file_put_contents($log, "previous session\n");
        new MainLoggerThread($log, $this->directory . "/log_archive");
        self::assertSame("", file_get_contents($log));
        $archives = glob($this->directory . "/log_archive/server.*.log");
        self::assertCount(1, $archives);
        self::assertSame("previous session\n", file_get_contents($archives[0]));
    }

    public function testEmptyLogNotArchived() : void{
        $log = $this->directory . "/server.log";
        touch($log);
        new MainLoggerThread($log, $this->directory . "/log_archive");
        self::assertSame([], glob($this->directory . "/log_archive/server.*.log"));
    }

    public function testRepeatedStartsPreserveAllArchives() : void{
        $log = $this->directory . "/server.log";
        file_put_contents($log, "first\n");
        new MainLoggerThread($log, $this->directory . "/log_archive");
        file_put_contents($log, "second\n");
        new MainLoggerThread($log, $this->directory . "/log_archive");
        $archives = glob($this->directory . "/log_archive/server.*.log");
        self::assertCount(2, $archives);
        self::assertEqualsCanonicalizing(["first\n", "second\n"], array_map(file_get_contents(...), $archives));
    }

    public function testArchiveFailureDoesNotDestroyOldLog() : void{
        $log = $this->directory . "/server.log";
        file_put_contents($log, "critical old data\n");
        file_put_contents($this->directory . "/log_archive", "not a directory");
        try{
            new MainLoggerThread($log, $this->directory . "/log_archive");
            self::fail("Expected archive failure");
        }catch(\RuntimeException $e){
            self::assertSame("critical old data\n", file_get_contents($log));
        }
    }
}
