package decor

import (
	"encoding/binary"
	"errors"
)

// PocketMine block layers of the decoration region.
//
// PHP's FeatureLevel applies every write to its SubChunk objects: setBlockStateId() on block layer 0 and
// setLiquidLayerStateId() on layer 1 (water for waterlogged Bedrock blocks), which call chunkutils2's
// PalettedBlockArray::set(). A batch of native features writes thousands of blocks, mostly into a few sections, and
// applying them one by one cost PHP ~0.65 us each (most of its decoration time). The session therefore keeps an exact
// replica of the PHP layers of the sections that are written (same palette order, bits and words as set() produces,
// see pmArray.set()) and sends the sections a batch wrote many blocks into as whole layers, which PHP installs with
// PalettedBlockArray::fromData(). The other writes are sent with their PocketMine states, applied by PHP one by one.
// Either way PHP ends up with the arrays it would have built itself, byte for byte.

// pmArray is chunkutils2's PalettedBlockArray (BlockArrayContainer): palette in insertion order, bits per block
// growing to the smallest size that fits when the palette is full, words in its layout.
type pmArray struct {
	bits    uint8
	palette []uint32
	words   []uint32
}

// pmMaxPalette is PalettedBlockArray::MAX_PALETTE_SIZE.
func pmMaxPalette(bits uint8) int {
	if bits >= 12 {
		return 4096
	}
	return 1 << bits
}

// pmBitsFor is the bits per block of BlockArrayContainer::blockArrayFromCapacity() for a palette size.
func pmBitsFor(n int) uint8 {
	for _, b := range [...]uint8{0, 1, 2, 3, 4, 5, 6, 8, 16} {
		if pmMaxPalette(b) >= n {
			return b
		}
	}
	return 16
}

func pmWordCount(bits uint8) int {
	if bits == 0 {
		return 0
	}
	perWord := 32 / int(bits)
	return (4096 + perWord - 1) / perWord
}

// init sets the array to a single block (new PalettedBlockArray(block)).
func (a *pmArray) init(v uint32) {
	a.bits = 0
	a.palette = append(a.palette[:0], v)
	a.words = a.words[:0]
}

// load sets the array to the one PHP built with fromData() (or got from a chunk): words as given.
func (a *pmArray) load(bits uint8, words []byte, palette []uint32) {
	a.bits = bits
	a.palette = append(a.palette[:0], palette...)
	n := pmWordCount(bits)
	if cap(a.words) < n {
		a.words = make([]uint32, n)
	}
	a.words = a.words[:n]
	for i := range a.words {
		a.words[i] = binary.LittleEndian.Uint32(words[i*4:])
	}
}

func (a *pmArray) offset(idx int) int {
	if a.bits == 0 {
		return 0
	}
	perWord := 32 / int(a.bits)
	return int((a.words[idx/perWord] >> (uint(idx%perWord) * uint(a.bits))) & (1<<a.bits - 1))
}

func (a *pmArray) setOffset(idx, p int) {
	perWord := 32 / int(a.bits)
	w := idx / perWord
	shift := uint(idx%perWord) * uint(a.bits)
	mask := uint32(1)<<a.bits - 1
	a.words[w] = a.words[w]&^(mask<<shift) | uint32(p)<<shift
}

// set is BlockArrayContainer::set(): false if the array cannot be replicated (a full 16-bit palette, where
// chunkutils2 collects garbage; never seen in practice).
func (a *pmArray) set(idx int, v uint32) bool {
	p := -1
	for i, e := range a.palette {
		if e == v {
			p = i
			break
		}
	}
	if p < 0 {
		if len(a.palette) >= pmMaxPalette(a.bits) {
			if a.bits == 16 {
				return false
			}
			// upsize to the capacity of one more entry (fastUpsize(): same palette, offsets copied, fresh words)
			bits := pmBitsFor(len(a.palette) + 1)
			old := *a
			n := pmWordCount(bits)
			words := make([]uint32, n)
			a.bits = bits
			a.words = words
			if old.bits > 0 {
				for i := 0; i < 4096; i++ {
					if o := old.offset(i); o != 0 {
						a.setOffset(i, o)
					}
				}
			}
		}
		a.palette = append(a.palette, v)
		p = len(a.palette) - 1
		if a.bits == 0 {
			// cannot happen: a 0-bit array holds one entry and was upsized above
			return false
		}
	}
	if a.bits == 0 {
		return true // the only entry: nothing stored
	}
	a.setOffset(idx, p)
	return true
}

