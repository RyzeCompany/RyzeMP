package worldgen

import (
	"encoding/json"
	"fmt"
	"math"
)

// Carvers (carver/*.php): vanilla's carver stage after the material rules. Float arithmetic follows the Java float
// code with fround() after every operation, exactly like the PHP port (not Go float32, so that both round the same).

const (
	carverRange          = 4
	carverRadius         = 8
	carverDistance       = (carverRange*2 - 1) * 16
	protectedBlocksOnTop = 7

	piF     = 3.1415927410125732 // (float) Math.PI
	twoPiF  = 6.2831854820251465 // (float) (Math.PI * 2)
	halfPiF = 1.5707963705062866 // (float) (Math.PI / 2)

	sinScale = 10430.378350470453
)

var sinTable = func() []float64 {
	t := make([]float64, 65536)
	for i := range t {
		t[i] = fround(math.Sin(float64(i) * math.Pi * 2.0 / 65536.0))
	}
	return t
}()

// SinTable exposes Mth.SIN for tests.
func SinTable() []float64 { return sinTable }

func mthSin(v float64) float64 { return sinTable[phpInt(float64(v*sinScale))&65535] }
func mthCos(v float64) float64 { return sinTable[phpInt(float64(v*sinScale)+16384.0)&65535] }

func mthFloor(v float64) int {
	i := phpInt(v)
	if v < float64(i) {
		return i - 1
	}
	return i
}

// ---- value providers (carver/ValueProvider.php) ----

const (
	provConstant = iota
	provUniform
	provTrapezoid
	provBiased
	provVeryBiased
)

type valueProvider struct {
	typ    int
	params []float64
}

func floatProvider(js any) (*valueProvider, error) {
	if n, ok := js.(json.Number); ok {
		f, err := numberToFloat(n)
		return &valueProvider{provConstant, []float64{fround(f)}}, err
	}
	obj, ok := js.(map[string]any)
	if !ok {
		return nil, fmt.Errorf("invalid float provider")
	}
	typ, err := jsonString(obj, "type", nil)
	if err != nil {
		return nil, err
	}
	get := func(keys ...string) ([]float64, error) {
		var out []float64
		for _, k := range keys {
			v, err := jsonFloat(obj, k, nil)
			if err != nil {
				return nil, err
			}
			out = append(out, fround(v))
		}
		return out, nil
	}
	var p []float64
	var t int
	switch stripNamespace(typ) {
	case "constant":
		t = provConstant
		p, err = get("value")
	case "uniform":
		t = provUniform
		p, err = get("min_inclusive", "max_exclusive")
	case "trapezoid":
		t = provTrapezoid
		p, err = get("min", "max", "plateau")
	default:
		return nil, fmt.Errorf("unsupported float provider type %q", typ)
	}
	if err != nil {
		return nil, err
	}
	return &valueProvider{t, p}, nil
}

func intProvider(js any) (*valueProvider, error) {
	if n, ok := js.(json.Number); ok {
		if !isJSONInt(n) {
			return nil, fmt.Errorf("invalid int provider")
		}
		v, err := n.Int64()
		return &valueProvider{provConstant, []float64{float64(v)}}, err
	}
	obj, ok := js.(map[string]any)
	if !ok {
		return nil, fmt.Errorf("invalid int provider")
	}
	typ, err := jsonString(obj, "type", nil)
	if err != nil {
		return nil, err
	}
	typ = stripNamespace(typ)
	if typ == "constant" {
		v, err := jsonInt(obj, "value", nil)
		return &valueProvider{provConstant, []float64{float64(v)}}, err
	}
	lo, e1 := jsonInt(obj, "min_inclusive", nil)
	hi, e2 := jsonInt(obj, "max_inclusive", nil)
	if err := firstErr(e1, e2); err != nil {
		return nil, err
	}
	p := []float64{float64(lo), float64(hi)}
	switch typ {
	case "uniform":
		return &valueProvider{provUniform, p}, nil
	case "biased_to_bottom":
		return &valueProvider{provBiased, p}, nil
	case "very_biased_to_bottom":
		return &valueProvider{provVeryBiased, p}, nil
	}
	return nil, fmt.Errorf("unsupported int provider type %q", typ)
}

