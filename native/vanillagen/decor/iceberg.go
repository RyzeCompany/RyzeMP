package decor

import "math"

// type/IcebergFeature.php
type icebergFeature struct {
	mainState                                int
	packedIce, snowBlock, blueIce, ice, snow int
	snowBlockState                           int
}

func newIceberg(reg *Registry, json obj) (Feature, error) {
	b := reg.B
	main, err := b.StateFromJSON(json["state"])
	if err != nil {
		return nil, err
	}
	return &icebergFeature{
		mainState: main, packedIce: b.MustBlock("packed_ice"), snowBlock: b.MustBlock("snow_block"),
		blueIce: b.MustBlock("blue_ice"), ice: b.MustBlock("ice"), snow: b.MustBlock("snow"), snowBlockState: b.MustDefault("snow_block"),
	}, nil
}

// phpMinF / phpMaxF are PHP's min()/max() of two floats (the second wins only if strictly smaller/greater).
func phpMinF(a, b float64) float64 {
	if b < a {
		return b
	}
	return a
}

func phpMaxF(a, b float64) float64 {
	if b > a {
		return b
	}
	return a
}

func (f *icebergFeature) Place(c *Ctx, x, y, z int) bool {
	r := c.R
	y = c.SeaLevel
	snowOnTop := r.NextDouble() > 0.7
	shapeAngle := float64(float64(r.NextDouble()*2.0) * math.Pi)
	shapeEllipseA := 11 - r.NextBoundedInt(5)
	shapeEllipseC := 3 + r.NextBoundedInt(3)
	isEllipse := r.NextDouble() > 0.7
	var overWaterHeight int
	if isEllipse {
		overWaterHeight = r.NextBoundedInt(6) + 6
	} else {
		overWaterHeight = r.NextBoundedInt(15) + 3
	}
	if !isEllipse && r.NextDouble() > 0.9 {
		overWaterHeight += r.NextBoundedInt(19) + 7
	}
	underWaterHeight := minInt(overWaterHeight+r.NextBoundedInt(11), 18)
	a1 := r.NextBoundedInt(7)
	width := minInt(overWaterHeight+a1-r.NextBoundedInt(5), 11)
	a := 11
	if isEllipse {
		a = shapeEllipseA
	}
	for xo := -a; xo < a; xo++ {
		for zo := -a; zo < a; zo++ {
			for yOff := 0; yOff < overWaterHeight; yOff++ {
				var radius int
				if isEllipse {
					radius = icebergRadiusEllipse(yOff, overWaterHeight, width)
				} else {
					radius = icebergRadiusRound(r, yOff, overWaterHeight, width)
				}
				if isEllipse || xo < radius {
					f.generateIcebergBlock(c, x, y, z, overWaterHeight, xo, yOff, zo, radius, a, isEllipse, shapeEllipseC, shapeAngle, snowOnTop)
				}
			}
		}
	}
	f.smooth(c, x, y, z, width, overWaterHeight, isEllipse, shapeEllipseA)
	for xo := -a; xo < a; xo++ {
		for zo := -a; zo < a; zo++ {
			for yOff := -1; yOff > -underWaterHeight; yOff-- {
				newA := a
				if isEllipse {
					newA = phpIntOfFloat(math.Ceil(fround(float64(float64(a) * fround(1.0-fround(float64(yOff*yOff)/fround(float64(float64(underWaterHeight)*8.0))))))))
				}
				radius := icebergRadiusSteep(r, -yOff, underWaterHeight, width)
				if xo < radius {
					f.generateIcebergBlock(c, x, y, z, underWaterHeight, xo, yOff, zo, radius, newA, isEllipse, shapeEllipseC, shapeAngle, snowOnTop)
				}
			}
		}
	}
	var doCutOut bool
	if isEllipse {
		doCutOut = r.NextDouble() > 0.1
	} else {
		doCutOut = r.NextDouble() > 0.7
	}
	if doCutOut {
		f.generateCutOut(c, width, overWaterHeight, x, y, z, isEllipse, shapeEllipseA, shapeAngle, shapeEllipseC)
	}
	return true
}

