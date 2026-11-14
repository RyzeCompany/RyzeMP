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

namespace pocketmine\lang;

use PHPUnit\Framework\TestCase;
use function is_file;

class ClientLocaleMapTest extends TestCase{

	public function testMapping() : void{
		self::assertSame("spa", ClientLocaleMap::toLanguageCode("es_ES"));
		self::assertSame("spa", ClientLocaleMap::toLanguageCode("es_MX"));
		self::assertSame("eng", ClientLocaleMap::toLanguageCode("en_GB"));
		self::assertSame("chs", ClientLocaleMap::toLanguageCode("zh_CN"));
		self::assertSame("deu", ClientLocaleMap::toLanguageCode("de-DE"));
		self::assertNull(ClientLocaleMap::toLanguageCode("pt_BR"));
		self::assertNull(ClientLocaleMap::toLanguageCode(""));
	}

	public function testMappedLanguagesExist() : void{
		foreach(["bg_BG", "zh_CN", "de_DE", "en_US", "fr_FR", "it_IT", "ja_JP", "ko_KR", "lv_LV", "nl_NL", "ru_RU", "es_ES", "tr_TR", "vi_VN"] as $locale){
			$code = ClientLocaleMap::toLanguageCode($locale);
			self::assertNotNull($code, $locale);
			self::assertTrue(is_file(\pocketmine\LOCALE_DATA_PATH . $code . ".ini"), "Missing translation for $locale ($code)");
		}
	}
}