func heightProvider(js any, minY, height int) (*valueProvider, error) {
	obj, ok := js.(map[string]any)
	if !ok {
		return nil, fmt.Errorf("invalid height provider")
	}
	if _, ok := obj["type"]; !ok {
		a, err := resolveAnchor(obj, minY, height)
		return &valueProvider{provConstant, []float64{float64(a)}}, err
	}
	typ, err := jsonString(obj, "type", nil)
	if err != nil {
		return nil, err
	}
	switch stripNamespace(typ) {
	case "constant":
		a, err := resolveAnchor(obj["value"], minY, height)
		return &valueProvider{provConstant, []float64{float64(a)}}, err
	case "uniform":
		lo, e1 := resolveAnchor(obj["min_inclusive"], minY, height)
		hi, e2 := resolveAnchor(obj["max_inclusive"], minY, height)
		return &valueProvider{provUniform, []float64{float64(lo), float64(hi)}}, firstErr(e1, e2)
	}
	return nil, fmt.Errorf("unsupported height provider type %q", typ)
}

func (p *valueProvider) sampleFloat(r *LegacyRandom) float64 {
	v := p.params
	switch p.typ {
	case provConstant:
		return v[0]
	case provUniform:
		return fround(fround(float64(r.NextFloat()*fround(v[1]-v[0]))) + v[0])
	case provTrapezoid:
		rng := fround(v[1] - v[0])
		slope := fround(fround(rng-v[2]) / 2.0)
		plateau := fround(rng - slope)
		a := fround(v[0] + fround(float64(r.NextFloat()*plateau)))
		return fround(a + fround(float64(r.NextFloat()*slope)))
	}
	panic("not a float provider")
}

func (p *valueProvider) sampleInt(r *LegacyRandom) int {
	lo := int(p.params[0])
	if p.typ == provConstant {
		return lo
	}
	hi := int(p.params[1])
	switch p.typ {
	case provUniform:
		if lo > hi {
			return lo
		}
		return lo + r.NextBoundedInt(hi-lo+1)
	case provBiased:
		return lo + r.NextBoundedInt(r.NextBoundedInt(hi-lo+1)+1)
	case provVeryBiased:
		a := r.NextBoundedInt(hi - lo + 1)
		b := r.NextBoundedInt(a + 1)
		return lo + r.NextBoundedInt(b+1)
	}
	panic("not an int provider")
}

// ---- carvers ----

type worldCarver struct {
	id          string
	cave        bool
	probability float64
	y           *valueProvider

	// cave
	count, hm, vm, svm, floor, room, thickness *valueProvider
	weird                                      bool

	// canyon
	vrot, yScale, distance, hrf *valueProvider
	smoothness                  int
	vDefault, vCenter           float64
	genDepth                    int
}

