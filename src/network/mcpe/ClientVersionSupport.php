<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use pocketmine\network\mcpe\protocol\ProtocolInfo;

/**
 * Client protocol IDs accepted by the server and their matching BedrockData palettes.
 * The snapshots without a suffix are the 1.26.40 / 1.26.45 baseline.
 * Older snapshots follow the previously supported RyzeMP beta.5 mappings.
 *
 * @internal
 */
final class ClientVersionSupport{
	/** @var array<int, array{string, string, string}> [display version, block suffix, item suffix] */
	private const DATA = [
		ProtocolInfo::PROTOCOL_1_20_0 => ["1.20.0", "-1.20.0", "-1.20.0"],
		ProtocolInfo::PROTOCOL_1_20_10 => ["1.20.10", "-1.20.10", "-1.20.10"],
		ProtocolInfo::PROTOCOL_1_20_30 => ["1.20.30", "-1.20.30", "-1.20.40"],
		ProtocolInfo::PROTOCOL_1_20_40 => ["1.20.40", "-1.20.40", "-1.20.40"],
		ProtocolInfo::PROTOCOL_1_20_50 => ["1.20.50", "-1.20.50", "-1.20.50"],
		ProtocolInfo::PROTOCOL_1_20_60 => ["1.20.60", "-1.20.60", "-1.20.60"],
		ProtocolInfo::PROTOCOL_1_20_70 => ["1.20.70", "-1.20.70", "-1.20.70"],
		ProtocolInfo::PROTOCOL_1_20_80 => ["1.20.80", "-1.20.80", "-1.20.80"],
		ProtocolInfo::PROTOCOL_1_21_0 => ["1.21.0", "-1.21.2", "-1.21.2"],
		ProtocolInfo::PROTOCOL_1_21_2 => ["1.21.2", "-1.21.2", "-1.21.2"],
		ProtocolInfo::PROTOCOL_1_21_20 => ["1.21.20", "-1.21.20", "-1.21.20"],
		ProtocolInfo::PROTOCOL_1_21_30 => ["1.21.30", "-1.21.30", "-1.21.30"],
		ProtocolInfo::PROTOCOL_1_21_40 => ["1.21.40", "-1.21.40", "-1.21.40"],
		ProtocolInfo::PROTOCOL_1_21_50 => ["1.21.50", "-1.21.50", "-1.21.50"],
		ProtocolInfo::PROTOCOL_1_21_60 => ["1.21.60", "-1.21.60", "-1.21.60"],
		ProtocolInfo::PROTOCOL_1_21_70 => ["1.21.70", "-1.21.70", "-1.21.70"],
		ProtocolInfo::PROTOCOL_1_21_80 => ["1.21.80", "-1.21.93", "-1.21.80"],
		ProtocolInfo::PROTOCOL_1_21_90 => ["1.21.90", "-1.21.93", "-1.21.90"],
		ProtocolInfo::PROTOCOL_1_21_93 => ["1.21.93", "-1.21.93", "-1.21.93"],
		ProtocolInfo::PROTOCOL_1_21_100 => ["1.21.100", "-1.21.100", "-1.21.100"],
		ProtocolInfo::PROTOCOL_1_21_111 => ["1.21.111", "-1.26.0", "-1.21.111"],
		ProtocolInfo::PROTOCOL_1_21_120 => ["1.21.120", "-1.26.0", "-1.21.120"],
		ProtocolInfo::PROTOCOL_1_21_124 => ["1.21.124", "-1.26.0", "-1.21.120"],
		ProtocolInfo::PROTOCOL_1_21_130 => ["1.21.130", "-1.26.0", "-1.21.130"],
		ProtocolInfo::PROTOCOL_1_26_0 => ["1.26.0", "-1.26.0", "-1.26.0"],
		ProtocolInfo::PROTOCOL_1_26_10 => ["1.26.10", "-1.26.10", "-1.26.10"],
		ProtocolInfo::PROTOCOL_1_26_20 => ["1.26.20", "-1.26.20", "-1.26.20"],
		ProtocolInfo::PROTOCOL_1_26_30 => ["1.26.30", "-1.26.30", "-1.26.30"],
		ProtocolInfo::PROTOCOL_1_26_40 => ["1.26.40", "", ""],
		ProtocolInfo::PROTOCOL_1_26_45 => ["1.26.45", "", ""],
		ProtocolInfo::PROTOCOL_1_26_52 => ["1.26.52", "-1.26.50", "-1.26.51"],
		ProtocolInfo::PROTOCOL_1_26_60 => ["1.26.60", "-1.26.60", "-1.26.60"]
	];

	private function __construct(){}

	public static function isSupported(int $protocolId) : bool{
		return isset(self::DATA[$protocolId]);
	}

	/** @return array<int, string> protocol ID => representative Bedrock version */
	public static function getVersions() : array{
		$versions = [];
		foreach(self::DATA as $id => [$version]){
			$versions[$id] = $version;
		}
		return $versions;
	}

	/** @return array{string, string, string} */
	private static function getData(int $protocolId) : array{
		return self::DATA[$protocolId] ?? throw new \InvalidArgumentException("Unsupported Bedrock protocol $protocolId");
	}

	public static function getBlockPaletteSuffix(int $protocolId) : string{
		return self::getData($protocolId)[1];
	}

	public static function getItemPaletteSuffix(int $protocolId) : string{
		return self::getData($protocolId)[2];
	}

	public static function getBlockNetworkIdsFile(int $protocolId) : ?string{
		self::getData($protocolId);
		return match(true){
			$protocolId >= ProtocolInfo::PROTOCOL_1_26_60 => "block_network_ids-1.26.60.json",
			$protocolId >= ProtocolInfo::PROTOCOL_1_26_52 => "block_network_ids-1.26.50.json",
			default => null
		};
	}
}
