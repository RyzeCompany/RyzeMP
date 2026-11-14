package decor

import (
	"encoding/json"
	"math"
)

// geodeFeature is type/GeodeFeature.php.
type geodeFeature struct {
	filling, innerLayer, alternateInnerLayer, middleLayer, outerLayer StateProvider
	innerPlacements                                                   []int
	cannotReplace, invalidBlocks                                      []bool
	fillingLayer, innerLayerSize, middleLayerSize, outerLayerSize     float64
	generateCrackChance, baseCrackSize                                float64
	crackPointOffset                                                  int
	usePotentialPlacementsChance, useAlternateLayer0Chance            float64
	placementsRequireLayer0Alternate                                  bool
	outerWallDistance, distributionPoints, pointOffset                *IntProvider
	minGenOffset, maxGenOffset                                        int
	noiseMultiplier                                                   float64
	invalidBlocksThreshold                                            int

	noise     *floatNoise
	noiseSeed int64
}

func uniformInt(min, max int) *IntProvider {
	return &IntProvider{Sample: func(r *Random) int { return r.NextBoundedInt(max-min+1) + min }, Min: min, Max: max}
}

func newGeode(reg *Registry, js obj) (Feature, error) {
	b := reg.B
	blocks, ok := asObj(js["blocks"])
	if !ok {
		return nil, errExpected("blocks")
	}
	f := &geodeFeature{}
	var err error
	providers := []struct {
		dst *StateProvider
		key string
	}{
		{&f.filling, "filling_provider"}, {&f.innerLayer, "inner_layer_provider"}, {&f.alternateInnerLayer, "alternate_inner_layer_provider"},
		{&f.middleLayer, "middle_layer_provider"}, {&f.outerLayer, "outer_layer_provider"},
	}
	for _, p := range providers {
		if *p.dst, err = reg.ParseStateProvider(blocks[p.key]); err != nil {
			return nil, err
		}
	}
	list, err := jArray(blocks, "inner_placements")
	if err != nil {
		return nil, err
	}
	for _, s := range list {
		st, err := b.StateFromJSON(s)
		if err != nil {
			return nil, err
		}
		f.innerPlacements = append(f.innerPlacements, st)
	}
	set, err := b.BlockSet(blocks["cannot_replace"])
	if err != nil {
		return nil, err
	}
	f.cannotReplace = blockMask(b, set)
	if set, err = b.BlockSet(blocks["invalid_blocks"]); err != nil {
		return nil, err
	}
	f.invalidBlocks = blockMask(b, set)
	layers, _ := asObj(js["layers"])
	if layers == nil {
		layers = obj{}
	}
	crack, _ := asObj(js["crack"])
	if crack == nil {
		crack = obj{}
	}
	var errs [16]error
	f.fillingLayer, errs[0] = jFloatDef(layers, "filling", 1.7)
	f.innerLayerSize, errs[1] = jFloatDef(layers, "inner_layer", 2.2)
	f.middleLayerSize, errs[2] = jFloatDef(layers, "middle_layer", 3.2)
	f.outerLayerSize, errs[3] = jFloatDef(layers, "outer_layer", 4.2)
	f.generateCrackChance, errs[4] = jFloatDef(crack, "generate_crack_chance", 1.0)
	f.baseCrackSize, errs[5] = jFloatDef(crack, "base_crack_size", 2.0)
	f.crackPointOffset, errs[6] = jIntDef(crack, "crack_point_offset", 2)
	f.usePotentialPlacementsChance, errs[7] = jFloatDef(js, "use_potential_placements_chance", 0.35)
	f.useAlternateLayer0Chance, errs[8] = jFloatDef(js, "use_alternate_layer0_chance", 0.0)
	f.placementsRequireLayer0Alternate = jBoolDef(js, "placements_require_layer0_alternate", true)
	f.minGenOffset, errs[9] = jIntDef(js, "min_gen_offset", -16)
	f.maxGenOffset, errs[10] = jIntDef(js, "max_gen_offset", 16)
	f.noiseMultiplier, errs[11] = jFloatDef(js, "noise_multiplier", 0.05)
	f.invalidBlocksThreshold, errs[12] = jInt(js, "invalid_blocks_threshold")
	f.outerWallDistance, errs[13] = intProviderDef(js, "outer_wall_distance", 4, 5)
	f.distributionPoints, errs[14] = intProviderDef(js, "distribution_points", 3, 4)
	f.pointOffset, errs[15] = intProviderDef(js, "point_offset", 1, 2)
	if err := firstErr(errs[:]...); err != nil {
		return nil, err
	}
	return f, nil
}

func intProviderDef(o obj, key string, min, max int) (*IntProvider, error) {
	if o[key] == nil {
		return uniformInt(min, max), nil
	}
	return parseIntProvider(o[key])
}

func (f *geodeFeature) safeSetBlock(c *Ctx, x, y, z, state int) {
	if state < 0 {
		return
	}
	if f.cannotReplace[c.B.BlockOf[c.Level.GetState(x, y, z)]] {
		return
	}
	c.Level.SetState(x, y, z, state)
}

type geodePoint struct{ x, y, z, offset int }