func newWorldCarver(id string, js map[string]any, minY, height int) (*worldCarver, error) {
	typ, err := jsonString(js, "type", nil)
	if err != nil {
		return nil, err
	}
	prob, err := jsonFloat(js, "probability", nil)
	if err != nil {
		return nil, err
	}
	c := &worldCarver{id: id, probability: fround(prob)}
	if c.y, err = heightProvider(js["y"], minY, height); err != nil {
		return nil, err
	}
	fp := func(v any, err *error) *valueProvider {
		if *err != nil {
			return nil
		}
		var p *valueProvider
		p, *err = floatProvider(v)
		return p
	}
	switch stripNamespace(typ) {
	case "cave":
		c.cave = true
		if c.count, err = intProvider(js["count"]); err != nil {
			return nil, err
		}
		svm := js["start_vertical_radius_multiplier"]
		if svm == nil {
			svm = json.Number("1.0")
		}
		c.hm = fp(js["horizontal_radius_multiplier"], &err)
		c.vm = fp(js["vertical_radius_multiplier"], &err)
		c.svm = fp(svm, &err)
		c.floor = fp(js["floor_level"], &err)
		c.room = fp(js["room_vertical_radius_multiplier"], &err)
		c.thickness = fp(js["thickness"], &err)
		if err != nil {
			return nil, err
		}
		if c.weird, err = jsonBool(js, "weird_thickness_bias"); err != nil {
			return nil, err
		}
	case "canyon":
		shape, ok := js["shape"].(map[string]any)
		if !ok {
			return nil, fmt.Errorf("expected array for \"shape\"")
		}
		c.vrot = fp(js["vertical_rotation"], &err)
		c.yScale = fp(shape["y_scale"], &err)
		c.distance = fp(shape["distance_factor"], &err)
		c.thickness = fp(shape["thickness"], &err)
		c.hrf = fp(shape["horizontal_radius_factor"], &err)
		if err != nil {
			return nil, err
		}
		var e1, e2, e3 error
		c.smoothness, e1 = jsonInt(shape, "width_smoothness", nil)
		c.vDefault, e2 = jsonFloat(shape, "vertical_radius_default_factor", nil)
		c.vCenter, e3 = jsonFloat(shape, "vertical_radius_center_factor", nil)
		if err := firstErr(e1, e2, e3); err != nil {
			return nil, err
		}
		if c.smoothness <= 0 {
			return nil, fmt.Errorf("invalid canyon width_smoothness (%s)", id)
		}
		c.vDefault = fround(c.vDefault)
		c.vCenter = fround(c.vCenter)
		c.genDepth = height
	default:
		return nil, fmt.Errorf("unsupported carver type %q (%s)", typ, id)
	}
	return c, nil
}

func (c *worldCarver) carve(run *carvingRun, r *LegacyRandom, startX, startZ int) {
	if c.cave {
		c.carveCave(run, r, startX, startZ)
	} else {
		c.carveCanyon(run, r, startX, startZ)
	}
}

func canReach(run *carvingRun, x, z float64, step, steps int, thickness float64) bool {
	dx := x - run.middleX
	dz := z - run.middleZ
	left := float64(steps - step)
	reach := fround(fround(thickness+2.0) + 16.0)
	return float64(float64(dx*dx)+float64(dz*dz))-float64(left*left) <= float64(reach*reach)
}

func (c *worldCarver) carveCave(run *carvingRun, r *LegacyRandom, startX, startZ int) {
	count := c.count.sampleInt(r)
	for i := 0; i < count; i++ {
		x := float64((startX << 4) + r.NextBoundedInt(16))
		y := float64(c.y.sampleInt(r))
		z := float64((startZ << 4) + r.NextBoundedInt(16))
		hm := c.hm.sampleFloat(r)
		vm := c.vm.sampleFloat(r)
		svm := c.svm.sampleFloat(r)
		floorLevel := c.floor.sampleFloat(r)
		tunnels := 1
		if r.NextBoundedInt(4) == 0 {
			roomVM := c.room.sampleFloat(r)
			radius := fround(1.0 + fround(float64(r.NextFloat()*6.0)))
			hr := 1.5 + fround(float64(mthSin(halfPiF)*radius))
			run.carveEllipsoid(x+1.0, y, z, hr, float64(hr*roomVM), floorLevel, nil)
			tunnels += r.NextBoundedInt(4)
		}
		for t := 0; t < tunnels; t++ {
			yaw := fround(float64(r.NextFloat() * twoPiF))
			pitch := fround(fround(r.NextFloat()-0.5) / 4.0)
			thickness := c.thickness.sampleFloat(r)
			if c.weird && r.NextBoundedInt(10) == 0 {
				a := r.NextFloat()
				b := r.NextFloat()
				bias := fround(float64(a * b))
				thickness = fround(float64(thickness * fround(fround(float64(bias*3.0))+1.0)))
			}
			steps := carverDistance - r.NextBoundedInt(carverDistance/4)
			c.caveTunnel(run, r.NextLong(), x, y, z, hm, vm, thickness, yaw, pitch, 0, steps, svm, floorLevel)
		}
	}
}

