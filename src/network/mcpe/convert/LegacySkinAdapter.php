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

namespace pocketmine\network\mcpe\convert;

use pocketmine\entity\InvalidSkinException;
use pocketmine\entity\Skin;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use pocketmine\network\mcpe\protocol\types\skin\SkinImage;
use function is_array;
use function is_string;
use function intdiv;
use function json_decode;
use function json_encode;
use function str_repeat;
use function substr;
use const JSON_THROW_ON_ERROR;

class LegacySkinAdapter implements ProtocolAwareSkinAdapter{

	/** @phpstan-var \WeakMap<Skin, SkinData> */
	private static ?\WeakMap $personaSkins = null;
	/** @phpstan-var \WeakMap<Skin, int> */
	private static ?\WeakMap $personaSourceProtocols = null;

	public function __construct(){
		self::$personaSkins ??= new \WeakMap();
		self::$personaSourceProtocols ??= new \WeakMap();
	}

	public function toSkinData(Skin $skin) : SkinData{
		$persona = self::$personaSkins[$skin] ?? null;
		if($persona !== null){
			return $persona;
		}
		return $this->toClassicSkinData($skin, true);
	}

	private static function getPersonaCompatibilityFamily(int $protocolId) : int{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_50){
			return 2; // 2193+: 1.26.50/51/52
		}
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			return 1; // 2168/2169: 1.26.40/44/45
		}
		return 0; // Legacy Persona wire format (1.26.30 and older)
	}

	public function toSkinDataForProtocol(Skin $skin, int $targetProtocol) : SkinData{
		$persona = self::$personaSkins[$skin] ?? null;
		if($persona !== null){
			$sourceProtocol = self::$personaSourceProtocols[$skin] ?? null;
			if(
				$sourceProtocol !== null &&
				self::getPersonaCompatibilityFamily($sourceProtocol) !== self::getPersonaCompatibilityFamily($targetProtocol)
			){
				return $this->toClassicSkinData($skin, false);
			}
			return $persona;
		}

		// Respect plugin overrides of the legacy method for non-Persona skins.
		return $this->toSkinData($skin);
	}

	private function toClassicSkinData(Skin $skin, bool $trusted) : SkinData{
		$capeData = $skin->getCapeData();
		$capeImage = $capeData === "" ? new SkinImage(0, 0, "") : new SkinImage(32, 64, $capeData);
		$geometryName = $skin->getGeometryName();
		if($geometryName === ""){
			$geometryName = "geometry.humanoid.custom";
		}
		$geometryData = $skin->getGeometryData();
		if($geometryData === ""){
			$geometryData = SkinData::GEOMETRY_DATA_NONE;
		}
		return new SkinData(
			$skin->getSkinId(),
			"", //TODO: playfab ID
			json_encode(["geometry" => ["default" => $geometryName]], JSON_THROW_ON_ERROR),
			SkinImage::fromLegacy($skin->getSkinData()), [],
			$capeImage,
			$geometryData,
			isVerified: $trusted,
			trustedSkinFlag: $trusted ? "true" : "false",
			profileHash: ""
		);
	}

	private static function normalizeSkinImage(SkinImage $image) : string{
		$width = $image->getWidth();
		$height = $image->getHeight();
		$data = $image->getData();
		if($width !== $height || ($width !== 254 && $width !== 256)){
			return $data;
		}

		$normalized = "";
		for($y = 0; $y < 128; ++$y){
			$sourceY = intdiv($y * $height, 128);
			for($x = 0; $x < 128; ++$x){
				$sourceX = intdiv($x * $width, 128);
				$normalized .= substr($data, ($sourceY * $width + $sourceX) * 4, 4);
			}
		}
		return $normalized;
	}

	private static function personaToClassicSkin(SkinData $data) : Skin{
		$skinData = self::normalizeSkinImage($data->getSkinImage());
		try{
			return new Skin(
				"Standard_Custom",
				$skinData,
				"",
				"geometry.humanoid.custom",
				SkinData::GEOMETRY_DATA_NONE
			);
		}catch(InvalidSkinException){
			// Keep a deterministic safe fallback for unexpected Persona image sizes.
			return new Skin(
				"Standard_Custom",
				str_repeat("\x7f\x7f\x7f\xff", 64 * 64),
				"",
				"geometry.humanoid.custom",
				SkinData::GEOMETRY_DATA_NONE
			);
		}
	}

	public function fromSkinData(SkinData $data) : Skin{
		if($data->isPersona()){
			$skin = self::personaToClassicSkin($data);
			self::$personaSkins[$skin] = $data;
			return $skin;
		}

		$capeData = $data->isPersonaCapeOnClassic() ? "" : $data->getCapeImage()->getData();

		$resourcePatch = json_decode($data->getResourcePatch(), true);
		if(is_array($resourcePatch) && isset($resourcePatch["geometry"]["default"]) && is_string($resourcePatch["geometry"]["default"])){
			$geometryName = $resourcePatch["geometry"]["default"];
		}else{
			throw new InvalidSkinException("Missing geometry name field");
		}

		return new Skin($data->getSkinId(), self::normalizeSkinImage($data->getSkinImage()), $capeData, $geometryName, $data->getGeometryData());
	}
	public function fromSkinDataForProtocol(SkinData $data, int $sourceProtocol) : Skin{
		$skin = $this->fromSkinData($data);
		if($data->isPersona()){
			self::$personaSkins[$skin] = $data;
			self::$personaSourceProtocols[$skin] = $sourceProtocol;
		}
		return $skin;
	}

}
