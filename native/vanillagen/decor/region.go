package decor

import "math/bits"

// Heightmap types (FeatureLevel::*).
const (
	WorldSurfaceWG         = 0
	WorldSurface           = 1
	OceanFloorWG           = 2
	OceanFloor             = 3
	MotionBlocking         = 4
	MotionBlockingNoLeaves = 5
)

// RegionSize is the side of the decoration region (3x3 chunks).
const RegionSize = 48

// Region is the decoration region of feature/FeatureLevel.php: the 3x3 chunks around the decorated chunk as Java
// block states. It mirrors the PHP side's FeatureLevel exactly: the initial states are the ones PHP's getState()
// returns (sent by PHP), and every write of either side is applied to both, in order.
type Region struct {
	B                          *Blocks
	MinY, Height, MaxY         int
	SeaLevel                   int
	Seed                       int64
	sections                   int
	MinX, MinZ                 int
	CenterChunkX, CenterChunkZ int

	states   []uint16 // by index(): PocketMine's section layout, so that BEGIN copies sections
	nonEmpty []bool   // chunk index * sections + section: SubChunk::isEmptyFast() negated
	// pending marks the sections (chunk index * sections + section) whose states are not decoded yet: Decode fills
	// them on first use (st()), so that a decoration only pays for the sections it reads or writes.
	pending []bool
	Decode  func(section int, states []uint16)

	heightmaps [6][]int32 // per column dz * 48 + dx, valid where hmSet
	hmSet      [6][]bool
	hmUsed     [6]bool

	terrain [9]*[2][256]int32
	// Computed marks the chunks whose terrain heights were computed here (to be sent to PHP).
	Computed [9]bool
	// written marks the positions written with setState() during this decoration (by either side): PHP's chunk holds
	// toPm(state) there, which the terrain heightmap rebuild reads back through fromPm() (see RtBlock).
	written []uint64
	// RtBlock is the block of fromPm(toPm(state)) per state, -1 if not known yet (shared with the session).
	RtBlock []int32
	// FetchRtBlocks asks PHP for the RtBlock entries of some states.
	FetchRtBlocks func(states []int) error
	featureOnly   []bool // per block: blocks only features place (FeatureDecorator::initFeatureOnlyBlocks())

	// Writes is the log of the writes made by native features since the last flush: key, state pairs.
	Writes []uint32
	// BlockEntities are the block entity records referenced by the write log (key bit 31).
	BlockEntities [][]byte
	// LogWrites enables the write log.
	LogWrites bool

	Biomes *Biomes

	opaque [4][]bool // WS, OF, MB, MBNL opacity per state (shared, read-only)
}

func NewRegion(b *Blocks, minY, height, seaLevel int, seed int64, biomes *Biomes) *Region {
	r := &Region{B: b, MinY: minY, Height: height, MaxY: minY + height, SeaLevel: seaLevel, Seed: seed, sections: height >> 4, Biomes: biomes}
	r.states = make([]uint16, RegionSize*RegionSize*height)
	r.nonEmpty = make([]bool, 9*r.sections)
	r.pending = make([]bool, 9*r.sections)
	for t := range r.heightmaps {
		r.heightmaps[t] = make([]int32, RegionSize*RegionSize)
		r.hmSet[t] = make([]bool, RegionSize*RegionSize)
	}
	r.opaque = b.heightmapOpacity()
	r.written = make([]uint64, (RegionSize*RegionSize*height+63)/64)
	r.featureOnly = b.featureOnlyBlocks()
	return r
}

// Begin starts a decoration of a chunk; the caller then fills the states (SetInitial) and terrain heights.
func (r *Region) Begin(chunkX, chunkZ int) {
	r.CenterChunkX = chunkX
	r.CenterChunkZ = chunkZ
	r.MinX = (chunkX - 1) << 4
	r.MinZ = (chunkZ - 1) << 4
	for t := range r.hmSet {
		if r.hmUsed[t] {
			clear(r.hmSet[t])
			r.hmUsed[t] = false
		}
	}
	for i := range r.terrain {
		r.terrain[i] = nil
		r.Computed[i] = false
	}
	clear(r.written)
	r.Writes = r.Writes[:0]
	r.BlockEntities = r.BlockEntities[:0]
}

// index is the position of a block in the state array: chunk index, section, then (x << 8) | (z << 4) | y like
// PocketMine's PalettedBlockArray (dx, dz relative to the region, ry relative to MinY).
func (r *Region) index(dx, ry, dz int) int {
	return (((dx>>4)*3+(dz>>4))*r.sections+(ry>>4))<<12 | (dx&15)<<8 | (dz&15)<<4 | ry&15
}