func (f *geodeFeature) Place(c *Ctx, x, y, z int) bool {
	r := c.R
	level := c.Level
	b := c.B
	numPoints := f.distributionPoints.Sample(r)
	if f.noise == nil || f.noiseSeed != c.Seed {
		n, err := newFloatNoise(map[string]any{
			"base_octave":         json.Number("-4"),
			"amplitude_modifiers": []any{json.Number("1.0")},
			"normalize":           "legacy",
		}, c.Seed)
		if err != nil {
			panic(err)
		}
		f.noise = n
		f.noiseSeed = c.Seed
	}
	noise := f.noise
	crackSizeAdjustment := float64(numPoints) / float64(f.outerWallDistance.Max)
	innerAir := 1.0 / math.Sqrt(f.fillingLayer)
	innermostBlockLayer := 1.0 / math.Sqrt(f.innerLayerSize+crackSizeAdjustment)
	innerCrust := 1.0 / math.Sqrt(f.middleLayerSize+crackSizeAdjustment)
	outerCrust := 1.0 / math.Sqrt(f.outerLayerSize+crackSizeAdjustment)
	extra := 0.0
	if numPoints > 3 {
		extra = crackSizeAdjustment
	}
	crackSize := 1.0 / math.Sqrt((f.baseCrackSize+r.NextDouble()/2.0)+extra)
	shouldGenerateCrack := r.NextFloat() < f.generateCrackChance
	invalid := 0
	points := make([]geodePoint, 0, numPoints)
	for i := 0; i < numPoints; i++ {
		px := x + f.outerWallDistance.Sample(r)
		py := y + f.outerWallDistance.Sample(r)
		pz := z + f.outerWallDistance.Sample(r)
		state := level.GetState(px, py, pz)
		if b.IsAir(state) || f.invalidBlocks[b.BlockOf[state]] {
			invalid++
			if invalid > f.invalidBlocksThreshold {
				return false
			}
		}
		points = append(points, geodePoint{px, py, pz, f.pointOffset.Sample(r)})
	}
	var crackPoints [][3]int
	if shouldGenerateCrack {
		offsetIndex := r.NextBoundedInt(4)
		crackOffset := numPoints*2 + 1
		var cx, cz int
		switch offsetIndex {
		case 0:
			cx, cz = crackOffset, 0
		case 1:
			cx, cz = 0, crackOffset
		case 2:
			cx, cz = crackOffset, crackOffset
		}
		for _, cy := range [3]int{7, 5, 1} {
			crackPoints = append(crackPoints, [3]int{x + cx, y + cy, z + cz})
		}
	}
	var potentialCrystals [][3]int
	skipMargin := float64(float64(float64(numPoints)*f.noiseMultiplier) * 2.0)
	for pz := z + f.minGenOffset; pz <= z+f.maxGenOffset; pz++ {
		for py := y + f.minGenOffset; py <= y+f.maxGenOffset; py++ {
			for px := x + f.minGenOffset; px <= x+f.maxGenOffset; px++ {
				//the noise only shifts each term by +-noiseMultiplier * |noise| (|noise| < 2): skip the (slow) noise far
				//outside the shell; nothing is placed and no random is drawn there
				base := 0.0
				for _, q := range points {
					dx := px - q.x
					dy := py - q.y
					dz := pz - q.z
					base += 1.0 / math.Sqrt(float64(dx*dx+dy*dy+dz*dz)+float64(q.offset))
				}
				if base+skipMargin < outerCrust {
					continue
				}
				noiseOffset := float64(noise.get(float64(px), float64(py), float64(pz)) * f.noiseMultiplier)
				distSumShell := 0.0
				for _, q := range points {
					dx := px - q.x
					dy := py - q.y
					dz := pz - q.z
					distSumShell += 1.0/math.Sqrt(float64(dx*dx+dy*dy+dz*dz)+float64(q.offset)) + noiseOffset
				}
				if distSumShell < outerCrust {
					continue
				}
				if distSumShell >= innerAir {
					f.safeSetBlock(c, px, py, pz, f.filling(c, r, px, py, pz))
					continue
				}
				distSumCrack := 0.0
				for _, q := range crackPoints {
					dx := px - q[0]
					dy := py - q[1]
					dz := pz - q[2]
					distSumCrack += 1.0/math.Sqrt(float64(dx*dx+dy*dy+dz*dz)+float64(f.crackPointOffset)) + noiseOffset
				}
				if shouldGenerateCrack && distSumCrack >= crackSize {
					f.safeSetBlock(c, px, py, pz, b.Air)
				} else if distSumShell >= innermostBlockLayer {
					useAlternate := r.NextFloat() < f.useAlternateLayer0Chance
					if useAlternate {
						f.safeSetBlock(c, px, py, pz, f.alternateInnerLayer(c, r, px, py, pz))
					} else {
						f.safeSetBlock(c, px, py, pz, f.innerLayer(c, r, px, py, pz))
					}
					if (!f.placementsRequireLayer0Alternate || useAlternate) && r.NextFloat() < f.usePotentialPlacementsChance {
						potentialCrystals = append(potentialCrystals, [3]int{px, py, pz})
					}
				} else if distSumShell >= innerCrust {
					f.safeSetBlock(c, px, py, pz, f.middleLayer(c, r, px, py, pz))
				} else if distSumShell >= outerCrust {
					f.safeSetBlock(c, px, py, pz, f.outerLayer(c, r, px, py, pz))
				}
			}
		}
	}
	count := len(f.innerPlacements)
	for _, p := range potentialCrystals {
		state := f.innerPlacements[r.NextBoundedInt(count)]
		for d := 0; d < 6; d++ {
			if b.HasProperty(state, "facing") {
				state = b.With(state, "facing", DirNames[d])
			}
			tx := p[0] + StepX[d]
			ty := p[1] + StepY[d]
			tz := p[2] + StepZ[d]
			target := level.GetState(tx, ty, tz)
			if b.HasProperty(state, "waterlogged") {
				state = b.WithBool(state, "waterlogged", b.FluidIsSource(target))
			}
			if b.IsAir(target) || (int(b.BlockOf[target]) == b.WaterBlock && b.FluidIsSource(target)) {
				f.safeSetBlock(c, tx, ty, tz, state)
				break
			}
		}
	}
	return true
}
