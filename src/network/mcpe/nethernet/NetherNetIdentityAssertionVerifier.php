<?php
/*
 * This file is part of RyzeMP.
 * Copyright (C) RyzeCompany contributors.
 * SPDX-License-Identifier: LGPL-3.0-or-later
 */
declare(strict_types=1);
namespace pocketmine\network\mcpe\nethernet;

/**
 * Verifies the two independent NetherNet offer signatures:
 *
 * 1. Minecraft's authorization service signs the time-limited player JWT.
 * 2. The JWT's cpk (EC P-384) signs a detached JWS over the SDP fingerprints.
 *
 * Construct ONLY from the official HTTPS JWKS at:
 * https://authorization.franchise.minecraft-services.net/.well-known/keys
 * The caller must retrieve/cache those keys securely outside the server tick.
 * No self-signed, unsigned or test-only token bypass is supported.
 *
 * Verification does not prove that DTLS succeeded; the eventual native
 * connection MUST also bind the authenticated fingerprint and Bedrock login.
 * @internal
 */
final class NetherNetIdentityAssertionVerifier{
    private const ISSUER = 'https://authorization.franchise.minecraft-services.net/';
    private const AUDIENCE = 'api://auth-minecraft-services/multiplayer';
    private const MAX_SDP = 65536;
    private const MAX_IDENTITY = 65536;
    private const MAX_TOKEN = 32768;
    private const SKEW = 60;
    /** @var array<string, array{pem: string, kty: string}> */
    private array $keys;

    private function __construct(array $keys){
        if($keys === [] || count($keys) > 32){
            throw new \InvalidArgumentException('Missing or excessive Minecraft trusted JWT keys');
        }
        $this->keys = $keys;
    }

    /**
     * $jwksJson must come ONLY from the official HTTPS endpoint above, not a
     * client header, user SDP, untrusted cached response or custom server URL.
     */
    public static function fromOfficialJwks(string $jwksJson) : self{
        if(strlen($jwksJson) > 262144){
            throw new \InvalidArgumentException('Minecraft JWKS too large');
        }
        $doc = json_decode($jwksJson, true, 32, JSON_THROW_ON_ERROR);
        if(!is_array($doc) || !isset($doc['keys']) || !is_array($doc['keys'])){
            throw new \InvalidArgumentException('Invalid Minecraft JWKS');
        }
        $keys = [];
        foreach($doc['keys'] as $i => $jwk){
            if(!is_array($jwk) || !in_array($jwk['kty'] ?? null, ['RSA', 'EC'], true)){
                continue;
            }
            if(count($keys) >= 32){throw new \InvalidArgumentException('Too many Minecraft JWKS keys');}
            $kid = isset($jwk['kid']) && is_string($jwk['kid']) ? $jwk['kid'] : '#' . $i;
            if(strlen($kid) > 256 || isset($keys[$kid])){throw new \InvalidArgumentException('Duplicate/invalid JWKS key id');}
            $pem = self::jwkPublicPem($jwk);
            if($pem !== null){
                $keys[$kid] = ['pem' => $pem, 'kty' => $jwk['kty']];
            }
        }
        return new self($keys);
    }