// SectionStates gives access to the 4096 states of a section for filling (index (x << 8) | (z << 4) | y).
func (r *Region) SectionStates(chunkIndex, section int) []uint16 {
	base := (chunkIndex*r.sections + section) << 12
	return r.states[base : base+4096]
}

func (r *Region) SetSectionNonEmpty(chunkIndex, section int, nonEmpty bool) {
	r.nonEmpty[chunkIndex*r.sections+section] = nonEmpty
}

// SetSectionPending marks a section (chunk index * sections + section) as not decoded yet (see pending).
func (r *Region) SetSectionPending(section int) { r.pending[section] = true }

// st is the state at an index(), decoding its section first if needed.
func (r *Region) st(i int) uint16 {
	if r.pending[i>>12] {
		r.decode(i >> 12)
	}
	return r.states[i]
}

func (r *Region) decode(section int) {
	r.pending[section] = false
	r.Decode(section, r.states[section<<12:section<<12+4096])
}

func (r *Region) SetTerrainHeights(chunkIndex int, h *[2][256]int32) { r.terrain[chunkIndex] = h }

func (r *Region) IsOutsideBuildHeight(y int) bool { return y < r.MinY || y >= r.MaxY }
func (r *Region) IsInsideBuildHeight(y int) bool  { return y >= r.MinY && y < r.MaxY }

// EnsureCanWrite is WorldGenRegion.ensureCanWrite(): inside the 3x3 chunks and the build height.
func (r *Region) EnsureCanWrite(x, y, z int) bool {
	dx := x - r.MinX
	dz := z - r.MinZ
	return dx >= 0 && dx < RegionSize && dz >= 0 && dz < RegionSize && y >= r.MinY && y < r.MaxY
}

func (r *Region) IsInRegion(x, z int) bool {
	dx := x - r.MinX
	dz := z - r.MinZ
	return dx >= 0 && dx < RegionSize && dz >= 0 && dz < RegionSize
}

func (r *Region) GetState(x, y, z int) int {
	dx := x - r.MinX
	dz := z - r.MinZ
	if dx < 0 || dx >= RegionSize || dz < 0 || dz >= RegionSize || y < r.MinY || y >= r.MaxY {
		return r.B.VoidAir
	}
	return int(r.st(r.index(dx, y-r.MinY, dz)))
}

// SetState is WorldGenRegion.setBlock(); false if the position cannot be written.
func (r *Region) SetState(x, y, z, state int) bool {
	dx := x - r.MinX
	dz := z - r.MinZ
	if dx < 0 || dx >= RegionSize || dz < 0 || dz >= RegionSize || y < r.MinY || y >= r.MaxY {
		return false
	}
	key := ((y-r.MinY)*RegionSize+dz)*RegionSize + dx
	i := r.index(dx, y-r.MinY, dz)
	r.written[i>>6] |= 1 << (i & 63)
	r.apply(i, dx, y, dz, state, true)
	if r.LogWrites {
		r.Writes = append(r.Writes, uint32(key), uint32(state))
	}
	return true
}

// ApplyRemote applies a write made by the PHP side (feature or structure placed there). PHP's setPmState()
// (Bedrock-only blocks) does not update the heightmaps.
func (r *Region) ApplyRemote(key, state int, heightmaps bool) {
	dx := key % RegionSize
	dz := (key / RegionSize) % RegionSize
	y := key/(RegionSize*RegionSize) + r.MinY
	i := r.index(dx, y-r.MinY, dz)
	if heightmaps {
		r.written[i>>6] |= 1 << (i & 63)
	} else {
		// setPmState(): PHP reads back exactly the state it sent
		r.written[i>>6] &^= 1 << (i & 63)
	}
	r.apply(i, dx, y, dz, state, heightmaps)
}

func (r *Region) apply(i, dx, y, dz, state int, heightmaps bool) {
	if r.pending[i>>12] {
		r.decode(i >> 12)
	}
	r.states[i] = uint16(state)
	r.nonEmpty[((dx>>4)*3+(dz>>4))*r.sections+((y-r.MinY)>>4)] = true
	if !heightmaps {
		return
	}
	column := dz*RegionSize + dx
	for t := 0; t < 6; t++ {
		if r.hmUsed[t] && r.hmSet[t][column] {
			r.updateHeightmap(t, column, dx+r.MinX, y, dz+r.MinZ, state)
		}
	}
}

func (r *Region) IsEmptyBlock(x, y, z int) bool { return r.B.IsAir(r.GetState(x, y, z)) }
func (r *Region) IsWaterAt(x, y, z int) bool    { return r.B.IsWater(r.GetState(x, y, z)) }

