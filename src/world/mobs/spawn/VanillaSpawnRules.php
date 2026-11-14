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

namespace pocketmine\world\mobs\spawn;

use pocketmine\Server;
use pocketmine\utils\Filesystem;
use function count;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function defined;
use function file_put_contents;
use function getmypid;
use function hash;
use function hash_file;
use function is_dir;
use function is_file;
use function is_string;
use function ksort;
use function mkdir;
use function rename;
use function unlink;
use const CURLINFO_HTTP_CODE;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_SSL_OPTIONS;
use const CURLOPT_TIMEOUT;
use const CURLSSLOPT_NATIVE_CA;
use const PHP_OS_FAMILY;

/**
 * Mojang's official Bedrock spawn rules (behavior_pack/spawn_rules/*.json of https://github.com/Mojang/bedrock-samples),
 * downloaded on the server owner's machine the first time they are needed.
 *
 * The files are (c) Mojang AB and subject to the Minecraft EULA (the repository's LICENSE.md), so they are not shipped
 * with PocketMine-MP: like the vanilla worldgen data ({@link \pocketmine\world\generator\vanilla\VanillaDataFetcher}),
 * each server fetches them from Mojang's repository. The download is pinned to the commit of the release tag matching
 * the supported Bedrock version and every file is checked against its SHA-256 below, so the rules cannot change under
 * a running server. Update {@link TAG}, {@link COMMIT} and {@link FILES} together when moving to a new Bedrock version.
 *
 * When the files are not in the data folder, the table derived from them at build time ({@link BundledSpawnRules},
 * resources/vanilla_spawn_rules.json) is used, so nothing has to be downloaded; the download (and, until it is done,
 * {@link SpawnRules::builtin()}) is only the fallback for a missing or outdated bundled table.
 */
final class VanillaSpawnRules{
	public const TAG = "v1.26.50.4";
	public const COMMIT = "46ba6ea985fb5a92d79a9419198f10dda14c199d";
	public const BASE_URL = "https://raw.githubusercontent.com/Mojang/bedrock-samples/" . self::COMMIT . "/behavior_pack/spawn_rules/";
	public const DIRECTORY = "bedrock-spawn-rules";