    /** @return array{xuid: string, name: string, publicKeyDer: string, subject: string} */
    public function validate(string $offerSdp, ?int $now = null) : array{
        if($offerSdp === '' || strlen($offerSdp) > self::MAX_SDP || str_contains($offerSdp, "\0")){
            throw new \InvalidArgumentException('Invalid SDP offer size');
        }
        $normalized = str_replace("\r\n", "\n", $offerSdp);
        if(!preg_match_all('/^a=identity:([^\n]+)$/m', $normalized, $found) || count($found[1]) !== 1 ||
            strlen($found[1][0]) > self::MAX_IDENTITY){
            throw new \InvalidArgumentException('Exactly one NetherNet identity assertion is required');
        }
        $identityJson = base64_decode(trim($found[1][0]), true);
        if($identityJson === false || strlen($identityJson) > self::MAX_IDENTITY){
            throw new \InvalidArgumentException('Invalid identity Base64');
        }
        $identity = json_decode($identityJson, true, 16, JSON_THROW_ON_ERROR);
        if(!is_array($identity) || !isset($identity['assertion']) || !is_string($identity['assertion'])){
            throw new \InvalidArgumentException('Invalid identity wrapper');
        }
        $assertion = json_decode($identity['assertion'], true, 16, JSON_THROW_ON_ERROR);
        if(!is_array($assertion) || !is_string($assertion['token'] ?? null) ||
            !is_string($assertion['fingerprints'] ?? null)){
            throw new \InvalidArgumentException('Invalid identity assertion');
        }
        $claims = $this->verifyMinecraftToken($assertion['token'], $now ?? time());
        $fingerprints = [];
        foreach(explode("\n", $normalized) as $line){
            if(!str_starts_with($line, 'a=fingerprint:')){continue;}
            $value = trim(substr($line, strlen('a=fingerprint:')));
            if(!preg_match('/\A([a-z0-9-]+) ([A-Fa-f0-9:]+)\z/D', $value, $matches)){
                throw new \InvalidArgumentException('Invalid SDP fingerprint syntax');
            }
            if(count($fingerprints) >= 8){throw new \InvalidArgumentException('Too many SDP fingerprints');}
            $fingerprints[] = ['algorithm' => $matches[1], 'digest' => $matches[2]];
        }
        if($fingerprints === []){
            throw new \InvalidArgumentException('SDP missing DTLS fingerprints');
        }
        $canonical = json_encode(['fingerprint' => $fingerprints], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $detached = explode('.', $assertion['fingerprints']);
        if(count($detached) !== 3 || $detached[1] !== ''){
            throw new \InvalidArgumentException('Invalid detached fingerprint JWS');
        }
        $protected = self::decodeJsonPart($detached[0]);
        if(($protected['alg'] ?? null) !== 'ES384'){
            throw new \InvalidArgumentException('NetherNet fingerprint algorithm must be ES384');
        }
        $der = base64_decode($claims['cpk'], true);
        if($der === false || strlen($der) < 64 || strlen($der) > 4096){
            throw new \InvalidArgumentException('Invalid client identity public key');
        }
        $pem = self::derToPem($der);
        $key = openssl_pkey_get_public($pem);
        if($key === false || ($info = openssl_pkey_get_details($key)) === false ||
            $info['type'] !== OPENSSL_KEYTYPE_EC || ($info['ec']['curve_name'] ?? null) !== 'secp384r1'){
            throw new \InvalidArgumentException('Client identity must use EC P-384');
        }
        $signature = self::decodeUrlPart($detached[2]);
        $signed = $detached[0] . '.' . self::encodeUrl($canonical);
        if(openssl_verify($signed, self::ecdsaJoseToDer($signature, 48), $key, OPENSSL_ALGO_SHA384) !== 1){
            throw new \InvalidArgumentException('SDP fingerprint signature rejected');
        }
        return [
            'xuid' => (string)($claims['xid'] ?? ''),
            'name' => (string)($claims['xname'] ?? ''),
            'publicKeyDer' => $der,
            'subject' => $claims['sub']
        ];
    }

    /** @return array<string, mixed> */
    private function verifyMinecraftToken(string $jwt, int $now) : array{
        if(strlen($jwt) > self::MAX_TOKEN){throw new \InvalidArgumentException('Identity JWT too large');}
        $parts = explode('.', $jwt);
        if(count($parts) !== 3){throw new \InvalidArgumentException('Invalid identity JWT');}
        $header = self::decodeJsonPart($parts[0]);
        $claims = self::decodeJsonPart($parts[1]);
        $alg = $header['alg'] ?? null;
        if(!in_array($alg, ['RS256', 'RS384', 'ES256', 'ES384'], true)){
            throw new \InvalidArgumentException('Unsupported Minecraft JWT algorithm');
        }
        $signature = self::decodeUrlPart($parts[2]);
        $verified = false;
        foreach($this->keys as $kid => $entry){
            if(isset($header['kid']) && $header['kid'] !== $kid){continue;}
            if(($entry['kty'] === 'RSA') !== str_starts_with($alg, 'RS')){continue;}
            $key = openssl_pkey_get_public($entry['pem']);
            if($key === false){continue;}
            $raw = str_starts_with($alg, 'ES') ? self::ecdsaJoseToDer($signature, $alg === 'ES384' ? 48 : 32) : $signature;
            $digest = str_ends_with($alg, '256') ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA384;
            if(openssl_verify($parts[0] . '.' . $parts[1], $raw, $key, $digest) === 1){
                $verified = true;
                break;
            }
        }
        if(!$verified){throw new \InvalidArgumentException('Minecraft identity JWT signature rejected');}
        $aud = $claims['aud'] ?? null;
        if(($claims['iss'] ?? null) !== self::ISSUER ||
            !(is_string($aud) ? $aud === self::AUDIENCE : (is_array($aud) && in_array(self::AUDIENCE, $aud, true))) ||
            !is_string($claims['sub'] ?? null) || $claims['sub'] === '' ||
            !is_int($claims['exp'] ?? null) || $claims['exp'] <= $now - self::SKEW ||
            (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now + self::SKEW)) ||
            (isset($claims['iat']) && (!is_int($claims['iat']) || $claims['iat'] > $now + self::SKEW)) ||
            !is_string($claims['cpk'] ?? null) ||
            !is_string($claims['xid'] ?? null) || $claims['xid'] === ''){
            throw new \InvalidArgumentException('Minecraft token claims invalid or expired');
        }
        return $claims;
    }

