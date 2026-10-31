<?php
declare(strict_types=1);

// Local cryptographic fixtures only. Synthetic keys are NEVER installed as
// production trust anchors. Production must use official Minecraft HTTPS JWKS.
require dirname(__DIR__) . '/src/network/mcpe/nethernet/NetherNetIdentityAssertionVerifier.php';

use pocketmine\network\mcpe\nethernet\NetherNetIdentityAssertionVerifier as Verifier;

function b64url(string $x) : string{ return rtrim(strtr(base64_encode($x), '+/', '-_'), '='); }
function check(bool $b, string $msg) : void{if(!$b)throw new RuntimeException($msg);}
function signData(string $data, OpenSSLAsymmetricKey $key, int $alg) : string{
    check(openssl_sign($data, $sig, $key, $alg), 'Cannot create synthetic signature');
    return $sig;
}
function readLength(string $der, int &$i) : int{
    $n = ord($der[$i++]);
    if($n < 128)return $n;
    $bytes = $n & 127;
    check($bytes >= 1 && $bytes <= 2, 'Invalid DER test length');
    $size = 0;
    for($j = 0; $j < $bytes; ++$j){$size = ($size << 8) | ord($der[$i++]);}
    return $size;
}
function derToJose(string $der, int $width) : string{
    $offset = 0;
    check(ord($der[$offset++]) === 48, 'No signature SEQUENCE');
    readLength($der, $offset);
    $result = '';
    for($j = 0; $j < 2; ++$j){
        check(ord($der[$offset++]) === 2, 'Invalid signature integer');
        $len = readLength($der, $offset);
        $part = ltrim(substr($der, $offset, $len), "\0");
        $offset += $len;
        check(strlen($part) <= $width, 'ECDSA component too wide');
        $result .= str_pad($part, $width, "\0", STR_PAD_LEFT);
    }
    return $result;
}
// PocketMine's PHP build does not necessarily expose key generation.
// CI creates synthetic fixture keys via the system OpenSSL CLI. This does not
// bypass verification: the tests still sign/verify with PHP's OpenSSL extension.
$rsaFile = getenv('RYZE_TEST_RSA_PEM');
$ecFile = getenv('RYZE_TEST_EC_PEM');
$authority = $rsaFile !== false
    ? openssl_pkey_get_private(file_get_contents($rsaFile))
    : openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
$client = $ecFile !== false
    ? openssl_pkey_get_private(file_get_contents($ecFile))
    : openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);
check($authority instanceof OpenSSLAsymmetricKey && $client instanceof OpenSSLAsymmetricKey,
    'Cannot import synthetic fixture keys; OpenSSL error: ' . (openssl_error_string() ?: 'unknown'));
$root = openssl_pkey_get_details($authority);
$clientDetails = openssl_pkey_get_details($client);
check(is_array($root) && is_array($clientDetails), 'No public keys');
$jwks = json_encode(['keys' => [[
    'kid' => 'official-test-fixture',
    'kty' => 'RSA',
    'n' => b64url($root['rsa']['n']),
    'e' => b64url($root['rsa']['e'])
]]], JSON_THROW_ON_ERROR);
$verifier = Verifier::fromOfficialJwks($jwks);
$clientPem = $clientDetails['key'];
$clientDer = base64_decode(preg_replace('~-----[^-]+-----|\s+~', '', $clientPem), true);
check($clientDer !== false, 'Could not DER-encode client identity');

$issue = static function(int $expiry) use ($authority, $client, $clientDer): string{
    $jwtHeader = b64url('{"alg":"RS256","kid":"official-test-fixture"}');
    $jwtClaims = b64url(json_encode([
        'iss' => 'https://authorization.franchise.minecraft-services.net/',
        'aud' => 'api://auth-minecraft-services/multiplayer',
        'sub' => 'synthetic-client',
        'exp' => $expiry,
        'xid' => '987654',
        'xname' => 'Fixture',
        'cpk' => base64_encode($clientDer)
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $jwtSigned = $jwtHeader . '.' . $jwtClaims;
    $jwt = $jwtSigned . '.' . b64url(signData($jwtSigned, $authority, OPENSSL_ALGO_SHA256));
    $canonical = '{"fingerprint":[{"algorithm":"sha-256","digest":"AA:BB:CC:DD"}]}';
    $head = b64url('{"alg":"ES384"}');
    $sig = derToJose(signData($head . '.' . b64url($canonical), $client, OPENSSL_ALGO_SHA384), 48);
    $identity = base64_encode(json_encode([
        'idp' => ['domain' => 'test', 'protocol' => 'default'],
        'assertion' => json_encode(['token' => $jwt, 'fingerprints' => $head . '..' . b64url($sig)], JSON_THROW_ON_ERROR)
    ], JSON_THROW_ON_ERROR));
    return "v=0\r\na=fingerprint:sha-256 AA:BB:CC:DD\r\na=identity:$identity\r\nm=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\n";
};
$valid = $issue(time() + 900);
$verified = $verifier->validate($valid);
check($verified['xuid'] === '987654', 'Authenticated XUID mismatch');
check($verified['name'] === 'Fixture', 'Authenticated name mismatch');
check(hash_equals($clientDer, $verified['publicKeyDer']), 'Identity public key mismatch');

$reject = static function(string $sdp, string $purpose) use ($verifier): void{
    try{$verifier->validate($sdp);}catch(InvalidArgumentException|JsonException $e){return;}
    throw new RuntimeException('Accepted invalid ' . $purpose);
};
$reject(str_replace('AA:BB:CC:DD', 'AA:BB:CC:EE', $valid), 'DTLS fingerprint tampering');
$reject($issue(time() - 3600), 'expired Minecraft JWT');
$reject(str_replace('a=fingerprint:sha-256 ', 'a=fingerprint:sha-384 ', $valid), 'fingerprint algorithm tampering');
$reject(str_replace('a=identity:', 'a=unknown:', $valid), 'missing Minecraft assertion');
$reject($valid . 'a=identity:AAAA\r\n', 'duplicate assertion');
echo "RyzeMP Minecraft JWT RSA + detached ES384 fingerprint validation OK\n";
