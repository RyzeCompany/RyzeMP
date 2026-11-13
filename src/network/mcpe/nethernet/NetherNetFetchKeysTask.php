<?php
declare(strict_types=1);
namespace pocketmine\network\mcpe\nethernet;

use pocketmine\scheduler\AsyncTask;

/** Fixed authority, verified TLS, no redirects, bounded time and response size. */
final class NetherNetFetchKeysTask extends AsyncTask{
    private ?string $jwks = null;
    public function __construct(\Closure $done){$this->storeLocal('done', $done);}

    public function onRun() : void{
        $body = '';
        $curl = curl_init('https://authorization.franchise.minecraft-services.net/.well-known/keys');
        if($curl === false){return;}
        try{
            curl_setopt_array($curl, [
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'RyzeMP-NetherNet/1',
                CURLOPT_WRITEFUNCTION => static function($handle, string $chunk) use (&$body) : int{
                    if(strlen($body) + strlen($chunk) > 262144){return 0;}
                    $body .= $chunk;
                    return strlen($chunk);
                }
            ]);
            if(curl_exec($curl) !== false && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200){
                $this->jwks = $body;
            }
        }finally{curl_close($curl);}
    }

    public function onCompletion() : void{($this->fetchLocal('done'))($this->jwks);}
}
