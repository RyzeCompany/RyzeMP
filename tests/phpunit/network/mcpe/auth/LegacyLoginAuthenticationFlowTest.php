<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\handler\LegacySessionBootstrap;
use pocketmine\network\mcpe\JwtUtils;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\types\login\legacy\LegacyAuthChain;

/** Transport-to-verifier regression for the one-certificate login reported by a real Android client. */
final class LegacyLoginAuthenticationFlowTest extends TestCase{
	private static function key() : \OpenSSLAsymmetricKey{
		$key = openssl_pkey_new(['ec' => ['curve_name' => JwtUtils::BEDROCK_SIGNING_KEY_CURVE_NAME]]);
		self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
		return $key;
	}

	private static function certificate(\OpenSSLAsymmetricKey $signer, \OpenSSLAsymmetricKey $next, bool $identity = false) : string{
		$claims = ['nbf' => time() - 10, 'exp' => time() + 300, 'identityPublicKey' => base64_encode(JwtUtils::emitDerPublicKey($next))];
		if($identity){
			$claims['extraData'] = ['displayName' => 'LegacyProbe', 'identity' => '00000000-0000-4000-8000-000000000419', 'XUID' => '123456789'];
		}
		return JwtUtils::create(['alg' => 'ES384', 'x5u' => base64_encode(JwtUtils::emitDerPublicKey($signer))], $claims, $signer);
	}

	/** Historical unsigned-varint framing, specified independently of LoginPacket::encode(). */
	private static function frame(string $data) : string{
		$length = strlen($data);
		$prefix = '';
		do{
			$byte = $length & 0x7f;
			$length >>= 7;
			$prefix .= chr($byte | ($length > 0 ? 0x80 : 0));
		}while($length > 0);
		return $prefix . $data;
	}

	/** @return array<string, array{bool}> */
	public static function certificateForms() : array{
		return ['three signed certificates' => [true], 'one self-issued certificate with claimed XUID' => [false]];
	}

	#[DataProvider('certificateForms')]
	public function testNativeLoginPreservesEveryCertificateAndRequiresTrustedSignature(bool $online) : void{
		// The test issuer's private key is generated here and is trusted only by this task instance.
		// Production roots and the server's authentication policy are never modified.
		$issuer = self::key();
		$intermediate = self::key();
		$client = self::key();
		$certificates = $online ? [
			self::certificate($client, $issuer),
			self::certificate($issuer, $intermediate),
			self::certificate($intermediate, $client, true)
		] : [self::certificate($client, $client, true)];
		$json = json_encode(['chain' => $certificates], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
		$clientData = JwtUtils::create(
			['alg' => 'ES384', 'x5u' => base64_encode(JwtUtils::emitDerPublicKey($client))],
			['GameVersion' => '1.16.100'], $client
		);
		$request = pack('V', strlen($json)) . $json . pack('V', strlen($clientData)) . $clientData;
		$wire = "\x01" . pack('N', 419) . self::frame($request);
		$compressed = zlib_encode(self::frame($wire), ZLIB_ENCODING_RAW);
		self::assertIsString($compressed);

		$login = LegacySessionBootstrap::decodeLogin($compressed, new ZlibCompressor(7, 256, 1024 * 1024));
		self::assertSame($json, $login->authInfoJson);
		self::assertSame($clientData, $login->clientDataJwt);
		$out = new ByteBufferWriter();
		$login->encode($out, 419);
		self::assertSame($wire, $out->getData());
		// NetworkSession re-encodes the preflight packet and decodes it in handleDataPacket().
		$reader = new ByteBufferReader($out->getData());
		$login = new LoginPacket();
		$login->decode($reader, 419);
		self::assertSame(0, $reader->getUnreadLength());
		self::assertSame($json, $login->authInfoJson);

		$mapper = new \JsonMapper();
		$mapper->bExceptionOnMissingData = true;
		$mapper->bStrictObjectTypeChecking = true;
		$mapper->bEnforceMapType = false;
		$chain = $mapper->map(json_decode($login->authInfoJson, flags: JSON_THROW_ON_ERROR), new LegacyAuthChain());
		self::assertSame($certificates, $chain->chain);
		self::assertCount($online ? 3 : 1, $chain->chain);

		$result = null;
		$task = new ProcessLegacyLoginTask($chain->chain, $login->clientDataJwt, JwtUtils::emitDerPublicKey($issuer), true,
			static function(bool $authenticated, bool $required, mixed $error, ?string $clientKey) use (&$result) : void{
				$result = [$authenticated, $required, $error, $clientKey];
			}
		);
		$task->onRun();
		$task->onCompletion();
		self::assertSame([$online, true, null, JwtUtils::emitDerPublicKey($client)], $result);
	}
}