	/**
	 * File name => SHA-256 of the file at {@link COMMIT}.
	 */
	public const FILES = [
		"armadillo.json" => "8595a294e9b7b3ed3e55798e10ad0540088cee08e1e69f2c0f5f64f88e5841a9",
		"axolotl.json" => "9f694b70bd39f208ac167955af84a64c1ded20e46f319bc06ee9fafcd7e097ea",
		"bat.json" => "5f9fc9dfb85eaa16210b21985a8183f0f92c60f31fc544d1d23bbfa01fa9ee16",
		"bee.json" => "ae571145c0d7c06b16a9b5bb215e0c20c5e8007d6a7fd77487a16b80dd325ff3",
		"blaze.json" => "a57499b34eb23ec4ce1738db85c6fa2f6305e8d0f94d5a4b3e7af3b32c3a480e",
		"bogged.json" => "7cc63dec09c786e602dfd94d99ab2ee8bba28bdb73aa5c2baa757bf4158952fb",
		"camel.json" => "3f1ce89c36537934b4f54abc26cc66cb2e05e35af72bdbbd0392112c3d7d9e4c",
		"camel_husk.json" => "090926a1ebf3fe8d0a84131175e887946a712350b33797719928228b46c95318",
		"cat.json" => "0239684ca877ab5601ed4c9a5c0b5c01b22c0e5d586b0645d18b44410372ede4",
		"cave_spider.json" => "5ea07c8bbf3483b10958ef55b9889c3e37577471ecd375abdaba6aa7a8735c2f",
		"chicken.json" => "a9eed5a90d7e5c7f51372983c1d7e106e49103f09f313a88cc9c27e97c76bf0d",
		"cod.json" => "789668a084b75ac315c902c8727fa2dedd21314367194cb571c8bb3619f49be6",
		"cow.json" => "37d92406840036f75f6b83e22cc8c532391ea4f7519ddd46d95e94eac4dd9884",
		"creeper.json" => "309d6785a0e53ddf74d24b9c8d07948b4b73a6cc5c45e461244e2b79e46983af",
		"dolphin.json" => "a9c68a038991acdde4ee9fa83453b64e0d90f711f552a6c384c11eb99d68ebff",
		"donkey.json" => "78606f1b4eded1ae8058d7f76b908c0c70dd6710938ba172e44afefbef0447cd",
		"drowned.json" => "7d69ee6659e38a891a71e3defc3bffc42db424d57a14a4e13d78dcb48840293e",
		"enderman.json" => "2a9ff53da9d91325f0ed448e79d173ef9a4c754a80a2d5ba59448755d15940fc",
		"fox.json" => "ab98fa6a4e68fa8f780655b02764c4dbacd6705dad3c57652a5804d685640030",
		"frog.json" => "287dfcd7bce18dd54851c0e572ee21ff631f7233fefc77f8971d0fedd68a0a01",
		"ghast.json" => "8dfb17952d03f00890d96c0550d82faab9f3cd97514639a0c875fb554028bebe",
		"glow_squid.json" => "4d4f1692b4332d20c880a8346d143a36e6fa2174532ea7943e420ce28d4921ba",
		"goat.json" => "bfe6ccff18a6780dec5bb915965938b03c6caf2265f89499ab2b844083b9fa2f",
		"guardian.json" => "229c6911b680ede5d0760f8d2490d3152df9a5d609958cbca910d12383afd2d2",
		"hoglin.json" => "f2bf6e7c0f5b227bb7abdbe3d2402d20af4526504ecc750de0a20ae3042accc8",
		"horse.json" => "dd90efe9b6963dada68293aef4432a67757b9bfe271bf48b01db35f4a28eafe0",
		"husk.json" => "34d7dfc45914e33a0307daef6ac9f33fa2953adfcbe71f53a6e86e5527bcd886",
		"llama.json" => "df27b677eeb62d930009335fc9a0a69bfdcc8bfb1125c98a03f9e7dffdada215",
		"magma_cube.json" => "dc28b0507af4891a8edab8455017b09987f096190dca671a3b7caed6538761f7",
		"mooshroom.json" => "5586603d005f6868b85b5aea500823e2bebbb3f6a4da056d5a104cf725f258dc",
		"nautilus.json" => "ddd8590046d0914983e8de0e46b66c5d90cec4fa26d041aa617d2748319e1ac4",
		"ocelot.json" => "aea232454654be0264281c6993a86b4001ebbc678c2b9112d839427ce1b7145e",
		"panda.json" => "c75a083a053245202482383fe037d1cef567aad371352ece41df2e753b75daaa",
		"parched.json" => "cd2942f5662956f492fd6c2d4b603b7f1acfba0e0323052573ef78ef0d0796ad",
		"parrot.json" => "aa414890f39d232432fd283542dda571a356beba893f705cd8db16ffb919f7da",
		"phantom.json" => "b42ef98766b79812a71a22ea464c57da55b9cf29e9478eb1f802c58cdd043b83",
		"pig.json" => "17b0b7ba70f9bd74de0ba5b32b9ad6fa72c55f9ff5cc0958eba42be3a203f235",
		"piglin.json" => "e112714603085e89f85ea7ef3096f9074029825ad7c8385c6d55332994f46f96",
		"pillager.json" => "73bd2b4e55538522a93a34559931687ebcd29e11f33156b4bfc95cbd3285d5c5",
		"pillager_patrol.json" => "0bdec2b5c7174b955b9ae86f6f7b22b0d72ab0d0246e512a2d4ebf77b579b05f",
		"polar_bear.json" => "d6036eae4d73d16d155606dbfdc9ade0f9388a8f5d1de98a2dbd584069a65ca1",
		"pufferfish.json" => "a46b6d70b9eaf95b8007fc98cd18a7ad0514c954853cc33f0440642650e64a2f",
		"rabbit.json" => "a586ca043efc2960883dd905d4d3e797a0b08487ab52eb40d90330ac6e4889a4",
		"salmon.json" => "fb61ee402e5b2f8d3b8a8b10b21100369ac3bbd487cd547f4902a5754348ecf5",
		"sheep.json" => "c39d5b2f7a079ae3316495a5449e297c0e5c9aafc848c97ab3bd8d96e48d1a2d",
		"skeleton.json" => "5ba1ea88e9747137bac89ddafc31b52a75def011210c882dc6226e9488981a0b",
		"slime.json" => "851a9374f8783d99a1ef0fd65f6f009e94a8e7f315edfa2b30a5fa6f7d99cf9d",
		"spider.json" => "69d67d5ef043b3b16692c2b9d5821de0a78f3249c509df9a6cfd58e82d5bb09a",
		"squid.json" => "5492a120767c18656420abc6345edccc308a66c3df908414e37e929663d4b8e6",
		"stray.json" => "0119e7aeee57b8bd1ed9f6decc65f6143750f20aebfbe31c7eded75f11c25d1c",
		"strider.json" => "688815b16290b0f27f5209530834682e13a3c467890a9f2f6b2141a7a67f990c",
		"sulfur_cube.json" => "9953aff56531c723a42f94621bafeb5b1fae0a3d4a2e9c8505ba3e4a06913495",
		"tropicalfish.json" => "1294007af6393300b8a05c42580c2fd397825b947e0f92f386654842428a9b0c",
		"turtle.json" => "40a1cc36420bdec2e1b86f65eef43275e8f6f5b67a3dffd0a3abba49b0195672",
		"witch.json" => "5f53bbb97b9560ba0fdef159dc837c483a95da44cb8864f848b7e9d1ea635d99",
		"wither_skeleton.json" => "b73d8dd1506fb77f91fe699923012f08a47514792574549771667c4deada20a8",
		"wolf.json" => "a5fc522c99e0ed9e986049fcb01774945b2fe9b2e59386d17e612f2b7b5aa62b",
		"zombie.json" => "67ca9bae9d125c096cc52585a0f1d9911f2e1a25b581074d91d1564215be20cf",
		"zombie_horse.json" => "aeaad572a1c82d0003c49d2911b365c8d652b5d7f1d7e936642b3363a7240aac",
		"zombie_pigman.json" => "3c674dc17bdedf5c2010e53d3e26073e6b51e7a85a608d080fcd58b95ba8ca7b",
	];

