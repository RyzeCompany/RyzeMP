package main

import (
	"bufio"
	"encoding/binary"
	"errors"
	"fmt"
	"io"

	"github.com/PocketMine-MP-Reborn/PocketMine-MP/native/vanillagen/worldgen"
)

// Wire protocol between PocketMine-MP (src/world/generator/vanilla/native) and vanillagen.
//
// Every message is a frame: u32 little-endian payload length, then the payload. The first payload byte is the
// message type. Strings are u16 length + bytes. All integers are little-endian.
//
//	client -> server  HELLO  0x01  "PMVG" u16 protocol, str token, str dataDir, str settings (overworld, nether or end),
//	                               i64 seed, u32 flags; with FLAG_PACKED_BIOMES: u16 b, u32[b] Bedrock biome id of every
//	                               biome of the generator (in the order of the READY biome names)
//	server -> client  READY  0x81  u32 algorithm, str fingerprint, str version, i32 minY, i32 height,
//	                               u16 biomeCount, str[biomeCount] biome names,
//	                               u16 paletteCount, str[paletteCount] material names (0 without FLAG_MATERIALS)
//	client -> server  FILL   0x02  i32 chunkX, i32 chunkZ, optional terrain adaptation: u16 r, (i32 minX, minY, minZ,
//	                               maxX, maxY, maxZ, adaptation, groundLevelDelta)[r], u16 j, (i32 x, groundY, z)[j],
//	                               i32[6] affected area (Beardifier.php)
//	server -> client  CHUNK  0x82  i32 chunkX, i32 chunkZ, u32 n, u32 r, u8[r] run materials, u16[r] run
//	                               lengths (n blocks in total), u32 m, u16[m] biomes, u8 stage,
//	                               u32 k, (u32 start, u16 length)[k] carving mask runs
//	                               With FLAG_PACKED_SECTIONS in HELLO the runs are replaced by u32 n, u8[n] materials
//	                               (raw), and after the carving mask come the subchunks ready for PalettedBlockArray::
//	                               fromData(): u8 sections, per section (bottom up): u8 paletteCount (0 = only air),
//	                               u8[paletteCount] materials, u8 bitsPerBlock, u32 wordBytes, u8[wordBytes] words
//	                               (u32 LE words, blocks packed little end first, index (x << 8) | (z << 4) | y).
//	                               With FLAG_PACKED_BIOMES the biome arrays of the subchunks follow, ready for
//	                               PalettedBlockArray::fromData() too: per section u8 bits, u16 p, u32[p] Bedrock biome
//	                               ids, u8[wordBytes(bits)] words (see appendBiomeSections). Then, once a CLASSES message
//	                               was received and the chunk is past the terrain stage: u8 1, u16[512] terrain heights
//	                               (see appendTerrainHeights); otherwise u8 0.
//	client -> server  CLASSES 0x06 u16 n, u8[n] height class of every READY material (0 air, 1 solid, 2 blocks motion in
//	                               heightmaps); no reply. Later CHUNK replies carry the terrain heights.
//	server -> client  ERROR  0xFF  str message (the connection is closed afterwards)
//	client -> server  STATS  0x05  (no payload); reply 0x85 u64 process CPU time in ns (benchmarks)
//
// Decoration (native placement of the features of the decoration stage) uses messages 0x10-0x14 / 0x90-0x94 on the
// same connection, see decor/session.go.
//
// Materials: stage 0 (terrain): 0 air, 1 stone, 2 water, 3 lava; stages 1 (material rules) and 2 (carved): indexes
// into the READY material names. Index ((x << 4) | z) * height + (y - minY); the carving mask uses the same index.
// Biomes: index into the READY biome names, index ((qx << 2) | qz) * (height >> 2) + qy.
const (
	// protocolVersion 3: the Nether and End noise settings (the End's biomes are a single-biome tree)
	// protocolVersion 4: terrain adaptation (Beardifier) data in FILL
	// protocolVersion 5: decoration messages (decor/session.go)
	// protocolVersion 6: FLAG_PACKED_SECTIONS (raw materials + packed subchunks in CHUNK)
	// protocolVersion 7: FLAG_PACKED_BIOMES, CLASSES and terrain heights in CHUNK; decoration writes come back as
	//                    PocketMine block layers (decor/session.go)
	protocolVersion = 7
	// algorithmVersion must match VanillaNativeBackend::ALGORITHM_VERSION on the PHP side. Bump both whenever the
	// generated terrain changes, so that a binary and a PHP generator that disagree are never mixed in one world.
	algorithmVersion = 4

	msgHello      = 0x01
	msgFill       = 0x02
	msgStats      = 0x05 // client -> server: no payload; reply STATS_REPLY 0x85 u64 process CPU time (ns), for benchmarks
	msgStatsReply = 0x85
	msgClasses    = 0x06
	msgReady      = 0x81
	msgChunk      = 0x82
	msgError      = 0xFF

	flagNoAquifers      = 1
	flagDeepslateFloats = 2
	flagMaterials       = 4
	flagCarvers         = 8
	flagPackedSections  = 16
	flagPackedBiomes    = 32

	maxFrame = 64 << 20
)

