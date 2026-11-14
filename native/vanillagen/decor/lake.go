package decor

// lakeFeature is type/LakeFeature.php.
type lakeFeature struct {
	fluid, barrier                                                   StateProvider
	canPlaceFeature, canReplaceWithAirOrFluid, canReplaceWithBarrier BlockPredicate
	ice                                                              int
}

func newLake(reg *Registry, json obj) (Feature, error) {
	f := &lakeFeature{}
	var err error
	if f.fluid, err = reg.ParseStateProvider(json["fluid"]); err != nil {
		return nil, err
	}
	if f.barrier, err = reg.ParseStateProvider(json["barrier"]); err != nil {
		return nil, err
	}
	if f.canPlaceFeature, err = parseBlockPredicate(reg, json["can_place_feature"]); err != nil {
		return nil, err
	}
	if f.canReplaceWithAirOrFluid, err = parseBlockPredicate(reg, json["can_replace_with_air_or_fluid"]); err != nil {
		return nil, err
	}
	if f.canReplaceWithBarrier, err = parseBlockPredicate(reg, json["can_replace_with_barrier"]); err != nil {
		return nil, err
	}
	if f.ice, err = reg.B.DefaultOf("ice"); err != nil {
		return nil, err
	}
	return f, nil
}

func lakeIsEdge(grid *[2048]bool, xx, zz, yy int) bool {
	if grid[(xx*16+zz)*8+yy] {
		return false
	}
	return (xx < 15 && grid[((xx+1)*16+zz)*8+yy]) ||
		(xx > 0 && grid[((xx-1)*16+zz)*8+yy]) ||
		(zz < 15 && grid[(xx*16+zz+1)*8+yy]) ||
		(zz > 0 && grid[(xx*16+(zz-1))*8+yy]) ||
		(yy < 7 && grid[(xx*16+zz)*8+yy+1]) ||
		(yy > 0 && grid[(xx*16+zz)*8+(yy-1)])
}

func (f *lakeFeature) Place(c *Ctx, x, y, z int) bool {
	level := c.Level
	b := c.B
	r := c.R
	if y <= level.MinY+4 {
		return false
	}
	ox := x - 8
	oy := y - 4
	oz := z - 8
	var grid [2048]bool
	spots := r.NextBoundedInt(4) + 4
	for i := 0; i < spots; i++ {
		xr := float64(r.NextDouble()*6.0) + 3.0
		yr := float64(r.NextDouble()*4.0) + 2.0
		zr := float64(r.NextDouble()*6.0) + 3.0
		xp := float64(float64(r.NextDouble()*((16.0-xr)-2.0))+1.0) + xr/2.0
		yp := float64(float64(r.NextDouble()*((8.0-yr)-4.0))+2.0) + yr/2.0
		zp := float64(float64(r.NextDouble()*((16.0-zr)-2.0))+1.0) + zr/2.0
		for xx := 1; xx < 15; xx++ {
			for zz := 1; zz < 15; zz++ {
				for yy := 1; yy < 7; yy++ {
					xd := (float64(xx) - xp) / (xr / 2.0)
					yd := (float64(yy) - yp) / (yr / 2.0)
					zd := (float64(zz) - zp) / (zr / 2.0)
					if float64(float64(xd*xd)+float64(yd*yd))+float64(zd*zd) < 1.0 {
						grid[(xx*16+zz)*8+yy] = true
					}
				}
			}
		}
	}
	fluid := f.fluid(c, r, ox, oy, oz)
	if fluid < 0 {
		fluid = b.Water
	}
	for xx := 0; xx < 16; xx++ {
		for zz := 0; zz < 16; zz++ {
			for yy := 0; yy < 8; yy++ {
				if !lakeIsEdge(&grid, xx, zz, yy) {
					continue
				}
				state := level.GetState(ox+xx, oy+yy, oz+zz)
				block := int(b.BlockOf[state])
				if yy >= 4 && (block == b.WaterBlock || block == b.LavaBlock) {
					return false
				}
				if yy < 4 && !b.IsSolid(state) && state != fluid {
					return false
				}
				if !f.canPlaceFeature(c, ox+xx, oy+yy, oz+zz) {
					return false
				}
			}
		}
	}
	air := b.CaveAir
	for xx := 0; xx < 16; xx++ {
		for zz := 0; zz < 16; zz++ {
			for yy := 0; yy < 8; yy++ {
				if !grid[(xx*16+zz)*8+yy] {
					continue
				}
				if !f.canReplaceWithAirOrFluid(c, ox+xx, oy+yy, oz+zz) {
					continue
				}
				if yy >= 4 {
					level.SetState(ox+xx, oy+yy, oz+zz, air)
				} else {
					level.SetState(ox+xx, oy+yy, oz+zz, fluid)
				}
			}
		}
	}
	barrier := f.barrier(c, r, ox, oy, oz)
	if barrier < 0 {
		barrier = air
	}
	if !b.IsAir(barrier) {
		for xx := 0; xx < 16; xx++ {
			for zz := 0; zz < 16; zz++ {
				for yy := 0; yy < 8; yy++ {
					if !lakeIsEdge(&grid, xx, zz, yy) {
						continue
					}
					if yy >= 4 && r.NextBoundedInt(2) == 0 {
						continue
					}
					px, py, pz := ox+xx, oy+yy, oz+zz
					if b.IsSolid(level.GetState(px, py, pz)) && f.canReplaceWithBarrier(c, px, py, pz) {
						level.SetState(px, py, pz, barrier)
					}
				}
			}
		}
	}
	if b.IsWater(fluid) {
		climate := c.Reg.Climate()
		for xx := 0; xx < 16; xx++ {
			for zz := 0; zz < 16; zz++ {
				px, py, pz := ox+xx, oy+4, oz+zz
				if climate.ShouldFreeze(c, px, py, pz, false, -1) && f.canReplaceWithAirOrFluid(c, px, py, pz) {
					level.SetState(px, py, pz, f.ice)
				}
			}
		}
	}
	return true
}
