<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

use function ord;
use function strlen;
use function substr;
use function unpack;

/**
 * Decodes the body of a frame received from the NetherNet bridge. Integers are big-endian, strings are prefixed with
 * a uint16 length.
 */
final class NetherNetFrameReader{
	public function __construct(
		private string $buffer,
		private int $offset = 0
	){}

	/**
	 * @throws NetherNetFrameException
	 */
	private function need(int $bytes) : void{
		if(strlen($this->buffer) - $this->offset < $bytes){
			throw new NetherNetFrameException("Frame too short: need $bytes more bytes at offset {$this->offset}");
		}
	}

	/**
	 * @throws NetherNetFrameException
	 */
	public function u8() : int{
		$this->need(1);
		return ord($this->buffer[$this->offset++]);
	}

	/**
	 * @throws NetherNetFrameException
	 */
	public function u16() : int{
		$this->need(2);
		$v = unpack("n", $this->buffer, $this->offset);
		$this->offset += 2;
		if($v === false){
			throw new NetherNetFrameException("Could not decode uint16");
		}
		return $v[1];
	}

	/**
	 * @throws NetherNetFrameException
	 */
	public function u32() : int{
		$this->need(4);
		$v = unpack("N", $this->buffer, $this->offset);
		$this->offset += 4;
		if($v === false){
			throw new NetherNetFrameException("Could not decode uint32");
		}
		return $v[1];
	}

	/**
	 * @throws NetherNetFrameException
	 */
	public function string() : string{
		$length = $this->u16();
		$this->need($length);
		$s = substr($this->buffer, $this->offset, $length);
		$this->offset += $length;
		return $s;
	}

	/**
	 * @throws NetherNetFrameException
	 */
	public function bytes(int $length) : string{
		$this->need($length);
		$s = substr($this->buffer, $this->offset, $length);
		$this->offset += $length;
		return $s;
	}

	public function hasMore() : bool{
		return $this->offset < strlen($this->buffer);
	}

	public function rest() : string{
		$s = substr($this->buffer, $this->offset);
		$this->offset = strlen($this->buffer);
		return $s;
	}
}
