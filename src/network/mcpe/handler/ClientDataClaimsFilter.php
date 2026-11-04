<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\handler;

/**
 * Only filters client-data claims that are not part of the skin/profile DTO.
 * The original signed JWT is preserved for the authentication verification.
 */
final class ClientDataClaimsFilter{
	/**
	 * @param array<string, mixed> $claims
	 * @return array<string, mixed>
	 */
	public static function forSkinMapping(array $claims) : array{
		// Some modern Bedrock clients add this claim, but ClientData has no matching property.
		unset($claims["Nonce"]);
		return $claims;
	}
}
