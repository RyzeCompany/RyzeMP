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
use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use pocketmine\network\mcpe\protocol\types\skin\SkinImage;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function random_bytes;
use function str_repeat;
use function trim;
use const JSON_THROW_ON_ERROR;

class LegacySkinAdapter implements SkinAdapter{
	/**
	 * The skin data a client sent, by the Skin made from it. Skin keeps only the image, cape and geometry, so turning
	 * it back into network data lost the rest: persona skins became a random colour and the face animation (the
	 * blinking eyes) disappeared whenever such a skin was sent again (skin changes, NPCs wearing a player's skin).
	 * Skins are immutable, so the same object always stands for the same data.
	 *
	 * @phpstan-var \WeakMap<Skin, SkinData>|null
	 */
	private ?\WeakMap $networkSkins = null;

	public function toSkinData(Skin $skin) : SkinData{
		if($this->networkSkins !== null && isset($this->networkSkins[$skin])){
			return $this->networkSkins[$skin];
		}
		$capeData = $skin->getCapeData();
		$capeImage = $capeData === "" ? new SkinImage(0, 0, "") : new SkinImage(32, 64, $capeData);
		$geometryName = $skin->getGeometryName();
		if($geometryName === ""){
			$geometryName = "geometry.humanoid.custom";
		}
		$geometryData = $skin->getGeometryData();
		if(trim($geometryData) === ""){
			//1.26.40+ (Cereal): SerializedSkin::GeometryData must parse as JSON, and an empty string doesn't. The client
			//falls back to its built-in geometry for the name in the resource patch, exactly as it did with "".
			$geometryData = '{"format_version":"1.12.0","minecraft:geometry":[]}';
		}
		return new SkinData(
			$skin->getSkinId(),
			"", //TODO: playfab ID
			json_encode(["geometry" => ["default" => $geometryName]], JSON_THROW_ON_ERROR),
			SkinImage::fromLegacy($skin->getSkinData()), [],
			$capeImage,
			$geometryData
		);
	}

	public function fromSkinData(SkinData $data) : Skin{
		$skin = $this->convertSkinData($data);
		//initialised here rather than in a constructor, which subclasses written for 5.44.3 don't call
		$this->networkSkins ??= new \WeakMap();
		$this->networkSkins[$skin] = $data;
		return $skin;
	}

	private function convertSkinData(SkinData $data) : Skin{
		if($data->isPersona()){
			return new Skin("Standard_Custom", str_repeat(random_bytes(3) . "\xff", 4096));
		}

		$capeData = $data->isPersonaCapeOnClassic() ? "" : $data->getCapeImage()->getData();

		$resourcePatch = json_decode($data->getResourcePatch(), true);
		if(is_array($resourcePatch) && isset($resourcePatch["geometry"]["default"]) && is_string($resourcePatch["geometry"]["default"])){
			$geometryName = $resourcePatch["geometry"]["default"];
		}else{
			throw new InvalidSkinException("Missing geometry name field");
		}

		return new Skin($data->getSkinId(), $data->getSkinImage()->getData(), $capeData, $geometryName, $data->getGeometryData());
	}
}
