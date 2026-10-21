<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\JwtUtils;
use pocketmine\network\mcpe\protocol\ProtocolInfo;

final class ProcessLegacyLoginTaskTest extends TestCase{
	private static function key() : \OpenSSLAsymmetricKey{
		$key = openssl_pkey_new(['ec' => ['curve_name' => JwtUtils::BEDROCK_SIGNING_KEY_CURVE_NAME]]);
		self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
		return $key;
	}

	private static function certificate(\OpenSSLAsymmetricKey $signer, \OpenSSLAsymmetricKey $next, int $expiry = 300) : string{
		return JwtUtils::create(
			['alg' => 'ES384', 'x5u' => base64_encode(JwtUtils::emitDerPublicKey($signer))],
			['nbf' => time() - 600, 'exp' => time() + $expiry, 'identityPublicKey' => base64_encode(JwtUtils::emitDerPublicKey($next))],
			$signer
		);
	}

	/** @return array<string, array{bool, bool}> */
	public static function trustedChainForms() : array{
		return [
			'root first' => [false, false],
			'root after client certificate' => [true, false],
			'rotated root first' => [false, true],
			'rotated root after client certificate' => [true, true]
		];
	}

	/** @param string[] $chain
	 * @return array{bool, bool, mixed, ?string, string}
	 */
	private function verify(array $chain, string $clientData, ?string $root, ?string $alternate = null) : array{
		$result = null;
		$diagnostic = '';
		$task = new ProcessLegacyLoginTask($chain, $clientData, $root, true,
			static function(bool $authenticated, bool $required, mixed $error, ?string $clientKey) use (&$result) : void{
				$result = [$authenticated, $required, $error, $clientKey];
			},
			alternateRootAuthKeyDer: $alternate,
			onDiagnostic: static function(string $message) use (&$diagnostic) : void{ $diagnostic = $message; }
		);
		$task->onRun();
		$task->onCompletion();
		self::assertNotNull($result);
		return [...$result, $diagnostic];
	}

	#[DataProvider('trustedChainForms')]
	public function testVerifiedRootIsRecognizedInEachChainPosition(bool $prependClient, bool $alternate) : void{
		$root = self::key();
		$client = self::key();
		$rootDer = JwtUtils::emitDerPublicKey($root);
		$chain = $prependClient ? [self::certificate($client, $root)] : [];
		$chain[] = self::certificate($root, $client);
		[$authenticated, $required, $error, $clientKey, $diagnostic] = $this->verify(
			$chain, self::certificate($client, $client), $alternate ? null : $rootDer, $alternate ? $rootDer : null
		);
		self::assertTrue($authenticated);
		self::assertTrue($required);
		self::assertNull($error);
		self::assertSame(JwtUtils::emitDerPublicKey($client), $clientKey);
		self::assertStringContainsString('verification=passed result=trusted trusted-root=' . hash('sha256', $rootDer), $diagnostic);
	}

	public function testSelfSignedClientIsNotAuthenticatedEvenWhenItClaimsAnXuid() : void{
		$client = self::key();
		$token = JwtUtils::create(
			['alg' => 'ES384', 'x5u' => base64_encode(JwtUtils::emitDerPublicKey($client))],
			['identityPublicKey' => base64_encode(JwtUtils::emitDerPublicKey($client)), 'extraData' => ['XUID' => '123456789']],
			$client
		);
		[$authenticated, $required, $error, , $diagnostic] = $this->verify([$token], self::certificate($client, $client), JwtUtils::emitDerPublicKey(self::key()));
		self::assertFalse($authenticated);
		self::assertTrue($required);
		self::assertNull($error);
		self::assertStringContainsString('verification=passed result=untrusted trusted-root=none', $diagnostic);
		self::assertStringContainsString('single-self-issued=yes', $diagnostic);
		self::assertStringNotContainsString($token, $diagnostic);
		self::assertStringNotContainsString(base64_encode(JwtUtils::emitDerPublicKey($client)), $diagnostic);
	}

