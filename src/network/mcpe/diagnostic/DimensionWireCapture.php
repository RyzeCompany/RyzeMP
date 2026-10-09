<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\diagnostic;

use function base64_encode;
use function bin2hex;
use function chmod;
use function fclose;
use function file_exists;
use function fopen;
use function fwrite;
use function getenv;
use function gmdate;
use function is_dir;
use function json_encode;
use function microtime;
use function mkdir;
use function ord;
use function random_bytes;
use function rtrim;
use function strlen;
use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;

/**
 * Explicitly opt-in, short-lived wire capture for investigating protocol 2193
 * dimension transfers. Captures network BATCHES before encryption (after
 * compression) so an independent offline decoder can inspect their contents.
 *
 * IMPORTANT: Captured batches can contain signs, block entity NBT, player
 * position, and other gameplay data. Share only sanitized analyzer output.
 */
final class DimensionWireCapture{
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const MAX_BATCHES = 512;
    private const MAX_SECONDS = 3.0;

    /** @var resource|null */
    private $stream;
    private float $startedAt;
    private int $bytes = 0;
    private int $batches = 0;

    /**
     * @param resource $stream
     */
    private function __construct($stream, private string $fileName, int $dimension){
        $this->stream = $stream;
        $this->startedAt = microtime(true);
        $this->write([
            "kind" => "start",
            "time" => $this->startedAt,
            "protocol" => 2193,
            "dimension" => $dimension,
            "note" => "pre-encryption compressed network batches; TEST SERVERS ONLY"
        ]);
    }

    public static function create(string $serverDataPath, int $dimension) : ?self{
        if(getenv("PMMP_DIMENSION_WIRE_CAPTURE") !== "1"){
            return null;
        }
        $directory = rtrim($serverDataPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "dimension-captures";
        if(!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)){
            return null;
        }

        $file = $directory . DIRECTORY_SEPARATOR .
            "dim2193-" . gmdate("Ymd-His") . "-" . bin2hex(random_bytes(6)) . ".jsonl";
        // "x" prevents accidentally overwriting another session's file.
        $stream = @fopen($file, "xb");
        if($stream === false){
            return null;
        }
        @chmod($file, 0600);
        return new self($stream, $file, $dimension);
    }

    public function recordOutboundBatch(string $compressedBatch) : void{
        if($this->stream === null){
            return;
        }
        if(microtime(true) - $this->startedAt > self::MAX_SECONDS ||
            $this->batches >= self::MAX_BATCHES ||
            $this->bytes + strlen($compressedBatch) > self::MAX_BYTES){
            $this->close("limit_reached");
            return;
        }
        ++$this->batches;
        $this->bytes += strlen($compressedBatch);
        $this->write([
            "kind" => "outbound_batch",
            "time" => microtime(true),
            "sequence" => $this->batches,
            "compression" => strlen($compressedBatch) > 0 ? ord($compressedBatch[0]) : -1,
            "bytes" => strlen($compressedBatch),
            "base64" => base64_encode($compressedBatch)
        ]);
    }

    public function markClientDisconnect() : void{
        $this->close("client_disconnected");
    }

    public function close(string $reason = "closed") : void{
        if($this->stream === null){
            return;
        }
        $this->write([
            "kind" => "end",
            "time" => microtime(true),
            "reason" => $reason,
            "batches" => $this->batches,
            "bytes" => $this->bytes
        ]);
        fclose($this->stream);
        $this->stream = null;
    }

    /** @param array<string, bool|float|int|string> $record */
    private function write(array $record) : void{
        if($this->stream === null){
            return;
        }
        $line = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
        $written = @fwrite($this->stream, $line);
        if($written !== strlen($line)){
            fclose($this->stream);
            $this->stream = null;
        }
    }

    public function __destruct(){
        $this->close();
    }
}