func (f *icebergFeature) isIcebergState(block int) bool {
	return block == f.packedIce || block == f.snowBlock || block == f.blueIce
}

func (f *icebergFeature) generateCutOut(c *Ctx, width, height, x, y, z int, isEllipse bool, shapeEllipseA int, shapeAngle float64, shapeEllipseC int) {
	r := c.R
	signX, signZ := 1, 1
	if r.NextBoolean() {
		signX = -1
	}
	if r.NextBoolean() {
		signZ = -1
	}
	half := width / 2
	xOff := r.NextBoundedInt(maxInt(half-2, 1))
	if r.NextBoolean() {
		xOff = half + 1 - r.NextBoundedInt(maxInt(width-half-1, 1))
	}
	zOff := r.NextBoundedInt(maxInt(half-2, 1))
	if r.NextBoolean() {
		zOff = half + 1 - r.NextBoundedInt(maxInt(width-half-1, 1))
	}
	if isEllipse {
		xOff = r.NextBoundedInt(maxInt(shapeEllipseA-5, 1))
		zOff = xOff
	}
	lx := signX * xOff
	lz := signZ * zOff
	var angle float64
	if isEllipse {
		angle = shapeAngle + math.Pi/2
	} else {
		angle = float64(float64(r.NextDouble()*2.0) * math.Pi)
	}
	for yOff := 0; yOff < height-3; yOff++ {
		radius := icebergRadiusRound(r, yOff, height, width)
		f.carve(c, radius, yOff, x, y, z, false, angle, lx, lz, shapeEllipseA, shapeEllipseC)
	}
	for yOff := -1; yOff > -height+r.NextBoundedInt(5); yOff-- {
		radius := icebergRadiusSteep(r, -yOff, height, width)
		f.carve(c, radius, yOff, x, y, z, true, angle, lx, lz, shapeEllipseA, shapeEllipseC)
	}
}

func (f *icebergFeature) carve(c *Ctx, radius, yOff, x, y, z int, underWater bool, angle float64, lx, lz, shapeEllipseA, shapeEllipseC int) {
	level := c.Level
	b := c.B
	a := radius + 1 + shapeEllipseA/3
	cc := minInt(radius-3, 3) + shapeEllipseC/2 - 1
	for xo := -a; xo < a; xo++ {
		for zo := -a; zo < a; zo++ {
			if signedDistanceEllipse(xo, zo, lx, lz, a, cc, angle) >= 0.0 {
				continue
			}
			px, py, pz := x+xo, y+yOff, z+zo
			block := int(b.BlockOf[level.GetState(px, py, pz)])
			if f.isIcebergState(block) || block == f.snowBlock {
				if underWater {
					level.SetState(px, py, pz, b.Water)
				} else {
					level.SetState(px, py, pz, b.Air)
					if int(b.BlockOf[level.GetState(px, py+1, pz)]) == f.snow {
						level.SetState(px, py+1, pz, b.Air)
					}
				}
			}
		}
	}
}

func (f *icebergFeature) generateIcebergBlock(c *Ctx, x, y, z, height, xo, yOff, zo, radius, a int, isEllipse bool, shapeEllipseC int, shapeAngle float64, snowOnTop bool) {
	r := c.R
	var signedDist float64
	if isEllipse {
		ellipseC := shapeEllipseC
		if yOff > 0 && height-yOff <= 3 {
			ellipseC -= 4 - (height - yOff)
		}
		signedDist = signedDistanceEllipse(xo, zo, 0, 0, a, ellipseC, shapeAngle)
	} else {
		off := fround(fround(float64(10.0*phpMaxF(0.20000000298023224, phpMinF(0.800000011920929, r.NextFloat())))) / float64(radius))
		signedDist = ((off + float64(xo*xo)) + float64(zo*zo)) - float64(radius*radius)
	}
	if signedDist >= 0.0 {
		return
	}
	compareVal := -0.5
	if !isEllipse {
		compareVal = float64(-6 - r.NextBoundedInt(3))
	}
	if signedDist > compareVal && r.NextDouble() > 0.9 {
		return
	}
	f.setIcebergBlock(c, x+xo, y+yOff, z+zo, height-yOff, height, isEllipse, snowOnTop)
}