func (a *pmArray) appendTo(w *wr) {
	w.u8(a.bits)
	w.u16(uint16(len(a.palette)))
	for _, v := range a.palette {
		w.u32(v)
	}
	for _, word := range a.words {
		w.u32(word)
	}
}

// pmSource is a section as BEGIN sent it, decoded on its first write (most sections are never written).
type pmSource struct {
	blockBits  uint8 // 0xff: no block layer
	blockWords []byte
	blockPal   []byte // (u32 pm, u16 dry, u16 wet) entries
	liqBits    uint8  // 0xff: no liquid layer
	liqWords   []byte
	liqPal     []byte // u32 pm entries
}

// pmSection replicates a PHP SubChunk's block layers (SubChunk::setBlockStateId() / setLiquidLayerStateId()).
type pmSection struct {
	loaded bool
	broken bool // not replicable any more: its writes are sent one by one
	layers int
	l      [2]pmArray

	// this batch: native writes, and the layers PHP has before them (checked by PHP before installing new ones)
	batchWrites  int
	beforeLayers int
	beforeBits   [2]uint8
	beforeCount  [2]int
}

type pmLayers struct {
	empty, water uint32 // PHP's empty block id (air) and water state
	src          []pmSource
	sec          []pmSection
	touched      []int // sections written this batch, in first-write order
	tmp          []uint32
}

func newPmLayers(sections int) *pmLayers {
	return &pmLayers{src: make([]pmSource, 9*sections), sec: make([]pmSection, 9*sections)}
}

// begin forgets the sections of the previous decoration (their sources are set by BEGIN).
func (p *pmLayers) begin() {
	for i := range p.sec {
		p.sec[i].loaded = false
		p.sec[i].broken = false
		p.sec[i].batchWrites = 0
	}
	p.touched = p.touched[:0]
}

func (p *pmLayers) section(i int) (*pmSection, error) {
	s := &p.sec[i]
	if s.loaded {
		return s, nil
	}
	src := &p.src[i]
	s.loaded = true
	s.broken = false
	s.layers = 0
	if src.blockBits == 0xff {
		return s, nil
	}
	pal := p.tmp[:0]
	for k := 0; k+8 <= len(src.blockPal); k += 8 {
		pal = append(pal, binary.LittleEndian.Uint32(src.blockPal[k:]))
	}
	if len(src.blockWords) != pmWordCount(src.blockBits)*4 {
		return nil, errors.New("bad block layer in BEGIN")
	}
	s.l[0].load(src.blockBits, src.blockWords, pal)
	s.layers = 1
	if src.liqBits != 0xff {
		pal = pal[:0]
		for k := 0; k+4 <= len(src.liqPal); k += 4 {
			pal = append(pal, binary.LittleEndian.Uint32(src.liqPal[k:]))
		}
		if len(src.liqWords) != pmWordCount(src.liqBits)*4 {
			return nil, errors.New("bad liquid layer in BEGIN")
		}
		s.l[1].load(src.liqBits, src.liqWords, pal)
		s.layers = 2
	}
	p.tmp = pal
	return s, nil
}

// write replicates setBlockStateId(block) then setLiquidLayerStateId(water ? water : empty) at a block of a section
// (idx (x << 8) | (z << 4) | y). native: a write of this batch's native features.
func (p *pmLayers) write(i, idx int, block uint32, water, native bool) error {
	s, err := p.section(i)
	if err != nil {
		return err
	}
	if native {
		if s.batchWrites == 0 {
			p.touched = append(p.touched, i)
			s.beforeLayers = s.layers
			for k := 0; k < 2; k++ {
				if k < s.layers {
					s.beforeBits[k] = s.l[k].bits
					s.beforeCount[k] = len(s.l[k].palette)
				}
			}
		}
		s.batchWrites++
	}
	if s.broken {
		return nil
	}
	if s.layers == 0 {
		s.l[0].init(p.empty)
		s.layers = 1
	}
	if !s.l[0].set(idx, block) {
		s.broken = true
		return nil
	}
	liquid := p.empty
	if water {
		liquid = p.water
	}
	if s.layers < 2 {
		if liquid == p.empty {
			return nil
		}
		s.l[1].init(p.empty)
		s.layers = 2
	}
	if !s.l[1].set(idx, liquid) {
		s.broken = true
	}
	return nil
}
