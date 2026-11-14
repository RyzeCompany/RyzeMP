package decor

import "encoding/binary"

// type/MonsterRoomFeature.php
type monsterRoomFeature struct {
	cannotReplace                         []bool
	cobblestone, mossyCobblestone         int
	chest, chestBlock, spawner, spawnerBl int
}

var monsterRoomMobs = [4]string{"minecraft:skeleton", "minecraft:zombie", "minecraft:zombie", "minecraft:spider"}

func newMonsterRoom(reg *Registry, json obj) (Feature, error) {
	b := reg.B
	return &monsterRoomFeature{
		cannotReplace:    b.TagMask("minecraft:features_cannot_replace"),
		cobblestone:      b.MustDefault("cobblestone"),
		mossyCobblestone: b.MustDefault("mossy_cobblestone"),
		chest:            b.MustDefault("chest"),
		chestBlock:       b.MustBlock("chest"),
		spawner:          b.MustDefault("spawner"),
		spawnerBl:        b.MustBlock("spawner"),
	}, nil
}

func (f *monsterRoomFeature) safeSetBlock(c *Ctx, x, y, z, state int) bool {
	if f.cannotReplace[c.B.BlockOf[c.Level.GetState(x, y, z)]] {
		return false
	}
	c.Level.SetState(x, y, z, state)
	return true
}

// lootRecord is a BlockEntityLoot record for PHP.
func lootRecord(tile, table string, seed int64) []byte {
	w := &wr{b: []byte{BlockEntityLoot}}
	w.str(tile)
	w.str(table)
	w.b = binary.LittleEndian.AppendUint64(w.b, uint64(seed))
	return w.b
}

func (f *monsterRoomFeature) Place(c *Ctx, x, y, z int) bool {
	level := c.Level
	b := c.B
	r := c.R
	xr := r.NextBoundedInt(2) + 2
	minX, maxX := -xr-1, xr+1
	zr := r.NextBoundedInt(2) + 2
	minZ, maxZ := -zr-1, zr+1
	holes := 0
	for dx := minX; dx <= maxX; dx++ {
		for dy := -1; dy <= 4; dy++ {
			for dz := minZ; dz <= maxZ; dz++ {
				solid := b.IsSolid(level.GetState(x+dx, y+dy, z+dz))
				if (dy == -1 || dy == 4) && !solid {
					return false
				}
				if (dx == minX || dx == maxX || dz == minZ || dz == maxZ) && dy == 0 &&
					level.IsEmptyBlock(x+dx, y, z+dz) && level.IsEmptyBlock(x+dx, y+1, z+dz) {
					holes++
				}
			}
		}
	}
	if holes < 1 || holes > 5 {
		return false
	}
	air := b.CaveAir
	for dx := minX; dx <= maxX; dx++ {
		for dy := 3; dy >= -1; dy-- {
			for dz := minZ; dz <= maxZ; dz++ {
				px, py, pz := x+dx, y+dy, z+dz
				wall := level.GetState(px, py, pz)
				if dx == minX || dy == -1 || dz == minZ || dx == maxX || dz == maxZ {
					if py >= level.MinY && !b.IsSolid(level.GetState(px, py-1, pz)) {
						level.SetState(px, py, pz, air)
					} else if b.IsSolid(wall) && int(b.BlockOf[wall]) != f.chestBlock {
						if dy == -1 && r.NextBoundedInt(4) != 0 {
							f.safeSetBlock(c, px, py, pz, f.mossyCobblestone)
						} else {
							f.safeSetBlock(c, px, py, pz, f.cobblestone)
						}
					}
				} else if int(b.BlockOf[wall]) != f.chestBlock && int(b.BlockOf[wall]) != f.spawnerBl {
					f.safeSetBlock(c, px, py, pz, air)
				}
			}
		}
	}
	for cc := 0; cc < 2; cc++ {
		for i := 0; i < 3; i++ {
			a := r.NextBoundedInt(xr*2 + 1)
			xc := x + a - xr
			a = r.NextBoundedInt(zr*2 + 1)
			zc := z + a - zr
			if !level.IsEmptyBlock(xc, y, zc) {
				continue
			}
			walls := 0
			for _, d := range PlaneHorizontal {
				if b.IsSolid(level.GetState(xc+StepX[d], y, zc+StepZ[d])) {
					walls++
				}
			}
			if walls != 1 {
				continue
			}
			if f.safeSetBlock(c, xc, y, zc, f.reorientChest(c, xc, y, zc)) {
				level.SetBlockEntity(xc, y, zc, lootRecord("Chest", "minecraft:chests/simple_dungeon", r.NextLong()))
			}
			break
		}
	}
	if f.safeSetBlock(c, x, y, z, f.spawner) {
		mob := monsterRoomMobs[r.NextBoundedInt(len(monsterRoomMobs))]
		w := &wr{b: []byte{BlockEntitySpawner}}
		w.str(mob)
		level.SetBlockEntity(x, y, z, w.b)
	}
	return true
}

// reorientChest is StructurePiece.reorient() for a chest.
func (f *monsterRoomFeature) reorientChest(c *Ctx, x, y, z int) int {
	b := c.B
	level := c.Level
	solidNeighbor := -1
	for _, d := range PlaneHorizontal {
		state := level.GetState(x+StepX[d], y, z+StepZ[d])
		if int(b.BlockOf[state]) == f.chestBlock {
			return f.chest
		}
		if c.Surv.IsSolidRender(state) {
			if solidNeighbor >= 0 {
				solidNeighbor = -1
				break
			}
			solidNeighbor = d
		}
	}
	if solidNeighbor >= 0 {
		return b.With(f.chest, "facing", DirNames[Opposite[solidNeighbor]])
	}
	name, ok := b.GetProperty(f.chest, "facing")
	if !ok {
		name = "north"
	}
	lockDir, err := directionFromName(name)
	if err != nil {
		panic(err)
	}
	solidAt := func(d int) bool { return c.Surv.IsSolidRender(level.GetState(x+StepX[d], y, z+StepZ[d])) }
	if solidAt(lockDir) {
		lockDir = Opposite[lockDir]
	}
	if solidAt(lockDir) {
		lockDir = clockWise(lockDir)
	}
	if solidAt(lockDir) {
		lockDir = Opposite[lockDir]
	}
	return b.With(f.chest, "facing", DirNames[lockDir])
}
