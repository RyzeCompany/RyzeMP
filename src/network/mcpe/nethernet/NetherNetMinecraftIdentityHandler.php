<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

use amber\nethernet\TransportConfig;
use amber\nethernet\TransportException;
use amber\nethernet\identity\ClientIdentityVerifier;
use amber\nethernet\identity\IdentityHandler;
use amber\nethernet\identity\PeerIdentity;
use amber\nethernet\identity\SdpIdentity;
use amber\nethernet\identity\ServerIdentity;
use pocketmine\network\mcpe\auth\AuthJwtHelper;
use pocketmine\network\mcpe\auth\AuthKeyProvider;

/**
 * Trust Minecraft's official, asynchronously refreshed auth keys.
 *
 * Never fetch token keys from the client-provided SDP, nor blindly trust its
 * public key. AuthKeyProvider already obtains Microsoft's/Mojang's signed
 * keyset from the first-party OpenID configuration and caches the result.
 *
 * @internal
 */
final class NetherNetMinecraftIdentityHandler implements IdentityHandler{
	public function __construct(
		private AuthKeyProvider $keys,
		private ServerIdentity $serverIdentity,
		private TransportConfig $config
	){}

	/**
	 * @param \Closure(?PeerIdentity, ?TransportException): void $complete
	 * @return \Closure(): void
	 */
	public function verifyClient(string $token, string $canonicalFingerprints, string $detachedJws, \Closure $complete) : \Closure{
		$parts = explode(".", $token);
		$header = count($parts) === 3 && strlen($parts[0]) <= 1024 ?
			base64_decode(strtr($parts[0], "-_", "+/"), true) : false;
		$decoded = $header === false ? null : json_decode($header, true, 8);
		$kid = is_array($decoded) ? ($decoded["kid"] ?? null) : null;
		if(!is_string($kid) || strlen($kid) < 1 || strlen($kid) > 256 ||
			preg_match('/\A[A-Za-z0-9_.:-]+\z/D', $kid) !== 1){
			$complete(null, new TransportException("identity_invalid", "Missing or invalid Minecraft token key ID"));
			return static function() : void{};
		}
		$cancelled = false;
		$this->keys->getKey($kid)->onCompletion(
			function(array $issuerAndDer) use ($kid, $token, $canonicalFingerprints, $detachedJws, $complete, &$cancelled) : void{
				if($cancelled){ return; }
				try{
					[$issuer, $der] = $issuerAndDer;
					$pem = "-----BEGIN PUBLIC KEY-----\n" .
						chunk_split(base64_encode($der), 64, "\n") .
						"-----END PUBLIC KEY-----\n";
					$key = openssl_pkey_get_public($pem);
					if($key === false){
						throw new TransportException("identity_untrusted", "Invalid official Minecraft signing key");
					}
					$verifier = new ClientIdentityVerifier(
						static fn(?string $requestedKid) => $requestedKid === $kid ? $key : null,
						$issuer,
						AuthJwtHelper::MOJANG_AUDIENCE,
						60
					);
					$verified = $verifier->verify(
						SdpIdentity::fromAssertion($token, $canonicalFingerprints, $detachedJws, $this->config),
						time()
					);
					$complete($verified, null);
				}catch(\Throwable $error){
					$complete(null, $error instanceof TransportException ? $error :
						new TransportException("identity_verification_failed", "Minecraft identity validation failed", $error));
				}
			},
			function() use ($complete, &$cancelled) : void{
				if(!$cancelled){
					$complete(null, new TransportException("identity_untrusted", "Minecraft signing key unavailable"));
				}
			}
		);
		return static function() use (&$cancelled) : void{ $cancelled = true; };
	}

	public function signServer(string $canonicalFingerprints, int $now) : string{
		return $this->serverIdentity->signServer($canonicalFingerprints, $now);
	}
}
