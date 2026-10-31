<?php
declare(strict_types=1);
/** Synthetic authority ONLY for automated tests; never consumed by server code. */
final class NetherNetFixtures{
    public \OpenSSLAsymmetricKey $authority;
    public \OpenSSLAsymmetricKey $client;
    public array $jwk;
    public string $clientDer;
    public function __construct(){
        $rsa = getenv('RYZE_TEST_RSA_PEM'); $ec = getenv('RYZE_TEST_EC_PEM');
        $this->authority = $rsa !== false ? openssl_pkey_get_private(file_get_contents($rsa)) : openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048,'config'=>'/etc/ssl/openssl.cnf']);
        $this->client = $ec !== false ? openssl_pkey_get_private(file_get_contents($ec)) : openssl_pkey_new(['ec'=>['curve_name'=>'secp384r1']]);
        $details = openssl_pkey_get_details($this->authority);
        $this->jwk = ['kid'=>'test-authority','kty'=>'RSA','use'=>'sig','alg'=>'RS256','n'=>self::url($details['rsa']['n']),'e'=>self::url($details['rsa']['e'])];
        $this->clientDer = self::der(openssl_pkey_get_details($this->client)['key']);
    }
    public static function url(string $data) : string{return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');}
    public static function decode(string $data) : string{return base64_decode(strtr($data, '-_', '+/'));}
    public static function der(string $pem) : string{return base64_decode(preg_replace('~-----[^-]+-----|\s+~', '', $pem), true);}
    public function jwks() : string{return json_encode(['keys'=>[$this->jwk]], JSON_THROW_ON_ERROR);}
    public function offer(string $sdp, array $overrides = [], bool $jwkCpk = false) : string{
        $cpk = base64_encode($this->clientDer);
        if($jwkCpk){$d=openssl_pkey_get_details($this->client);$cpk=['kty'=>'EC','crv'=>'P-384','x'=>self::url($d['ec']['x']),'y'=>self::url($d['ec']['y'])];}
        $claims = array_replace(['iss'=>'https://authorization.franchise.minecraft-services.net/',
            'aud'=>'api://auth-minecraft-services/multiplayer','sub'=>'synthetic-client', 'iat'=>time(), 'exp'=>time()+900,
            'xid'=>'987654','xname'=>'Fixture','cpk'=>$cpk], $overrides);
        $input = self::url('{"alg":"RS256","kid":"test-authority"}') . '.' . self::url(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if(!openssl_sign($input, $signature, $this->authority, OPENSSL_ALGO_SHA256)){throw new RuntimeException('Fixture signing failed');}
        $jwt = $input . '.' . self::url($signature);
        $head = self::url('{"alg":"ES384"}');
        $canonical = \pocketmine\network\mcpe\nethernet\NetherNetIdentityAssertionVerifier::canonicalFingerprints($sdp);
        openssl_sign($head . '.' . self::url($canonical), $signature, $this->client, OPENSSL_ALGO_SHA384);
        $jose = \pocketmine\network\mcpe\nethernet\NetherNetIdentityAssertionVerifier::ecdsaDerToJose($signature);
        $identity = base64_encode(json_encode(['idp'=>['domain'=>'test.invalid','protocol'=>'default'],
            'assertion'=>json_encode(['token'=>$jwt,'fingerprints'=>$head . '..' . self::url($jose)], JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR));
        $pos = strpos($sdp, 'm=application');
        return substr($sdp, 0, $pos) . 'a=identity:' . $identity . "\r\n" . substr($sdp, $pos);
    }
    public static function verifyAnswer(string $sdp) : string{
        $v = \pocketmine\network\mcpe\nethernet\NetherNetIdentityAssertionVerifier::class;
        if(preg_match('/^a=identity:([^\r\n]+)/m', $sdp, $m) !== 1){throw new RuntimeException('Unsigned server answer');}
        $identity=json_decode(base64_decode($m[1]),true,flags:JSON_THROW_ON_ERROR);
        $assertion=json_decode($identity['assertion'],true,flags:JSON_THROW_ON_ERROR);
        $jwt=explode('.',$assertion['token']); $claims=json_decode(self::decode($jwt[1]),true,flags:JSON_THROW_ON_ERROR);
        $der=$v::publicKeyDer($claims['cpk']); $key=openssl_pkey_get_public($v::derToPem($der));
        if($claims['exp'] < time() || openssl_verify($jwt[0].'.'.$jwt[1],$v::ecdsaJoseToDer(self::decode($jwt[2]),48),$key,OPENSSL_ALGO_SHA384)!==1){throw new RuntimeException('Invalid server JWT');}
        $jws=explode('.',$assertion['fingerprints']);
        if($jws[1]!=='' || openssl_verify($jws[0].'.'.self::url($v::canonicalFingerprints($sdp)),$v::ecdsaJoseToDer(self::decode($jws[2]),48),$key,OPENSSL_ALGO_SHA384)!==1){throw new RuntimeException('Invalid server DTLS signature');}
        return $der;
    }
}