const (
	cavePitchSteep  = 0.9200000166893005
	cavePitch       = 0.699999988079071
	caveRotStep     = 0.10000000149011612
	cavePitchSpeedD = 0.8999999761581421
	caveYawSpeedD   = 0.75

	canyonPitch       = 0.699999988079071
	canyonRotStep     = 0.05000000074505806
	canyonPitchSpeedD = 0.800000011920929
	canyonYawSpeedD   = 0.5
)

func (c *worldCarver) caveTunnel(run *carvingRun, seed int64, x, y, z, hm, vm, thickness, yaw, pitch float64, step, steps int, yScale, floorLevel float64) {
	r := NewLegacyRandom(seed)
	split := r.NextBoundedInt(steps/2) + steps/4
	steep := r.NextBoundedInt(6) == 0
	damping := cavePitch
	if steep {
		damping = cavePitchSteep
	}
	yawSpeed, pitchSpeed := 0.0, 0.0
	for i := step; i < steps; i++ {
		hr := 1.5 + fround(float64(mthSin(fround(fround(float64(piF*float64(i)))/float64(steps)))*thickness))
		vr := float64(hr * yScale)
		cosPitch := mthCos(pitch)
		x += fround(float64(mthCos(yaw) * cosPitch))
		y += mthSin(pitch)
		z += fround(float64(mthSin(yaw) * cosPitch))
		pitch = fround(float64(pitch * damping))
		pitch = fround(pitch + fround(float64(pitchSpeed*caveRotStep)))
		yaw = fround(yaw + fround(float64(yawSpeed*caveRotStep)))
		pitchSpeed = fround(float64(pitchSpeed * cavePitchSpeedD))
		yawSpeed = fround(float64(yawSpeed * caveYawSpeedD))
		a, b, cc := r.NextFloat(), r.NextFloat(), r.NextFloat()
		pitchSpeed = fround(pitchSpeed + fround(float64(fround(float64(fround(a-b)*cc))*2.0)))
		a, b, cc = r.NextFloat(), r.NextFloat(), r.NextFloat()
		yawSpeed = fround(yawSpeed + fround(float64(fround(float64(fround(a-b)*cc))*4.0)))
		if i == split && thickness > 1.0 {
			s1 := r.NextLong()
			t1 := fround(fround(float64(r.NextFloat()*0.5)) + 0.5)
			c.caveTunnel(run, s1, x, y, z, hm, vm, t1, fround(yaw-halfPiF), fround(pitch/3.0), i, steps, 1.0, floorLevel)
			s2 := r.NextLong()
			t2 := fround(fround(float64(r.NextFloat()*0.5)) + 0.5)
			c.caveTunnel(run, s2, x, y, z, hm, vm, t2, fround(yaw+halfPiF), fround(pitch/3.0), i, steps, 1.0, floorLevel)
			return
		}
		if r.NextBoundedInt(4) != 0 {
			if !canReach(run, x, z, i, steps, thickness) {
				return
			}
			run.carveEllipsoid(x, y, z, float64(hr*hm), float64(vr*vm), floorLevel, nil)
		}
	}
}

func (c *worldCarver) carveCanyon(run *carvingRun, r *LegacyRandom, startX, startZ int) {
	x := float64((startX << 4) + r.NextBoundedInt(16))
	y := float64(c.y.sampleInt(r))
	z := float64((startZ << 4) + r.NextBoundedInt(16))
	yaw := fround(float64(r.NextFloat() * twoPiF))
	pitch := c.vrot.sampleFloat(r)
	yScale := c.yScale.sampleFloat(r)
	thickness := c.thickness.sampleFloat(r)
	steps := phpInt(fround(float64(carverDistance * c.distance.sampleFloat(r))))
	c.canyonTunnel(run, r.NextLong(), x, y, z, thickness, yaw, pitch, 0, steps, yScale)
}

