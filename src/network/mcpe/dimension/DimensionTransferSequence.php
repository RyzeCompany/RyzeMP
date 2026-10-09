<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\dimension;

/**
 * Tracks one cross-dimension transfer without timers or packet hacks.
 *
 * Both the teleport request and the target player's chunk must be queued
 * before the transport is allowed to send the dimension-success action and
 * the final teleport (in that order).
 *
 * Does NOT assert client receipt or rendering of any packet.
 * @internal
 */
final class DimensionTransferSequence{
    private bool $waiting = false;
    private bool $teleportRequested = false;
    private bool $destinationChunkQueued = false;

    public function begin() : void{
        $this->waiting = true;
        $this->teleportRequested = false;
        $this->destinationChunkQueued = false;
    }

    /**
     * @return bool whether the final position packet must be deferred
     */
    public function deferTeleport() : bool{
        if(!$this->waiting){
            return false;
        }
        $this->teleportRequested = true;
        return true;
    }

    public function targetChunkQueued() : void{
        if($this->waiting){
            $this->destinationChunkQueued = true;
        }
    }

    /**
     * Consumes the completed transfer only once, even if the destination
     * chunk is delivered more than once or callbacks are repeated.
     */
    public function takeReady() : bool{
        if(!$this->waiting || !$this->teleportRequested || !$this->destinationChunkQueued){
            return false;
        }
        $this->cancel();
        return true;
    }

    public function isWaiting() : bool{
        return $this->waiting;
    }

    public function cancel() : void{
        $this->waiting = false;
        $this->teleportRequested = false;
        $this->destinationChunkQueued = false;
    }
}