func (f *icebergFeature) setIcebergBlock(c *Ctx, x, y, z, hDiff, height int, isEllipse, snowOnTop bool) {
	level := c.Level
	b := c.B
	r := c.R
	state := level.GetState(x, y, z)
	block := int(b.BlockOf[state])
	isWater := block == b.WaterBlock
	if !b.IsAir(state) && block != f.snowBlock && block != f.ice && !isWater {
		return
	}
	randomness := !isEllipse || r.NextDouble() > 0.05
	divisor := 2
	if isEllipse {
		divisor = 3
	}
	if snowOnTop && !isWater && float64(hDiff) <= float64(r.NextBoundedInt(maxInt(1, height/divisor)))+float64(float64(height)*0.6) && randomness {
		level.SetState(x, y, z, f.snowBlockState)
	} else {
		level.SetState(x, y, z, f.mainState)
	}
}

func signedDistanceEllipse(xo, zo, ox, oz, a, c int, angle float64) float64 {
	dx := float64(xo - ox)
	dz := float64(zo - oz)
	cos := libmCos(angle)
	sin := libmSin(angle)
	u := (float64(dx*cos) - float64(dz*sin)) / float64(a)
	v := (float64(dx*sin) + float64(dz*cos)) / float64(c)
	return (float64(u*u) + float64(v*v)) - 1.0
}

func icebergRadiusRound(r *Random, yOff, height, width int) int {
	k := fround(3.5 - r.NextFloat())
	heightK := fround(float64(float64(height) * k))
	scale := fround(float64(fround(1.0-fround(float64(yOff*yOff)/heightK)) * float64(width)))
	if height > 15+r.NextBoundedInt(5) {
		tempYOff := yOff
		if yOff < 3+r.NextBoundedInt(6) {
			tempYOff = yOff / 2
		}
		scale = fround(float64(fround(1.0-fround(float64(tempYOff)/fround(float64(heightK*0.4000000059604645)))) * float64(width)))
	}
	return phpIntOfFloat(math.Ceil(fround(scale / 2.0)))
}

func icebergRadiusEllipse(yOff, height, width int) int {
	scale := fround(float64(fround(1.0-fround(float64(yOff*yOff)/float64(height))) * float64(width)))
	return phpIntOfFloat(math.Ceil(fround(scale / 2.0)))
}

func icebergRadiusSteep(r *Random, yOff, height, width int) int {
	k := fround(1.0 + fround(r.NextFloat()/2.0))
	scale := fround(float64(fround(1.0-fround(float64(yOff)/fround(float64(float64(height)*k)))) * float64(width)))
	return phpIntOfFloat(math.Ceil(fround(scale / 2.0)))
}

func (f *icebergFeature) smooth(c *Ctx, x, y, z, width, height int, isEllipse bool, shapeEllipseA int) {
	level := c.Level
	b := c.B
	a := width / 2
	if isEllipse {
		a = shapeEllipseA
	}
	for xo := -a; xo <= a; xo++ {
		for zo := -a; zo <= a; zo++ {
			for yOff := 0; yOff <= height; yOff++ {
				px, py, pz := x+xo, y+yOff, z+zo
				block := int(b.BlockOf[level.GetState(px, py, pz)])
				if !f.isIcebergState(block) && block != f.snow {
					continue
				}
				if b.IsAir(level.GetState(px, py-1, pz)) {
					level.SetState(px, py, pz, b.Air)
					level.SetState(px, py+1, pz, b.Air)
				} else if f.isIcebergState(block) {
					counter := 0
					for _, d := range [4][2]int{{-1, 0}, {1, 0}, {0, -1}, {0, 1}} {
						if !f.isIcebergState(int(b.BlockOf[level.GetState(px+d[0], py, pz+d[1])])) {
							counter++
						}
					}
					if counter >= 3 {
						level.SetState(px, py, pz, b.Air)
					}
				}
			}
		}
	}
}
