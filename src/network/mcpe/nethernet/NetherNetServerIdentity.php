<?php
declare(strict_types=1);
namespace pocketmine\network\mcpe\nethernet;

/** Long-lived TOFU operator key. Never regenerated after corruption or restart. */
final class NetherNetServerIdentity{
    private \OpenSSLAsymmetricKey $key;
    private string $token;

    public function __construct(string $path){
        $directory = dirname($path);
        if(is_link($directory) || is_link($path) || is_link($path . '.lock')){
            throw new \RuntimeException('NetherNet operator identity cannot be a symbolic link');
        }
        if(!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)){
            throw new \RuntimeException('Cannot create private NetherNet identity directory');
        }
        $oldMask = umask(0077);
        try{
            $lock = fopen($path . '.lock', 'c');
            if($lock === false || !flock($lock, LOCK_EX | LOCK_NB)){
                throw new \RuntimeException('Cannot lock NetherNet operator identity');
            }
            try{
                clearstatcache(true, $path);
                if(!file_exists($path)){
                    // PHP 8.4's EC parameter API also works in relocatable PocketMine PHP,
                    // whose compiled-in openssl.cnf path may not exist in the container.
                    $key = openssl_pkey_new(['ec' => ['curve_name' => 'secp384r1']]);
                    if($key === false || !openssl_pkey_export($key, $pem, null, ['config' => '/dev/null'])){
                        throw new \RuntimeException('Cannot generate NetherNet P-384 identity');
                    }
                    $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
                    try{
                        if(file_put_contents($temporary, $pem, LOCK_EX) !== strlen($pem) || !chmod($temporary, 0600) || !rename($temporary, $path)){
                            throw new \RuntimeException('Cannot persist NetherNet operator identity');
                        }
                    }finally{if(is_file($temporary)){unlink($temporary);}}
                }
                if(!is_file($path) || filesize($path) > 8192 || !chmod($path, 0600)){
                    throw new \RuntimeException('Invalid or insecure NetherNet operator key file');
                }
                $key = openssl_pkey_get_private(file_get_contents($path));
                $details = $key === false ? false : openssl_pkey_get_details($key);
                if($details === false || ($details['ec']['curve_name'] ?? null) !== 'secp384r1'){
                    throw new \RuntimeException('Invalid persisted NetherNet key; restore its backup instead of deleting it');
                }
                $this->key = $key;
            }finally{flock($lock, LOCK_UN); fclose($lock);}
        }finally{umask($oldMask);}
        $details = openssl_pkey_get_details($this->key);
        // Match the retail SPKI wire representation used by Cloudburst/Engine.
        $der = base64_decode(preg_replace('~-----[^-]+-----|\s+~', '', $details['key']), true);
        $this->token = $this->sign(json_encode([
            'cpk' => base64_encode($der), 'iss' => 'RyzeCompany/RyzeMP',
            'iat' => time(), 'exp' => time() + 86400 * 365
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function sign(string $payload) : string{
        $head = NetherNetIdentityAssertionVerifier::encodeUrl('{"alg":"ES384"}');
        $input = $head . '.' . NetherNetIdentityAssertionVerifier::encodeUrl($payload);
        if(!openssl_sign($input, $signature, $this->key, OPENSSL_ALGO_SHA384)){
            throw new \RuntimeException('NetherNet identity signing failed');
        }
        return $input . '.' . NetherNetIdentityAssertionVerifier::encodeUrl(NetherNetIdentityAssertionVerifier::ecdsaDerToJose($signature));
    }

    public function signAnswer(string $sdp) : string{
        if(str_contains($sdp, 'a=identity:') || !str_contains($sdp, "\r\nm=")){
            throw new \InvalidArgumentException('Invalid native SDP answer');
        }
        $jws = explode('.', $this->sign(NetherNetIdentityAssertionVerifier::canonicalFingerprints($sdp)));
        $identity = base64_encode(json_encode([
            'idp' => ['domain' => 'RyzeCompany/RyzeMP', 'protocol' => 'default'],
            'assertion' => json_encode(['token' => $this->token, 'fingerprints' => $jws[0] . '..' . $jws[2]], JSON_THROW_ON_ERROR)
        ], JSON_THROW_ON_ERROR));
        $offset = strpos($sdp, "\r\nm=") + 2;
        return substr($sdp, 0, $offset) . 'a=identity:' . $identity . "\r\n" . substr($sdp, $offset);
    }
}