func (r *Region) GetBiome(x, y, z int) int { return r.Biomes.GetBiome(x, y, z) }

func (r *Region) isOpaque(t, state int) bool {
	switch t {
	case WorldSurfaceWG, WorldSurface:
		return r.opaque[0][state]
	case OceanFloorWG, OceanFloor:
		return r.opaque[1][state]
	case MotionBlocking:
		return r.opaque[2][state]
	}
	return r.opaque[3][state]
}

// GetHeight is the first free y above the highest block matching the heightmap type (vanilla's getHeight()).
func (r *Region) GetHeight(t, x, z int) int {
	dx := x - r.MinX
	dz := z - r.MinZ
	if dx < 0 || dx >= RegionSize || dz < 0 || dz >= RegionSize {
		return r.MinY //vanilla would crash here
	}
	if t == WorldSurfaceWG || t == OceanFloorWG {
		ci := (dx>>4)*3 + (dz >> 4)
		h := r.terrain[ci]
		if h == nil {
			h = r.rebuildTerrainHeights(ci)
			r.terrain[ci] = h
			r.Computed[ci] = true
		}
		i := 0
		if t == OceanFloorWG {
			i = 1
		}
		return int(h[i][(dx&15)|((dz&15)<<4)])
	}
	column := dz*RegionSize + dx
	if r.hmUsed[t] && r.hmSet[t][column] {
		return int(r.heightmaps[t][column])
	}
	ci := (dx>>4)*3 + (dz >> 4)
	top := r.MinY
	for s := r.sections - 1; s >= 0; s-- {
		if r.nonEmpty[ci*r.sections+s] {
			top = r.MinY + (s << 4) + 16
			break
		}
	}
	height := r.MinY
	for y := top - 1; y >= r.MinY; y-- {
		if r.isOpaque(t, int(r.st(r.index(dx, y-r.MinY, dz)))) {
			height = y + 1
			break
		}
	}
	r.hmUsed[t] = true
	r.hmSet[t][column] = true
	r.heightmaps[t][column] = int32(height)
	return height
}

func (r *Region) updateHeightmap(t, column, x, y, z, state int) {
	first := int(r.heightmaps[t][column])
	if y <= first-2 {
		return
	}
	if r.isOpaque(t, state) {
		if y >= first {
			r.heightmaps[t][column] = int32(y + 1)
		}
	} else if first-1 == y {
		dx := x - r.MinX
		dz := z - r.MinZ
		for yy := y - 1; yy >= r.MinY; yy-- {
			if r.isOpaque(t, int(r.st(r.index(dx, yy-r.MinY, dz)))) {
				r.heightmaps[t][column] = int32(yy + 1)
				return
			}
		}
		r.heightmaps[t][column] = int32(r.MinY)
	}
}

// heightmapOpacity computes (once) the opacity of every state for the heightmap types.
func (b *Blocks) heightmapOpacity() [4][]bool {
	b.mu.Lock()
	if b.opacity[0] != nil {
		defer b.mu.Unlock()
		return b.opacity
	}
	b.mu.Unlock()
	motion := b.MustTag("minecraft:blocks_motion_in_heightmap")
	motionNoLeaves := b.MustTag("minecraft:blocks_motion_in_heightmap_no_leaves")
	n := len(b.BlockOf)
	var o [4][]bool
	for i := range o {
		o[i] = make([]bool, n)
	}
	for s := 0; s < n; s++ {
		block := int(b.BlockOf[s])
		fluid := b.Fluid(s) != FluidNone
		o[0][s] = !b.IsAir(s)
		o[1][s] = motion[block]
		o[2][s] = motion[block] || fluid
		o[3][s] = motionNoLeaves[block] || fluid
	}
	b.mu.Lock()
	b.opacity = o
	b.mu.Unlock()
	return o
}

// GetTerrainHeights returns the terrain heights of a chunk of the region if known (sent or computed).
func (r *Region) GetTerrainHeights(chunkIndex int) *[2][256]int32 { return r.terrain[chunkIndex] }

