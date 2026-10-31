<?php
declare(strict_types=1);
namespace pocketmine\network\mcpe\nethernet;

use pocketmine\scheduler\AsyncPool;

/** In-memory trusted JWKS; network requests never run on the game thread. */
final class NetherNetAuthKeys{
    private ?NetherNetIdentityAssertionVerifier $verifier = null;
    private bool $fetching = false;
    private int $fetchedAt = 0;
    private int $retryAt = 0;
    private bool $stopped = false;

    public function __construct(private AsyncPool $pool, private \Closure $log){}

    public function tick(bool $unknownKey = false) : void{
        $now = time();
        if($this->stopped || $this->fetching || $now < $this->retryAt ||
            (!$unknownKey && $this->verifier !== null && $now - $this->fetchedAt < 900)){
            return;
        }
        $this->fetching = true;
        $this->retryAt = $now + 60;
        $this->pool->submitTask(new NetherNetFetchKeysTask(function(?string $jwks) : void{
            $this->fetching = false;
            if($this->stopped){return;}
            try{
                if($jwks === null){throw new \RuntimeException('Official Minecraft HTTPS JWKS request failed');}
                $this->verifier = NetherNetIdentityAssertionVerifier::fromOfficialJwks($jwks);
                $this->fetchedAt = time();
                ($this->log)('NetherNet official Minecraft authentication keys ready (automatic renewal active).');
            }catch(\Throwable){
                ($this->log)('NetherNet could not refresh official authentication keys; retry in 60s. Offers fail closed when no fresh keys are available.');
            }
        }));
    }

    public function getVerifier() : ?NetherNetIdentityAssertionVerifier{
        return time() - $this->fetchedAt <= 21600 ? $this->verifier : null;
    }

    public function shutdown() : void{$this->stopped = true; $this->verifier = null;}
}
