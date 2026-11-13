<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

use PHPUnit\Framework\TestCase;

final class NetherNetFrameCodecTest extends TestCase{

	public function testSingleMessage() : void{
		$frames = NetherNetFrameCodec::fragment("bedrock", 64, true);
		self::assertSame(["\x00bedrock"], $frames);
		self::assertSame("bedrock", (new NetherNetFrameCodec())->receive($frames[0], true));
	}

	public function testReliableFragmentsInCountdownOrder() : void{
		$frames = NetherNetFrameCodec::fragment("abcdefghijk", 5, true);
		self::assertSame(["\x02abcd", "\x01efgh", "\x00ijk"], $frames);
		$codec = new NetherNetFrameCodec();
		self::assertNull($codec->receive($frames[0], true));
		self::assertNull($codec->receive($frames[1], true));
		self::assertSame("abcdefghijk", $codec->receive($frames[2], true));
		self::assertSame("next", $codec->receive("\x00next", true));
	}

	public function testUnreliableCannotFragment() : void{
		$this->expectException(\LengthException::class);
		NetherNetFrameCodec::fragment("too-long", 5, false);
	}

	public function testUnreliableSingleMessage() : void{
		$codec = new NetherNetFrameCodec();
		self::assertSame("move", $codec->receive(NetherNetFrameCodec::fragment("move", 5, false)[0], false));
	}

	public function testRejectsOutOfOrderThenRecovers() : void{
		$codec = new NetherNetFrameCodec();
		self::assertNull($codec->receive("\x02first", true));
		try{
			$codec->receive("\x00skipped", true);
			self::fail("Expected fragment order validation");
		}catch(\UnexpectedValueException){
			self::assertSame("clean", $codec->receive("\x00clean", true));
		}
	}

	public function testRejectsFragmentOnUnreliableChannel() : void{
		$this->expectException(\UnexpectedValueException::class);
		(new NetherNetFrameCodec())->receive("\x01bad", false);
	}

	public function testRejectsOversizedMessage() : void{
		$this->expectException(\LengthException::class);
		NetherNetFrameCodec::fragment(str_repeat("X", 8 * 1024 * 1024 + 1), 1200, true);
	}
}