	public function testMentioningTrustedRootWithoutItsSignatureDoesNotAuthenticate() : void{
		$root = self::key();
		$client = self::key();
		[$authenticated, , $error] = $this->verify([self::certificate($client, $root)], self::certificate($client, $client), JwtUtils::emitDerPublicKey($root));
		self::assertFalse($authenticated);
		self::assertNotNull($error);
	}

	public function testForgedRootHeaderIsRejected() : void{
		$root = self::key();
		$client = self::key();
		$forged = JwtUtils::create(
			['alg' => 'ES384', 'x5u' => base64_encode(JwtUtils::emitDerPublicKey($root))],
			['identityPublicKey' => base64_encode(JwtUtils::emitDerPublicKey($client))], $client
		);
		[$authenticated, , $error, , $diagnostic] = $this->verify([$forged], self::certificate($client, $client), JwtUtils::emitDerPublicKey($root));
		self::assertFalse($authenticated);
		self::assertNotNull($error);
		self::assertStringContainsString('verification=failed result=invalid trusted-root=none', $diagnostic);
	}

	public function testExpiredTrustedCertificateIsRejected() : void{
		$root = self::key();
		$client = self::key();
		[$authenticated, , $error] = $this->verify([self::certificate($root, $client, -300)], self::certificate($client, $client), JwtUtils::emitDerPublicKey($root));
		self::assertFalse($authenticated);
		self::assertNotNull($error);
	}

	public function testClientDataMustBeSignedByFinalChainKey() : void{
		$root = self::key();
		$client = self::key();
		[$authenticated, , $error] = $this->verify([self::certificate($root, $client)], self::certificate($root, $root), JwtUtils::emitDerPublicKey($root));
		self::assertFalse($authenticated);
		self::assertNotNull($error);
	}

	public function testSingleUntrustedDelegationIsNotReportedAsSelfIssued() : void{
		$signer = self::key();
		$client = self::key();
		[$authenticated, $required, $error, , $diagnostic] = $this->verify(
			[self::certificate($signer, $client)], self::certificate($client, $client), JwtUtils::emitDerPublicKey(self::key())
		);
		self::assertFalse($authenticated);
		self::assertTrue($required);
		self::assertNull($error);
		self::assertStringContainsString('result=untrusted trusted-root=none', $diagnostic);
		self::assertStringContainsString('first-signer=' . hash('sha256', JwtUtils::emitDerPublicKey($signer)), $diagnostic);
		self::assertStringContainsString('single-self-issued=no', $diagnostic);
	}

	public function testRootMatchCannotAuthenticateAfterABrokenChainLink() : void{
		$root = self::key();
		$client = self::key();
		$unrelated = self::key();
		[$authenticated, $required, $error, , $diagnostic] = $this->verify(
			[self::certificate($root, $client), self::certificate($unrelated, $client)],
			self::certificate($client, $client), JwtUtils::emitDerPublicKey($root)
		);
		self::assertFalse($authenticated);
		self::assertTrue($required);
		self::assertNotNull($error);
		self::assertStringContainsString('verification=failed result=invalid', $diagnostic);
	}

	public function test419RotationDoesNotChangeOtherProtocolTrustPolicies() : void{
		self::assertSame(base64_decode(ProcessLegacyLoginTask::MOJANG_ROOT_PUBLIC_KEY_1_16_100, true), ProcessLegacyLoginTask::rootKeyForProtocol(419));
		self::assertSame(base64_decode(ProcessLegacyLoginTask::LEGACY_MOJANG_ROOT_PUBLIC_KEY, true), ProcessLegacyLoginTask::alternateRootKeyForProtocol(419));
		self::assertSame(base64_decode(ProcessLegacyLoginTask::LEGACY_MOJANG_ROOT_PUBLIC_KEY, true), ProcessLegacyLoginTask::rootKeyForProtocol(ProtocolInfo::PROTOCOL_1_20_0));
		self::assertNull(ProcessLegacyLoginTask::alternateRootKeyForProtocol(ProtocolInfo::PROTOCOL_1_20_0));
		self::assertNull(ProcessLegacyLoginTask::rootKeyForProtocol(ProtocolInfo::PROTOCOL_1_21_93));
		self::assertNull(ProcessLegacyLoginTask::alternateRootKeyForProtocol(ProtocolInfo::CURRENT_PROTOCOL));
	}
}
