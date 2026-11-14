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

use function explode;
use function str_replace;
use function strtolower;

/**
 * Maps Bedrock client locales (e.g. "es_MX", "pt_BR") to PocketMine-MP language codes (e.g. "spa").
 */
final class ClientLocaleMap{

	private function __construct(){
		//NOOP
	}

	/**
	 * Exact locale matches, checked before the language-only map.
	 * @phpstan-var array<string, string>
	 */
	private const LOCALES = [
		"zh_cn" => "chs",
		"zh_hans" => "chs",
	];

	/**
	 * ISO 639-1 language => PocketMine-MP language code.
	 * @phpstan-var array<string, string>
	 */
	private const LANGUAGES = [
		"bg" => "bul",
		"zh" => "chs",
		"de" => "deu",
		"en" => "eng",
		"fr" => "fra",
		"it" => "ita",
		"ja" => "jpn",
		"ko" => "kor",
		"lv" => "lav",
		"nl" => "nld",
		"ru" => "rus",
		"es" => "spa",
		"tr" => "tur",
		"vi" => "vie",
	];

	/**
	 * Returns the PocketMine-MP language code for the given client locale, or null if there is no mapping.
	 * The returned language is not guaranteed to have a translation file installed.
	 */
	public static function toLanguageCode(string $locale) : ?string{
		$locale = strtolower(str_replace("-", "_", $locale));
		if(isset(self::LOCALES[$locale])){
			return self::LOCALES[$locale];
		}
		return self::LANGUAGES[explode("_", $locale, 2)[0]] ?? null;
	}
}
