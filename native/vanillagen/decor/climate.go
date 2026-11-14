package decor

import "github.com/PocketMine-MP-Reborn/PocketMine-MP/native/vanillagen/worldgen"

// Climate is feature/BiomeClimate.php: biome temperature with the frozen modifier and height cooling.
type Climate struct {
	temperatureNoise *worldgen.SimplexNoise
	frozenNoise      [3]*worldgen.SimplexNoise
	biomeInfoNoise   *worldgen.SimplexNoise
	baseTemperature  []float64
	frozen           []bool
	precipitation    []bool
}

// Climate returns the registry's biome climate (built on first use).
func (reg *Registry) Climate() *Climate {
	if reg.climate != nil {
		return reg.climate
	}
	c := &Climate{
		temperatureNoise: worldgen.NewSimplexNoise(worldgen.NewLegacyRandom(1234)),
		biomeInfoNoise:   worldgen.NewSimplexNoise(worldgen.NewLegacyRandom(2345)),
	}
	random := worldgen.NewLegacyRandom(3456)
	for i := range c.frozenNoise {
		c.frozenNoise[i] = worldgen.NewSimplexNoise(random)
	}
	for _, name := range reg.BiomeNames {
		json, err := reg.Data.Biome(name)
		if err != nil {
			panic(err)
		}
		t, err := jFloatDef(json, "temperature", 0.5)
		if err != nil {
			panic(err)
		}
		c.baseTemperature = append(c.baseTemperature, fround(t))
		mod, _ := json["temperature_modifier"].(string)
		if json["temperature_modifier"] == nil {
			mod = "none"
		}
		c.frozen = append(c.frozen, mod == "frozen")
		p, ok := json["has_precipitation"].(bool)
		c.precipitation = append(c.precipitation, !ok || p)
	}
	reg.climate = c
	return c
}

func climateSimplex(n *worldgen.SimplexNoise, x, z float64) float64 {
	return fround(n.GetValue2D(x, z))
}

// Temperature is BiomeClimate::temperature().
func (c *Climate) Temperature(biome, x, y, z, seaLevel int) float64 {
	base := 0.5
	if biome >= 0 && biome < len(c.baseTemperature) {
		base = c.baseTemperature[biome]
	}
	if biome >= 0 && biome < len(c.frozen) && c.frozen[biome] {
		fx := float64(x)
		fz := float64(z)
		a := fround(0.0 + fround(float64(0.1428571492433548*climateSimplex(c.frozenNoise[0], float64(fx*0.05), float64(fz*0.05)))))
		b := fround(float64(0.2857142984867096 * climateSimplex(c.frozenNoise[1], float64(float64(fx*0.05)*0.5), float64(float64(fz*0.05)*0.5))))
		large := fround(fround(a+b) + fround(float64(0.5714285969734192*climateSimplex(c.frozenNoise[2], float64(float64(fx*0.05)*0.25), float64(float64(fz*0.05)*0.25)))))
		largeVariation := fround(float64(large * 7.0))
		edge := climateSimplex(c.biomeInfoNoise, float64(fx*0.2), float64(fz*0.2))
		if largeVariation+edge < 0.3 {
			small := climateSimplex(c.biomeInfoNoise, float64(fx*0.09), float64(fz*0.09))
			if small < 0.8 {
				base = 0.20000000298023224
			}
		}
	}
	snowLevel := seaLevel + 17
	if y > snowLevel {
		v := fround(float64(climateSimplex(c.temperatureNoise, fround(float64(x)/8.0), fround(float64(z)/8.0)) * 8.0))
		return fround(base - fround(fround(float64(fround(fround(v+float64(y))-float64(snowLevel))*0.05000000074505806))/40.0))
	}
	return base
}

func (c *Climate) WarmEnoughToRain(biome, x, y, z, seaLevel int) bool {
	return c.Temperature(biome, x, y, z, seaLevel) >= 0.15000000596046448
}

// ShouldFreeze is BiomeClimate::shouldFreeze() (biome < 0: the biome at the position).
func (c *Climate) ShouldFreeze(ctx *Ctx, x, y, z int, checkNeighbors bool, biome int) bool {
	level := ctx.Level
	if biome < 0 {
		biome = level.GetBiome(x, y, z)
	}
	if c.WarmEnoughToRain(biome, x, y, z, ctx.SeaLevel) {
		return false
	}
	if !level.IsInsideBuildHeight(y) {
		return false
	}
	state := level.GetState(x, y, z)
	b := ctx.B
	if int(b.BlockOf[state]) != b.WaterBlock || !b.FluidIsSource(state) {
		return false
	}
	if !checkNeighbors {
		return true
	}
	for _, d := range [4]int{West, East, North, South} {
		if !level.IsWaterAt(x+StepX[d], y, z+StepZ[d]) {
			return true
		}
	}
	return false
}

// ShouldSnow is BiomeClimate::shouldSnow() (biome < 0: the biome at the position).
func (c *Climate) ShouldSnow(ctx *Ctx, x, y, z, biome int) bool {
	level := ctx.Level
	if biome < 0 {
		biome = level.GetBiome(x, y, z)
	}
	precipitation := true
	if biome >= 0 && biome < len(c.precipitation) {
		precipitation = c.precipitation[biome]
	}
	if !precipitation || c.WarmEnoughToRain(biome, x, y, z, ctx.SeaLevel) {
		return false
	}
	if !level.IsInsideBuildHeight(y) {
		return false
	}
	state := level.GetState(x, y, z)
	b := ctx.B
	if !b.IsAir(state) && b.BlockName(state) != "minecraft:snow" {
		return false
	}
	return ctx.Surv.CanSurvive(level, b.MustDefault("snow"), x, y, z)
}