func (c *worldCarver) canyonTunnel(run *carvingRun, seed int64, x, y, z, thickness, yaw, pitch float64, step, steps int, yScale float64) {
	r := NewLegacyRandom(seed)
	widths := make([]float64, c.genDepth)
	factor := 1.0
	for i := range widths {
		if i == 0 || r.NextBoundedInt(c.smoothness) == 0 {
			a := r.NextFloat()
			b := r.NextFloat()
			factor = fround(1.0 + fround(float64(a*b)))
		}
		widths[i] = fround(float64(factor * factor))
	}
	yawSpeed, pitchSpeed := 0.0, 0.0
	for i := step; i < steps; i++ {
		hr := 1.5 + fround(float64(mthSin(fround(fround(float64(float64(i)*piF))/float64(steps)))*thickness))
		vr := float64(hr * yScale)
		hr = float64(hr * c.hrf.sampleFloat(r))
		center := fround(1.0 - fround(float64(math.Abs(fround(0.5-fround(float64(i)/float64(steps))))*2.0)))
		vf := fround(c.vDefault + fround(float64(c.vCenter*center)))
		rnd := fround(fround(float64(r.NextFloat()*0.25)) + 0.75)
		vr = float64(float64(vf*vr) * rnd)

		cosPitch := mthCos(pitch)
		sinPitch := mthSin(pitch)
		x += fround(float64(mthCos(yaw) * cosPitch))
		y += sinPitch
		z += fround(float64(mthSin(yaw) * cosPitch))
		pitch = fround(float64(pitch * canyonPitch))
		pitch = fround(pitch + fround(float64(pitchSpeed*canyonRotStep)))
		yaw = fround(yaw + fround(float64(yawSpeed*canyonRotStep)))
		pitchSpeed = fround(float64(pitchSpeed * canyonPitchSpeedD))
		yawSpeed = fround(float64(yawSpeed * canyonYawSpeedD))
		a, b, cc := r.NextFloat(), r.NextFloat(), r.NextFloat()
		pitchSpeed = fround(pitchSpeed + fround(float64(fround(float64(fround(a-b)*cc))*2.0)))
		a, b, cc = r.NextFloat(), r.NextFloat(), r.NextFloat()
		yawSpeed = fround(yawSpeed + fround(float64(fround(float64(fround(a-b)*cc))*4.0)))
		if r.NextBoundedInt(4) != 0 {
			if !canReach(run, x, z, i, steps, thickness) {
				return
			}
			run.carveEllipsoid(x, y, z, hr, vr, 0.0, widths)
		}
	}
}

// ---- carving of one chunk (carver/CarvingRun.php) ----

type carvingRun struct {
	middleX, middleZ     float64
	minBlockX, minBlockZ int
	carveMinY, carveMaxY int
	minY, height         int
	seaLevel             int
	seaMaterial          int
	materials            []byte
	heights              []int
	mask                 []bool
	aquifer              *Aquifer
	uncarvable           [256]bool
	grass, mycelium      int
	dirt                 int
	materialsSys         *MaterialSystem
}

func (run *carvingRun) carveEllipsoid(x, y, z, hr, vr, floorLevel float64, widths []float64) {
	limit := 16.0 + float64(hr*2.0)
	if math.Abs(x-run.middleX) > limit || math.Abs(z-run.middleZ) > limit {
		return
	}
	minX := max(mthFloor(x-hr)-run.minBlockX-1, 0)
	maxX := min(mthFloor(x+hr)-run.minBlockX, 15)
	minYEx := max(mthFloor(y-vr)-1, run.carveMinY)
	maxY := min(mthFloor(y+vr)+1, run.carveMaxY)
	minZ := max(mthFloor(z-hr)-run.minBlockZ-1, 0)
	maxZ := min(mthFloor(z+hr)-run.minBlockZ, 15)
	minY, height := run.minY, run.height
	for lx := minX; lx <= maxX; lx++ {
		dx := (float64(run.minBlockX+lx) + 0.5 - x) / hr
		for lz := minZ; lz <= maxZ; lz++ {
			dz := (float64(run.minBlockZ+lz) + 0.5 - z) / hr
			if float64(dx*dx)+float64(dz*dz) >= 1.0 {
				continue
			}
			column := (lx << 4) | lz
			base := column*height - minY
			reached := false
			for by := maxY; by > minYEx; by-- {
				dy := (float64(by) - 0.5 - y) / vr
				if widths == nil {
					if dy <= floorLevel || float64(float64(dx*dx)+float64(dy*dy))+float64(dz*dz) >= 1.0 {
						continue
					}
				} else if float64(float64(float64(dx*dx)+float64(dz*dz))*widths[by-minY-1])+float64(float64(dy*dy)/6.0) >= 1.0 {
					continue
				}
				index := base + by
				if run.mask[index] {
					continue
				}
				run.mask[index] = true
				run.carveBlock(index, column, lx, by, lz, &reached)
			}
		}
	}
}