	private static ?SpawnRules $rules = null;
	private static bool $fetching = false;
	private static ?string $lastError = null;

	private function __construct(){
		//NOOP
	}

	public static function getDirectory(string $dataPath) : string{
		return Filesystem::cleanPath($dataPath) . "/" . self::DIRECTORY . "/" . self::TAG;
	}

	/**
	 * Returns the vanilla rules, loading them from the cache directory if needed. If they are not there yet and
	 * $fetchIfMissing is set, starts downloading them in the background (once) and returns null.
	 */
	public static function get(Server $server, bool $fetchIfMissing = true) : ?SpawnRules{
		if(self::$rules !== null){
			return self::$rules;
		}
		if(self::$fetching){
			return null;
		}
		$dir = self::getDirectory($server->getDataPath());
		if(self::isComplete($dir)){
			if(self::$lastError !== null){
				return null;
			}
			try{
				self::$rules = self::loadFrom($dir);
				$server->getLogger()->debug("[Mob spawning] Loaded " . count(self::$rules->getConditions()) . " spawn conditions from " . self::$rules->getSource());
				return self::$rules;
			}catch(\InvalidArgumentException|\RuntimeException $e){
				self::$lastError = $e->getMessage();
				$server->getLogger()->warning("[Mob spawning] Could not read the vanilla spawn rules in $dir: " . $e->getMessage() . ". Using the built-in rules");
				return null;
			}
		}
		$bundled = BundledSpawnRules::get();
		if($bundled !== null){
			//same rules, derived from the pinned files at build time: nothing to download
			self::$rules = $bundled;
			$server->getLogger()->debug("[Mob spawning] Loaded " . count($bundled->getConditions()) . " spawn conditions from " . $bundled->getSource());
			return $bundled;
		}
		if($fetchIfMissing && self::$lastError === null){
			self::$fetching = true;
			$server->getLogger()->info("[Mob spawning] Downloading Bedrock's spawn rules (Mojang/bedrock-samples " . self::TAG . ") in the background; until then only the common Overworld mobs spawn");
			$server->getAsyncPool()->submitTask(new VanillaSpawnRulesFetchTask($dir));
		}
		return null;
	}

