<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use pocketmine\entity\Skin;
use pocketmine\network\mcpe\protocol\types\skin\SkinData;

/**
 * Optional extension for skin adapters which can preserve source protocol
 * information and adapt outgoing skins for a target protocol.
 *
 * Kept separate from SkinAdapter to preserve plugin API compatibility.
 */
interface ProtocolAwareSkinAdapter extends SkinAdapter{

	public function toSkinDataForProtocol(Skin $skin, int $targetProtocol) : SkinData;

	public function fromSkinDataForProtocol(SkinData $data, int $sourceProtocol) : Skin;
}