func (run *carvingRun) carveBlock(index, column, lx, y, lz int, reached *bool) {
	m := int(run.materials[index])
	if m == run.grass || m == run.mycelium {
		*reached = true
	}
	if run.uncarvable[m] {
		return
	}
	var state int
	if run.aquifer != nil {
		state = run.aquifer.ComputeSubstance(run.minBlockX+lx, y, run.minBlockZ+lz, 0.0)
		if state == materialKeep {
			return
		}
	} else if y < min(-54, run.seaLevel) {
		state = MaterialLava
	} else if y < run.seaLevel {
		state = run.seaMaterial
	} else {
		state = MaterialAir
	}
	run.setBlock(index, column, y, state)
	if *reached && y-1 >= run.minY && int(run.materials[index-1]) == run.dirt {
		top := run.materialsSys.TopMaterial(run.heights, lx, y-1, lz, state == MaterialWater || state == MaterialLava)
		if top != ruleNone {
			run.setBlock(index-1, column, y-1, top)
		}
	}
}

func (run *carvingRun) setBlock(index, column, y, material int) {
	run.materials[index] = byte(material)
	top := run.heights[column]
	if material != MaterialAir {
		if y > top {
			run.heights[column] = y
		}
	} else if y == top {
		base := index - y
		for by := y - 1; by >= run.minY; by-- {
			if run.materials[base+by] != MaterialAir {
				run.heights[column] = by
				return
			}
		}
		run.heights[column] = run.minY - 1
	}
}

// ---- carver stage (carver/CarverSystem.php) ----

type CarverSystem struct {
	biomeCarvers [][]*worldCarver
	uncarvable   [256]bool
	grass        int
	mycelium     int
	dirt         int
	seed         int64
	tree         *ClimateBiomeTree
	climate      *[6]Eval
	biomeCache   map[int64]int
	biomeOrder   []int64
	materials    *MaterialSystem
	mask         []bool
	random       *LegacyRandom
}

const carverBiomeCacheSize = 4096

// NewCarverSystem returns nil (and no error) when the data has no carver definitions.
func NewCarverSystem(data *Data, tree *ClimateBiomeTree, climate *[6]Eval, seed int64, materials *MaterialSystem, minY, height int) (*CarverSystem, error) {
	if !data.HasCarvers() {
		return nil, nil
	}
	s := &CarverSystem{seed: seed, tree: tree, climate: climate, materials: materials, biomeCache: map[int64]int{}, random: NewLegacyRandom(0)}
	carvers := map[string]*worldCarver{}
	hasCarvers := false
	for _, name := range tree.Names() {
		b, err := data.Biome(name)
		if err != nil {
			return nil, err
		}
		var list []any
		switch v := b["carvers"].(type) {
		case nil:
		case string:
			list = []any{v}
		case []any:
			list = v
		default:
			return nil, fmt.Errorf("invalid carver list of biome %s", name)
		}
		var bc []*worldCarver
		for _, id := range list {
			sid, ok := id.(string)
			if !ok || len(sid) > 0 && sid[0] == '#' {
				return nil, fmt.Errorf("unsupported carver reference in biome %s", name)
			}
			key := "minecraft:" + stripNamespace(sid)
			c, ok := carvers[key]
			if !ok {
				js, err := data.Carver(key)
				if err != nil {
					return nil, err
				}
				if c, err = newWorldCarver(key, js, minY, height); err != nil {
					return nil, err
				}
				carvers[key] = c
			}
			bc = append(bc, c)
		}
		s.biomeCarvers = append(s.biomeCarvers, bc)
		hasCarvers = hasCarvers || len(bc) > 0
	}
	if !hasCarvers {
		//no biome has carvers (the End)
		return nil, nil
	}
	tag, err := data.BlockTag("minecraft:uncarvable", 0)
	if err != nil {
		return nil, err
	}
	if tag == nil {
		tag = []string{"minecraft:bedrock"}
	}
	for _, b := range tag {
		if m, ok := materials.Palette.get(b); ok {
			s.uncarvable[m] = true
		}
	}
	id := func(name string) int {
		if m, ok := materials.Palette.get(name); ok {
			return m
		}
		return -1
	}
	s.grass = id("minecraft:grass_block")
	s.mycelium = id("minecraft:mycelium")
	s.dirt = id("minecraft:dirt")
	return s, nil
}