	/**
	 * Called on the main thread when a background download finished.
	 * @internal
	 */
	public static function onFetchCompleted(string $dir, ?string $error) : void{
		self::$fetching = false;
		$logger = Server::getInstance()->getLogger();
		if($error !== null){
			self::$lastError = $error;
			$logger->warning("[Mob spawning] Could not download Bedrock's spawn rules: $error. Using the built-in rules; put the files of " . self::BASE_URL . " in $dir and restart to use the vanilla ones");
			return;
		}
		$logger->info("[Mob spawning] Bedrock's spawn rules are ready (" . count(self::FILES) . " files in $dir)");
	}

	/**
	 * Returns whether every file is in the directory (checksums are checked when loading).
	 */
	public static function isComplete(string $dir) : bool{
		foreach(self::FILES as $file => $_){
			if(!is_file($dir . "/" . $file)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Reads and checks the files of a complete directory.
	 *
	 * @throws \InvalidArgumentException|\RuntimeException
	 */
	public static function loadFrom(string $dir) : SpawnRules{
		$conditions = [];
		$populations = [];
		$files = self::FILES;
		ksort($files);
		foreach($files as $file => $sha256){
			$json = Filesystem::fileGetContents($dir . "/" . $file);
			if(hash("sha256", $json) !== $sha256){
				throw new \RuntimeException("Checksum mismatch for $file (delete it to download it again)");
			}
			try{
				[$identifier, $population] = SpawnRuleParser::parseDescription($json);
				$populations[$identifier] = $population;
				foreach(SpawnRuleParser::parse($json) as $condition){
					$conditions[] = $condition;
				}
			}catch(\JsonException|\InvalidArgumentException $e){
				throw new \InvalidArgumentException("$file: " . $e->getMessage(), 0, $e);
			}
		}
		return new SpawnRules($conditions, $populations, "Mojang/bedrock-samples " . self::TAG);
	}

	/**
	 * Downloads the missing or damaged files into the directory. Runs on an async worker.
	 *
	 * @throws \RuntimeException
	 */
	public static function fetch(string $dir) : void{
		if(!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)){
			throw new \RuntimeException("Cannot create $dir");
		}
		foreach(self::FILES as $file => $sha256){
			$target = $dir . "/" . $file;
			if(is_file($target) && hash_file("sha256", $target) === $sha256){
				continue;
			}
			$body = self::download(self::BASE_URL . $file);
			if(hash("sha256", $body) !== $sha256){
				throw new \RuntimeException("Checksum mismatch for downloaded $file");
			}
			$tmp = $target . ".tmp" . getmypid();
			if(file_put_contents($tmp, $body) === false || !@rename($tmp, $target)){
				@unlink($tmp);
				throw new \RuntimeException("Cannot write $target");
			}
		}
	}

	private static function download(string $url) : string{
		$ch = curl_init($url);
		$options = [
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT => 60,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => ["User-Agent: PocketMine-MP"],
		];
		if(PHP_OS_FAMILY === "Windows" && defined("CURLSSLOPT_NATIVE_CA")){
			$options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
		}
		curl_setopt_array($ch, $options);
		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		if(!is_string($body) || $code !== 200){
			throw new \RuntimeException("Failed to download $url: " . ($error !== "" ? $error : "HTTP $code"));
		}
		return $body;
	}

	/**
	 * @internal for tests
	 */
	public static function reset() : void{
		self::$rules = null;
		self::$fetching = false;
		self::$lastError = null;
	}
}