// rebuildTerrainHeights is FeatureDecorator::computeTerrainHeights($chunk, true) for a chunk generated by another
// worker: the first free y per column of WORLD_SURFACE_WG and OCEAN_FLOOR_WG, read from the current blocks as PHP
// reads them from its chunk (fromPm() of the PocketMine state) and ignoring the blocks only features place.
func (r *Region) rebuildTerrainHeights(ci int) *[2][256]int32 {
	b := r.B
	// written positions read back through toPm/fromPm: make sure their blocks are known
	var unknown []int
	seen := map[int]bool{}
	first := (ci * r.sections) << 12
	last := first + (r.sections << 12)
	for w := first >> 6; w < last>>6; w++ {
		bits := r.written[w]
		for bits != 0 {
			k := w<<6 | trailingZeros64(bits)
			bits &= bits - 1
			s := int(r.st(k))
			if r.RtBlock[s] < 0 && !seen[s] {
				seen[s] = true
				unknown = append(unknown, s)
			}
		}
	}
	if len(unknown) > 0 {
		if err := r.FetchRtBlocks(unknown); err != nil {
			panic(sessionError{err})
		}
	}
	top := r.MinY
	for s := r.sections - 1; s >= 0; s-- {
		if r.nonEmpty[ci*r.sections+s] {
			top = r.MinY + (s << 4) + 16
			break
		}
	}
	motion := r.opaque[1] // OCEAN_FLOOR: blocks_motion_in_heightmap, per state
	baseX := (ci / 3) << 4
	baseZ := (ci % 3) << 4
	h := &[2][256]int32{}
	for i := 0; i < 256; i++ {
		x := i & 15
		z := i >> 4
		ws, of := int32(r.MinY), int32(r.MinY)
		haveWS := false
		for y := top - 1; y >= r.MinY; y-- {
			k := r.index(baseX+x, y-r.MinY, baseZ+z)
			s := int(r.st(k))
			block := int(b.BlockOf[s])
			if r.written[k>>6]&(1<<(k&63)) != 0 {
				block = int(r.RtBlock[s])
			}
			if block == b.AirBlock || block == b.CaveAirBlock || block == b.VoidAirBlock || r.featureOnly[block] {
				continue
			}
			if !haveWS {
				ws = int32(y + 1)
				haveWS = true
			}
			if motion[b.DefaultState[block]] {
				of = int32(y + 1)
				break
			}
		}
		h[0][i] = ws
		h[1][i] = of
	}
	return h
}

// featureOnlyBlocks is FeatureDecorator::initFeatureOnlyBlocks(): per block index.
func (b *Blocks) featureOnlyBlocks() []bool {
	set := make([]bool, len(b.Names))
	for _, tag := range []string{"logs", "leaves", "flowers", "saplings", "replaceable", "replaceable_by_trees", "cave_vines", "corals", "wall_corals", "coral_plants"} {
		if b.HasTag(tag) {
			for k := range b.MustTag(tag) {
				set[k] = true
			}
		}
	}
	for _, name := range []string{
		"bee_nest", "cocoa", "brown_mushroom_block", "red_mushroom_block", "mushroom_stem", "moss_carpet", "pale_moss_carpet",
		"leaf_litter", "pumpkin", "melon", "sugar_cane", "cactus", "cactus_flower", "bamboo", "bamboo_sapling", "big_dripleaf",
		"big_dripleaf_stem", "small_dripleaf", "azalea", "flowering_azalea", "sweet_berry_bush", "pale_hanging_moss", "kelp",
		"kelp_plant", "sea_pickle", "lily_pad", "firefly_bush", "shelf_mushroom", "creaking_heart", "brown_mushroom",
		"red_mushroom", "hay_block", "spore_blossom", "hanging_roots", "glow_lichen", "vine", "snow", "pointed_dripstone",
	} {
		if i, err := b.BlockIndex(name); err == nil {
			set[i] = true
		}
	}
	//water and air are terrain
	set[b.WaterBlock] = false
	set[b.LavaBlock] = false
	set[b.AirBlock] = false
	set[b.CaveAirBlock] = false
	return set
}

// Block entity kinds sent to PHP (NativeDecorator), built there with BlockEntityData.
const (
	BlockEntityBeehive  = 1 // u8 n, u16[n] ticks in hive
	BlockEntityLoot     = 2 // str tile id, str Java loot table, i64 seed
	BlockEntitySpawner  = 3 // str entity id
	blockEntityWriteBit = 1 << 31
)

// SetBlockEntity is FeatureLevel::setBlockEntity(): the record (kind byte + payload, see the constants) is applied by
// PHP in the order of the writes. Returns false if the position cannot be written.
func (r *Region) SetBlockEntity(x, y, z int, record []byte) bool {
	if !r.EnsureCanWrite(x, y, z) {
		return false
	}
	key := ((y-r.MinY)*RegionSize+(z-r.MinZ))*RegionSize + (x - r.MinX)
	r.BlockEntities = append(r.BlockEntities, record)
	r.Writes = append(r.Writes, uint32(key)|blockEntityWriteBit, uint32(len(r.BlockEntities)-1))
	return true
}

func trailingZeros64(v uint64) int { return bits.TrailingZeros64(v) }
