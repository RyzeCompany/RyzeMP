<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\portal;

use InvalidArgumentException;
use function strlen;
use function trim;

/** An immutable one-way link. Replacing it requires unregistering its stable ID first. */
final class PortalLink{

	public function __construct(
		public readonly string $id,
		public readonly PortalArea $source,
		public readonly PortalDestination $destination
	){
		if(trim($id) === "" || strlen($id) > 128){
			throw new InvalidArgumentException("Portal link ID must contain between 1 and 128 bytes");
		}
	}
}