func setLargeFeatureSeed(r *LegacyRandom, seed int64, chunkX, chunkZ int) {
	r.SetSeed(seed)
	a := r.NextLong()
	b := r.NextLong()
	r.SetSeed(int64(chunkX)*a ^ int64(chunkZ)*b ^ seed)
}

func (s *CarverSystem) startBiome(chunkX, chunkZ int) int {
	key := chunkHash(chunkX, chunkZ)
	if v, ok := s.biomeCache[key]; ok {
		return v
	}
	x := float64(chunkX << 4)
	z := float64(chunkZ << 4)
	cl := s.climate
	b := s.tree.SearchIsolated(Quantize(cl[0](x, 0.0, z)), Quantize(cl[1](x, 0.0, z)), Quantize(cl[2](x, 0.0, z)),
		Quantize(cl[3](x, 0.0, z)), Quantize(cl[4](x, 0.0, z)), Quantize(cl[5](x, 0.0, z)))
	if len(s.biomeOrder) >= carverBiomeCacheSize {
		delete(s.biomeCache, s.biomeOrder[0])
		s.biomeOrder = s.biomeOrder[1:]
	}
	s.biomeCache[key] = b
	s.biomeOrder = append(s.biomeOrder, key)
	return b
}

// StartCarvers returns the ids of the carvers chunk (x, z) may start (tests).
func (s *CarverSystem) StartCarvers(chunkX, chunkZ int) []string {
	var out []string
	for _, c := range s.biomeCarvers[s.startBiome(chunkX, chunkZ)] {
		out = append(out, c.id)
	}
	return out
}

// Apply carves a chunk whose material rules were just applied by the system's MaterialSystem. aquifer is the chunk's
// aquifer (nil if disabled). Returns the carving mask (same index as the materials; valid until the next call).
func (s *CarverSystem) Apply(ch *Chunk, aquifer *Aquifer, seaLevel, seaMaterial int) []bool {
	n := 256 * ch.Height
	if len(s.mask) != n {
		s.mask = make([]bool, n)
	} else {
		clear(s.mask)
	}
	run := &carvingRun{
		minBlockX: ch.ChunkX << 4, minBlockZ: ch.ChunkZ << 4,
		minY: ch.MinY, height: ch.Height, seaLevel: seaLevel, seaMaterial: seaMaterial,
		carveMinY: ch.MinY + 1, carveMaxY: ch.MinY + ch.Height - 1 - protectedBlocksOnTop,
		materials: ch.Materials, heights: s.materials.Heights(), mask: s.mask, aquifer: aquifer,
		uncarvable: s.uncarvable, grass: s.grass, mycelium: s.mycelium, dirt: s.dirt, materialsSys: s.materials,
	}
	run.middleX = float64(run.minBlockX + 8)
	run.middleZ = float64(run.minBlockZ + 8)
	r := s.random
	for dx := -carverRadius; dx <= carverRadius; dx++ {
		for dz := -carverRadius; dz <= carverRadius; dz++ {
			sx, sz := ch.ChunkX+dx, ch.ChunkZ+dz
			for i, c := range s.biomeCarvers[s.startBiome(sx, sz)] {
				setLargeFeatureSeed(r, s.seed+int64(i), sx, sz)
				if r.NextFloat() <= c.probability {
					c.carve(run, r, sx, sz)
				}
			}
		}
	}
	return s.mask
}
