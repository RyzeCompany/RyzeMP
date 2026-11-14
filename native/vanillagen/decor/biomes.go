package decor

import "math"

const (
	lcgMultiplier int64 = 6364136223846793005
	lcgIncrement  int64 = 1442695040888963407
)

// Biomes is feature/FeatureBiomes.php: the biome of a block like vanilla's BiomeManager.getBiome() (zoomed with the
// obfuscated seed's fiddle), reading the cells stored in the region's chunks and sampling the climate for the cells
// outside them or with an ambiguous Bedrock biome.
type Biomes struct {
	zoomSeed   int64
	minQuart   int
	quartsY    int
	baseQuartX int
	baseQuartZ int
	// stored cell biome per chunk index and cell ((qx << 2) | qz) * quartsY + qy: Bedrock biome id, -1 unknown
	stored [9][]int16
	// registry biome indexes per Bedrock biome id (only unambiguous ids are used)
	bedrockToJava map[int][]int
	// SampleCell is the registry biome of a cell from the climate (quart coordinates)
	SampleCell func(qx, qy, qz int) int
	cells      []int32 // ((lqx + 1) * 14 + lqz + 1) * quartsY + qy, -1 = not computed
}

func NewBiomes(zoomSeed int64, minY, height int, bedrockToJava map[int][]int, sample func(qx, qy, qz int) int) *Biomes {
	b := &Biomes{zoomSeed: zoomSeed, minQuart: minY >> 2, quartsY: height >> 2, bedrockToJava: bedrockToJava, SampleCell: sample}
	for i := range b.stored {
		b.stored[i] = make([]int16, 16*b.quartsY)
	}
	b.cells = make([]int32, 14*14*b.quartsY)
	return b
}

func (b *Biomes) Begin(centerChunkX, centerChunkZ int) {
	b.baseQuartX = (centerChunkX - 1) << 2
	b.baseQuartZ = (centerChunkZ - 1) << 2
	for i := range b.cells {
		b.cells[i] = -1
	}
}

// Stored gives the stored cells of a chunk for filling.
func (b *Biomes) Stored(chunkIndex int) []int16 { return b.stored[chunkIndex] }

// Cell is the registry biome index of the cell at quart coordinates (y clamped to the world).
func (b *Biomes) Cell(qx, qy, qz int) int {
	iy := qy - b.minQuart
	if iy < 0 {
		iy = 0
	} else if iy >= b.quartsY {
		iy = b.quartsY - 1
	}
	lqx := qx - b.baseQuartX
	lqz := qz - b.baseQuartZ
	cached := lqx >= -1 && lqx <= 12 && lqz >= -1 && lqz <= 12
	index := ((lqx+1)*14+lqz+1)*b.quartsY + iy
	if cached && b.cells[index] >= 0 {
		return int(b.cells[index])
	}
	biome := -1
	if lqx >= 0 && lqx < 12 && lqz >= 0 && lqz < 12 {
		bedrock := int(b.stored[(lqx>>2)*3+(lqz>>2)][(((lqx&3)<<2)|(lqz&3))*b.quartsY+iy])
		if bedrock >= 0 {
			if c := b.bedrockToJava[bedrock]; len(c) == 1 {
				biome = c[0]
			}
		}
	}
	if biome == -1 {
		biome = b.SampleCell(qx, b.minQuart+iy, qz)
	}
	if cached {
		b.cells[index] = int32(biome)
	}
	return biome
}

func biomeLcg(value, add int64) int64 {
	return value*(value*lcgMultiplier+lcgIncrement) + add
}

func biomeFiddle(value int64) float64 {
	return float64(float64(float64((value>>24)&1023)/1024.0-0.5) * 0.9)
}

// GetBiome is BiomeManager.getBiome(): the registry biome index at a block.
func (b *Biomes) GetBiome(x, y, z int) int {
	absX := x - 2
	absY := y - 2
	absZ := z - 2
	px := absX >> 2
	py := absY >> 2
	pz := absZ >> 2
	fx := float64(absX&3) / 4.0
	fy := float64(absY&3) / 4.0
	fz := float64(absZ&3) / 4.0
	best := 0
	bestDistance := math.Inf(1)
	for i := 0; i < 8; i++ {
		xEven := i&4 == 0
		yEven := i&2 == 0
		zEven := i&1 == 0
		cx, cy, cz := px, py, pz
		if !xEven {
			cx++
		}
		if !yEven {
			cy++
		}
		if !zEven {
			cz++
		}
		seed := b.zoomSeed
		r := biomeLcg(seed, int64(cx))
		r = biomeLcg(r, int64(cy))
		r = biomeLcg(r, int64(cz))
		r = biomeLcg(r, int64(cx))
		r = biomeLcg(r, int64(cy))
		r = biomeLcg(r, int64(cz))
		fiddleX := biomeFiddle(r)
		r = biomeLcg(r, seed)
		fiddleY := biomeFiddle(r)
		r = biomeLcg(r, seed)
		fiddleZ := biomeFiddle(r)
		dx, dy, dz := fx, fy, fz
		if !xEven {
			dx = fx - 1.0
		}
		if !yEven {
			dy = fy - 1.0
		}
		if !zEven {
			dz = fz - 1.0
		}
		dx += fiddleX
		dy += fiddleY
		dz += fiddleZ
		distance := float64(float64(dz*dz)+float64(dy*dy)) + float64(dx*dx)
		if bestDistance > distance {
			best = i
			bestDistance = distance
		}
	}
	if best&4 != 0 {
		px++
	}
	if best&2 != 0 {
		py++
	}
	if best&1 != 0 {
		pz++
	}
	return b.Cell(px, py, pz)
}