    /** @return array<string, mixed> */
    private static function decodeJsonPart(string $part) : array{
        $decoded = json_decode(self::decodeUrlPart($part), true, 12, JSON_THROW_ON_ERROR);
        if(!is_array($decoded)){throw new \InvalidArgumentException('Malformed JWS JSON');}
        return $decoded;
    }

    private static function decodeUrlPart(string $part) : string{
        if($part === '' || preg_match('/\A[A-Za-z0-9_-]+\z/D', $part) !== 1){
            throw new \InvalidArgumentException('Invalid Base64URL');
        }
        $s = base64_decode(strtr($part, '-_', '+/') . str_repeat('=', (4 - strlen($part) % 4) % 4), true);
        if($s === false){throw new \InvalidArgumentException('Bad Base64URL data');}
        return $s;
    }
    private static function encodeUrl(string $data) : string{
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function derLen(int $n) : string{
        if($n < 128){return chr($n);}
        if($n <= 255){return "\x81" . chr($n);}
        if($n <= 65535){return "\x82" . pack('n', $n);}
        throw new \InvalidArgumentException('ASN.1 length out of range');
    }
    private static function tlv(int $tag, string $body) : string{
        return chr($tag) . self::derLen(strlen($body)) . $body;
    }
    private static function derInt(string $s) : string{
        $s = ltrim($s, "\0");
        if($s === ''){$s = "\0";}
        if((ord($s[0]) & 0x80) !== 0){$s = "\0" . $s;}
        return self::tlv(2, $s);
    }
    private static function ecdsaJoseToDer(string $raw, int $n) : string{
        if(strlen($raw) !== 2 * $n){throw new \InvalidArgumentException('Invalid ECDSA signature length');}
        return self::tlv(48, self::derInt(substr($raw, 0, $n)) . self::derInt(substr($raw, $n)));
    }
    private static function derToPem(string $der) : string{
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
    /** @param array<string, mixed> $jwk */
    private static function jwkPublicPem(array $jwk) : ?string{
        if(isset($jwk['x5c'][0]) && is_string($jwk['x5c'][0])){
            $cert = base64_decode($jwk['x5c'][0], true);
            if($cert === false || strlen($cert) > 16384){return null;}
            $certificate = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($cert), 64, "\n") . "-----END CERTIFICATE-----\n";
            $key = openssl_pkey_get_public($certificate);
            if($key === false){return null;}
            $details = openssl_pkey_get_details($key);
            return $details === false ? null : $details['key'];
        }
        if($jwk['kty'] === 'RSA' && is_string($jwk['n'] ?? null) && is_string($jwk['e'] ?? null)){
            $n = self::decodeUrlPart($jwk['n']);
            $e = self::decodeUrlPart($jwk['e']);
            if(strlen($n) < 256 || strlen($n) > 1024 || strlen($e) > 8){return null;}
            $rsa = self::tlv(48, self::derInt($n) . self::derInt($e));
            $algorithm = hex2bin('300d06092a864886f70d0101010500');
            return self::derToPem(self::tlv(48, $algorithm . self::tlv(3, "\0" . $rsa)));
        }
        if($jwk['kty'] === 'EC' && in_array($jwk['crv'] ?? null, ['P-256', 'P-384'], true) &&
            is_string($jwk['x'] ?? null) && is_string($jwk['y'] ?? null)){
            $x = self::decodeUrlPart($jwk['x']);
            $y = self::decodeUrlPart($jwk['y']);
            $width = $jwk['crv'] === 'P-384' ? 48 : 32;
            if(strlen($x) !== $width || strlen($y) !== $width){return null;}
            $algorithm = hex2bin($width === 48 ?
                '301006072a8648ce3d020106052b81040022' :
                '301306072a8648ce3d020106082a8648ce3d030107');
            return self::derToPem(self::tlv(48, $algorithm . self::tlv(3, "\0\x04" . $x . $y)));
        }
        return null;
    }
}
