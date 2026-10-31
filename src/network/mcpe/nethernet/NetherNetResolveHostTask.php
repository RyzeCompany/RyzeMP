<?php
declare(strict_types=1);
namespace pocketmine\network\mcpe\nethernet;
use pocketmine\scheduler\AsyncTask;

/** DNS runs on a bounded async worker, never within a game tick or native callback. */
final class NetherNetResolveHostTask extends AsyncTask{
    private ?string $ip = null;
    public function __construct(private string $host, \Closure $done){$this->storeLocal('done', $done);}
    public function onRun() : void{
        $addresses = @gethostbynamel($this->host);
        foreach($addresses === false ? [] : $addresses as $ip){
            if(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && $ip !== '0.0.0.0'){$this->ip = $ip; break;}
        }
    }
    public function onCompletion() : void{($this->fetchLocal('done'))($this->ip);}
}