type reader struct {
	b   []byte
	err error
}

func (r *reader) take(n int) []byte {
	if r.err != nil {
		return nil
	}
	if len(r.b) < n {
		r.err = errors.New("truncated message")
		return nil
	}
	v := r.b[:n]
	r.b = r.b[n:]
	return v
}

func (r *reader) u8() byte {
	if v := r.take(1); v != nil {
		return v[0]
	}
	return 0
}

func (r *reader) u16() uint16 {
	if v := r.take(2); v != nil {
		return binary.LittleEndian.Uint16(v)
	}
	return 0
}

func (r *reader) u32() uint32 {
	if v := r.take(4); v != nil {
		return binary.LittleEndian.Uint32(v)
	}
	return 0
}

func (r *reader) i64() int64 {
	if v := r.take(8); v != nil {
		return int64(binary.LittleEndian.Uint64(v))
	}
	return 0
}

func (r *reader) str() string {
	n := int(r.u16())
	return string(r.take(n))
}

type writer struct{ b []byte }

func (w *writer) u8(v byte)    { w.b = append(w.b, v) }
func (w *writer) u16(v uint16) { w.b = binary.LittleEndian.AppendUint16(w.b, v) }
func (w *writer) u32(v uint32) { w.b = binary.LittleEndian.AppendUint32(w.b, v) }
func (w *writer) i32(v int32)  { w.u32(uint32(v)) }
func (w *writer) str(s string) {
	if len(s) > 0xffff {
		s = s[:0xffff]
	}
	w.u16(uint16(len(s)))
	w.b = append(w.b, s...)
}

func readFrame(r *bufio.Reader) ([]byte, error) {
	var hdr [4]byte
	if _, err := io.ReadFull(r, hdr[:]); err != nil {
		return nil, err
	}
	n := binary.LittleEndian.Uint32(hdr[:])
	if n == 0 || n > maxFrame {
		return nil, fmt.Errorf("invalid frame length %d", n)
	}
	b := make([]byte, n)
	if _, err := io.ReadFull(r, b); err != nil {
		return nil, err
	}
	return b, nil
}

func writeFrame(w *bufio.Writer, payload []byte) error {
	var hdr [4]byte
	binary.LittleEndian.PutUint32(hdr[:], uint32(len(payload)))
	if _, err := w.Write(hdr[:]); err != nil {
		return err
	}
	if _, err := w.Write(payload); err != nil {
		return err
	}
	return w.Flush()
}

// readBeardifier reads the optional terrain adaptation of a FILL message (nil if absent or empty).
func readBeardifier(r *reader) *worldgen.Beardifier {
	if r.err != nil || len(r.b) == 0 {
		return nil
	}
	b := &worldgen.Beardifier{}
	n := int(r.u16())
	for i := 0; i < n*worldgen.RigidSize && r.err == nil; i++ {
		b.Rigids = append(b.Rigids, int(int32(r.u32())))
	}
	n = int(r.u16())
	for i := 0; i < n*worldgen.JunctionSize && r.err == nil; i++ {
		b.Junctions = append(b.Junctions, int(int32(r.u32())))
	}
	for i := 0; i < 6; i++ {
		b.Affected[i] = int(int32(r.u32()))
	}
	if r.err == nil && len(r.b) != 0 {
		r.err = errors.New("trailing bytes in FILL")
	}
	return b
}
