<?php

declare(strict_types=1);

namespace pocketmine\world;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class GameRulesTest extends TestCase{
	private string $directory;
	protected function setUp() : void{
		$this->directory = sys_get_temp_dir() . "/ryzemp-rules-" . bin2hex(random_bytes(12));
		mkdir($this->directory);
	}
	protected function tearDown() : void{ (new Filesystem())->remove($this->directory); }

	public function testDefaultsAndImportedWorldDefault() : void{
		self::assertFalse((new GameRules($this->directory . "/world.yml"))->getBool("showcoordinates"));
		self::assertTrue((new GameRules($this->directory . "/nether.yml", true))->getBool("showCoordinates"));
	}
	public function testPersistenceAndIndependentWorlds() : void{
		$first = new GameRules($this->directory . "/world.yml");
		$second = new GameRules($this->directory . "/nether.yml");
		$first->setBool("showCoordinates", true);
		self::assertTrue($first->getBool("showcoordinates"));
		self::assertFalse($second->getBool("showcoordinates"));
		self::assertTrue((new GameRules($this->directory . "/world.yml"))->getBool("showcoordinates"));
		$first->setBool("showcoordinates", false);
		self::assertFalse((new GameRules($this->directory . "/world.yml", true))->getBool("showcoordinates"));
	}
	public function testNetworkRulesPreserveServerConstraints() : void{
		$rules = new GameRules($this->directory . "/world.yml");
		$rules->setBool("showcoordinates", true);
		$network = $rules->toNetwork();
		self::assertTrue($network["showcoordinates"]->getValue());
		self::assertFalse($network["naturalregeneration"]->getValue());
		self::assertFalse($network["locatorbar"]->getValue());
	}
	public function testUnknownRuleRejected() : void{
		$this->expectException(\InvalidArgumentException::class);
		(new GameRules($this->directory . "/world.yml"))->setBool("doesnotexist", true);
	}
	public function testWrongStoredTypeRejected() : void{
		file_put_contents($this->directory . "/world.yml", "showcoordinates: 'false'\n");
		$this->expectException(\InvalidArgumentException::class);
		new GameRules($this->directory . "/world.yml");
	}
	public function testFailedSaveDoesNotChangeRunningValue() : void{
		$path = $this->directory . "/world.yml";
		$rules = new GameRules($path);
		mkdir($path);
		try{
			$rules->setBool("showcoordinates", true);
			self::fail("Invalid destination accepted");
		}catch(\RuntimeException){ self::assertFalse($rules->getBool("showcoordinates")); }
	}
}
