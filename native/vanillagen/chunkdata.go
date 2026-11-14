package main

import (
	"errors"

	"github.com/PocketMine-MP-Reborn/PocketMine-MP/native/vanillagen/worldgen"
)

// Extra chunk data of protocol 7, written so that PocketMine-MP drops it into its chunks without a per-block loop
// (see VanillaGenerator::writeChunk() and FeatureDecorator::recordTerrain()).

// Height classes of the CLASSES message: what FeatureDecorator::computeTerrainHeights() sees for a material (PHP maps
// the material to its PocketMine block and back to a Java state).
const (
	heightClassAir    = 0 // isAir(): skipped
	heightClassSolid  = 1 // not air, not blocks_motion_in_heightmap: WORLD_SURFACE_WG only
	heightClassMotion = 2 // blocks_motion_in_heightmap: WORLD_SURFACE_WG and OCEAN_FLOOR_WG
)

// paletteBits is the bits per block chunkutils2 picks for a palette of n entries (BlockArrayContainer::
// getOptimalBitsPerBlock(), which a PalettedBlockArray reaches by set() calls starting from a single entry).
func paletteBits(n int) uint8 {
	for _, b := range [...]uint8{0, 1, 2, 3, 4, 5, 6, 8, 16} {
		if paletteCapacity(b) >= n {
			return b
		}
	}
	return 16
}

// paletteCapacity is PalettedBlockArray::MAX_PALETTE_SIZE for a bits-per-block value.
func paletteCapacity(bits uint8) int {
	if bits >= 12 {
		return 4096
	}
	return 1 << bits
}

// wordBytes is the size of the word array of a PalettedBlockArray (u32 words, blocks packed from the low bits).
func wordBytes(bits uint8) int {
	if bits == 0 {
		return 0
	}
	perWord := 32 / int(bits)
	return (4096 + perWord - 1) / perWord * 4
}

// appendBiomeSections writes the biome array of every 16-block subchunk as PocketMine builds it from the cells (see
// protocol.go): per section u8 bits, u16 p, u32[p] Bedrock biome ids, u8[wordBytes(bits)] words. The palette is in
// the order a PalettedBlockArray filled block by block (x, then z, then y) gets it, and the bits are the ones its
// set() calls reach, so PalettedBlockArray::fromData() gives the same array byte for byte.
func appendBiomeSections(w *writer, chunk *worldgen.Chunk, bedrock []uint32) {
	quartsY := chunk.Height >> 2
	sections := chunk.Height >> 4
	var palette []uint32
	var cellIndex [64]uint8 // palette offset per cell (qx << 4) | (qz << 2) | qy
	var words []uint32
	for s := 0; s < sections; s++ {
		palette = palette[:0]
		q0 := s << 2
		for qx := 0; qx < 4; qx++ {
			for qz := 0; qz < 4; qz++ {
				column := ((qx << 2) | qz) * quartsY
				for qy := 0; qy < 4; qy++ {
					id := bedrock[chunk.Biomes[column+q0+qy]]
					p := -1
					for i, v := range palette {
						if v == id {
							p = i
							break
						}
					}
					if p < 0 {
						palette = append(palette, id)
						p = len(palette) - 1
					}
					cellIndex[(qx<<4)|(qz<<2)|qy] = uint8(p)
				}
			}
		}
		bits := paletteBits(len(palette))
		w.u8(bits)
		w.u16(uint16(len(palette)))
		for _, id := range palette {
			w.u32(id)
		}
		if bits == 0 {
			continue
		}
		perWord := 32 / int(bits)
		n := wordBytes(bits) / 4
		if cap(words) < n {
			words = make([]uint32, n)
		}
		words = words[:n]
		clear(words)
		for i := 0; i < 4096; i++ {
			// i = (x << 8) | (z << 4) | y
			cell := ((i >> 10) << 4) | (((i >> 6) & 3) << 2) | ((i >> 2) & 3)
			words[i/perWord] |= uint32(cellIndex[cell]) << (uint(i%perWord) * uint(bits))
		}
		for _, word := range words {
			w.u32(word)
		}
	}
}

// appendTerrainHeights writes FeatureDecorator::recordTerrain()'s packed heights of a chunk: u16[256] WORLD_SURFACE_WG
// then u16[256] OCEAN_FLOOR_WG, first free y above the highest matching block minus minY (0: none), index x | z << 4.
func appendTerrainHeights(w *writer, chunk *worldgen.Chunk, classes []byte) {
	h := chunk.Height
	var floor [256]uint16
	for i := 0; i < 256; i++ {
		x := i & 15
		z := i >> 4
		column := chunk.Materials[((x<<4)|z)*h : ((x<<4)|z)*h+h]
		surface := 0
		of := 0
		for y := h - 1; y >= 0; y-- {
			c := classes[column[y]]
			if c == heightClassAir {
				continue
			}
			if surface == 0 {
				surface = y + 1
			}
			if c == heightClassMotion {
				of = y + 1
				break
			}
		}
		w.u16(uint16(surface))
		floor[i] = uint16(of)
	}
	for _, v := range floor {
		w.u16(v)
	}
}

// readClasses reads a CLASSES message: u16 n, u8[n] height class per material of the READY palette.
func readClasses(r *reader, paletteSize int) ([]byte, error) {
	n := int(r.u16())
	classes := r.take(n)
	if r.err != nil || len(r.b) != 0 || n != paletteSize || n > 256 {
		return nil, errors.New("malformed CLASSES")
	}
	out := make([]byte, 256) // materials are bytes: unknown ones count as air (never sent by a valid chunk)
	for i, c := range classes {
		if c > heightClassMotion {
			return nil, errors.New("invalid height class")
		}
		out[i] = c
	}
	return out, nil
}

// readBedrockBiomes reads the Bedrock biome id of every biome of the generator (HELLO with FLAG_PACKED_BIOMES).
func readBedrockBiomes(r *reader) []uint32 {
	n := int(r.u16())
	ids := make([]uint32, n)
	for i := range ids {
		ids[i] = r.u32()
	}
	return ids
}
