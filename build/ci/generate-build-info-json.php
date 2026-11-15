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

use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\VersionInfo;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if(count($argv) !== 6){
	fwrite(STDERR, "required args: <git hash> <tag name> <build number> <phar download URL> <PHP binary download URL>\n");
	exit(1);
}
[, $gitHash, $tag, $build, $downloadUrl, $phpUrl] = $argv;
$projectUrl = (string) getenv("CI_PROJECT_URL");
//public address of the release pages (the auto-updater prints it), e.g. https://updates.vanillamine.com/releases
$releasesUrl = getenv("RELEASES_PUBLIC_URL");
$detailsUrl = is_string($releasesUrl) && $releasesUrl !== "" ? "$releasesUrl/$tag" : "$projectUrl/-/releases/$tag";
$pipelineId = (string) getenv("CI_PIPELINE_ID");

echo json_encode([
	"php_version" => sprintf("%d.%d", PHP_MAJOR_VERSION, PHP_MINOR_VERSION), //deprecated
	"base_version" => VersionInfo::BASE_VERSION,
	"build" => (int) $build,
	"is_dev" => VersionInfo::IS_DEVELOPMENT_BUILD,
	"channel" => VersionInfo::BUILD_CHANNEL,
	"git_commit" => $gitHash,
	"mcpe_version" => ProtocolInfo::MINECRAFT_VERSION_NETWORK,
	"date" => time(),
	"details_url" => $detailsUrl,
	"download_url" => $downloadUrl,
	"source_url" => "$projectUrl/-/tree/$tag",
	"build_log_url" => "$projectUrl/-/pipelines/$pipelineId",
	"php_download_url" => $phpUrl,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
