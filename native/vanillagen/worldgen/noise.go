package worldgen

import (
	"math"
	"strconv"
)

var gradX = [16]float64{1, -1, 1, -1, 1, -1, 1, -1, 0, 0, 0, 0, 1, 0, -1, 0}
var gradY = [16]float64{1, 1, -1, -1, 0, 0, 0, 0, 1, -1, 1, -1, 1, -1, 1, -1}
var gradZ = [16]float64{0, 0, 0, 0, 1, 1, -1, -1, 1, 1, -1, -1, 0, 1, 0, -1}

const wrapScale = 33554432.0

// ImprovedNoise is one octave of gradient noise (noise/ImprovedNoise.php).
// The permutation is kept in bytes and the gradient of an entry looked up from its 4 low bits: 280 bytes per octave
// instead of 8 KB of int and per-entry gradient tables (a generator has hundreds of octaves; the values and the
// arithmetic are the same).
type ImprovedNoise struct {
	p          [256]uint8
	xo, yo, zo float64
}

// grad is gradX/gradY/gradZ by gradient index (hash & 15), contiguous per index.
var grad = func() (g [16][3]float64) {
	for i := range g {
		g[i] = [3]float64{gradX[i], gradY[i], gradZ[i]}
	}
	return
}()

func NewImprovedNoise(r *Xoroshiro) *ImprovedNoise {
	n := &ImprovedNoise{}
	n.xo = float64(r.NextDouble() * 256.0)
	n.yo = float64(r.NextDouble() * 256.0)
	n.zo = float64(r.NextDouble() * 256.0)
	for i := 0; i < 256; i++ {
		n.p[i] = uint8(i)
	}
	for i := 0; i < 256; i++ {
		offset := r.NextBoundedInt(256 - i)
		n.p[i], n.p[i+offset] = n.p[i+offset], n.p[i]
	}
	return n
}

func wrap(x float64) float64 {
	return x - float64(math.Floor(x/wrapScale+0.5)*wrapScale)
}

func smooth(r float64) float64 {
	return float64(float64(r*r)*r) * (float64(r*(float64(r*6.0)-15.0)) + 10.0)
}

// sampleRaw: x/y/z already wrapped and offset.
func (n *ImprovedNoise) sampleRaw(x, y, z float64) float64 {
	return n.sampleCore(x, y, z, -1, 0)
}

// sampleCore implements both ImprovedNoise::sampleRaw (fudge == 0) and the y-smeared variant used by the
// blended noise (fudge != 0, originalY is the unwrapped scaled y).
func (n *ImprovedNoise) sampleCore(x, y, z float64, originalY, fudge float64) float64 {
	fx := math.Floor(x)
	fy := math.Floor(y)
	fz := math.Floor(z)
	rx := x - fx
	ry := y - fy
	rz := z - fz
	gry := ry
	if fudge != 0 {
		limit := ry
		if originalY >= 0 && originalY < ry {
			limit = originalY
		}
		gry = ry - float64(math.Floor(limit/fudge+1.0e-7)*fudge)
	}
	ix := phpInt(fx)
	iy := phpInt(fy)
	iz := phpInt(fz)
	p := &n.p
	x0 := int(p[ix&255])
	x1 := int(p[(ix+1)&255])
	xy00 := int(p[(x0+iy)&255])
	xy01 := int(p[(x0+iy+1)&255])
	xy10 := int(p[(x1+iy)&255])
	xy11 := int(p[(x1+iy+1)&255])

	rx1 := rx - 1.0
	ry1 := gry - 1.0
	rz1 := rz - 1.0

	g := &grad[p[(xy00+iz)&255]&15]
	d000 := float64(g[0]*rx) + float64(g[1]*gry) + float64(g[2]*rz)
	g = &grad[p[(xy10+iz)&255]&15]
	d100 := float64(g[0]*rx1) + float64(g[1]*gry) + float64(g[2]*rz)
	g = &grad[p[(xy01+iz)&255]&15]
	d010 := float64(g[0]*rx) + float64(g[1]*ry1) + float64(g[2]*rz)
	g = &grad[p[(xy11+iz)&255]&15]
	d110 := float64(g[0]*rx1) + float64(g[1]*ry1) + float64(g[2]*rz)
	g = &grad[p[(xy00+iz+1)&255]&15]
	d001 := float64(g[0]*rx) + float64(g[1]*gry) + float64(g[2]*rz1)
	g = &grad[p[(xy10+iz+1)&255]&15]
	d101 := float64(g[0]*rx1) + float64(g[1]*gry) + float64(g[2]*rz1)
	g = &grad[p[(xy01+iz+1)&255]&15]
	d011 := float64(g[0]*rx) + float64(g[1]*ry1) + float64(g[2]*rz1)
	g = &grad[p[(xy11+iz+1)&255]&15]
	d111 := float64(g[0]*rx1) + float64(g[1]*ry1) + float64(g[2]*rz1)

	sx := smooth(rx)
	sy := smooth(ry)
	sz := smooth(rz)

	a := d000 + float64(sx*(d100-d000))
	b := d010 + float64(sx*(d110-d010))
	c := d001 + float64(sx*(d101-d001))
	d := d011 + float64(sx*(d111-d011))
	ab := a + float64(sy*(b-a))
	cd := c + float64(sy*(d-c))
	return ab + float64(sz*(cd-ab))
}

// NoiseStack is a sum of octaves (noise/NoiseStack.php).
type NoiseStack struct {
	octaves     []*ImprovedNoise
	frequencies []float64
	amplitudes  []float64
	MaxValue    float64
}

func (s *NoiseStack) addLayer(n *ImprovedNoise, frequency, amplitude float64) {
	s.octaves = append(s.octaves, n)
	s.frequencies = append(s.frequencies, frequency)
	s.amplitudes = append(s.amplitudes, amplitude)
	s.MaxValue += float64(2.0 * math.Abs(amplitude))
}

func (s *NoiseStack) Get3D(x, y, z float64) float64 {
	value := 0.0
	for i, o := range s.octaves {
		f := s.frequencies[i]
		sx := float64(x * f)
		sy := float64(y * f)
		sz := float64(z * f)
		v := o.sampleCore(wrap(sx)+o.xo, wrap(sy)+o.yo, wrap(sz)+o.zo, -1, 0)
		value += float64(s.amplitudes[i] * v)
	}
	return value
}

const (
	normalizeDisabled = 0
	normalizeEnabled  = 1
	normalizeLegacy   = 2
)

type octaveSpec struct {
	index     int
	frequency float64
	amplitude float64
}

// NormalNoiseParameters (noise/NormalNoiseParameters.php).
type NormalNoiseParameters struct {
	octaves             []octaveSpec
	normalizationFactor float64
	baseAmplitude       float64
	baseOctave          int
	octaveCount         int
	modifiers           []float64
}

func NewNormalNoiseParameters(baseAmplitude float64, baseOctave, octaveCount, normalize int, modifiers []float64) *NormalNoiseParameters {
	p := &NormalNoiseParameters{baseAmplitude: baseAmplitude, baseOctave: baseOctave, octaveCount: octaveCount, modifiers: modifiers}
	frequency := math.Pow(2.0, float64(baseOctave))
	amplitude := baseAmplitude
	if normalize != normalizeDisabled {
		amplitude *= math.Pow(0.5, float64(-(octaveCount-1))) / (math.Pow(0.5, float64(-octaveCount)) - 1)
	}
	modifier := func(i int) float64 {
		if i < len(modifiers) {
			return modifiers[i]
		}
		return 1.0
	}
	for i := 0; i < octaveCount; i++ {
		m := modifier(i)
		if m != 0.0 {
			p.octaves = append(p.octaves, octaveSpec{baseOctave + i, frequency, amplitude * m})
		}
		frequency *= 2
		amplitude *= 0.5
	}
	targetAmplitude := 0.0
	variance := 0.0
	for _, o := range p.octaves {
		targetAmplitude += math.Abs(o.amplitude)
		deviation := 0.2702247831245211 * math.Abs(o.amplitude)
		variance += float64(deviation * deviation)
	}
	inputDeviation := math.Sqrt(variance)
	factor := 0.0
	if inputDeviation != 0.0 {
		targetDeviation := 0.3333333333333333
		factor = float64(targetAmplitude*targetDeviation) / float64(inputDeviation*math.Sqrt(2))
	}
	if normalize == normalizeLegacy && factor != 0.0 {
		lo, hi := math.Inf(1), math.Inf(-1)
		for i := 0; i < octaveCount; i++ {
			if modifier(i) != 0.0 {
				lo = math.Min(lo, float64(i))
				hi = math.Max(hi, float64(i))
			}
		}
		one := 1.0
		parity := 0.1 * (one + one/(hi-lo+1))
		targetDeviation := 0.3333333333333333
		factor = float64(float64(baseAmplitude*0.5)*targetDeviation) / parity
	}
	p.normalizationFactor = factor
	return p
}

func (p *NormalNoiseParameters) Create(r *Xoroshiro) *NoiseStack {
	first := r.ForkPositional()
	second := r.ForkPositional()
	inputFactor := 1.0181268882175227
	s := &NoiseStack{}
	for _, o := range p.octaves {
		name := "octave_" + strconv.Itoa(o.index)
		a := NewImprovedNoise(first.FromHashOf(name))
		b := NewImprovedNoise(second.FromHashOf(name))
		valueFactor := p.normalizationFactor * o.amplitude
		s.addLayer(a, o.frequency, valueFactor)
		s.addLayer(b, o.frequency*inputFactor, valueFactor)
	}
	return s
}

// CreateLegacyNetherBiome creates the noise with the legacy initialization of the Nether biome noises (legacy
// random sources): sequentially seeded octaves from one random, skipping the unused ones down from octave 0
// (NormalNoiseParameters::createLegacyNetherBiome).
func (p *NormalNoiseParameters) CreateLegacyNetherBiome(r *Xoroshiro) *NoiseStack {
	amplitudes := make([]float64, p.octaveCount)
	for i := range amplitudes {
		amplitudes[i] = 1.0
		if i < len(p.modifiers) {
			amplitudes[i] = p.modifiers[i]
		}
	}
	first := legacyLayers(r, p.baseOctave, amplitudes)
	second := legacyLayers(r, p.baseOctave, amplitudes)
	valueFactor := float64(p.normalizationFactor * p.baseAmplitude)
	inputFactor := 1.0181268882175227
	s := &NoiseStack{}
	for _, l := range first {
		s.addLayer(l.noise, l.frequency, float64(l.amplitude*valueFactor))
	}
	for _, l := range second {
		s.addLayer(l.noise, float64(l.frequency*inputFactor), float64(l.amplitude*valueFactor))
	}
	return s
}

type legacyLayer struct {
	noise                *ImprovedNoise
	frequency, amplitude float64
}

func legacyLayers(r *Xoroshiro, firstOctave int, amplitudes []float64) []legacyLayer {
	octaves := len(amplitudes)
	zeroOctaveIndex := -firstOctave
	levels := make([]*ImprovedNoise, octaves)
	zeroOctave := NewImprovedNoise(r)
	if zeroOctaveIndex >= 0 && zeroOctaveIndex < octaves && amplitudes[zeroOctaveIndex] != 0.0 {
		levels[zeroOctaveIndex] = zeroOctave
	}
	for i := zeroOctaveIndex - 1; i >= 0; i-- {
		if i < octaves && amplitudes[i] != 0.0 {
			levels[i] = NewImprovedNoise(r)
		} else {
			r.Consume(262)
		}
	}
	frequency := math.Pow(2.0, float64(-zeroOctaveIndex))
	amplitude := math.Pow(2.0, float64(octaves-1)) / (math.Pow(2.0, float64(octaves)) - 1)
	var layers []legacyLayer
	for i := 0; i < octaves; i++ {
		if levels[i] != nil {
			layers = append(layers, legacyLayer{levels[i], frequency, float64(amplitude * amplitudes[i])})
		}
		frequency *= 2
		amplitude /= 2
	}
	return layers
}

// BlendedNoise is the old blended terrain noise (noise/BlendedNoise.php).
type BlendedNoise struct {
	noises       [3][]*ImprovedNoise
	frequencies  [3][]float64
	amplitudes   [3][]float64
	fudgeScales  [3][]float64
	xzMultiplier float64
	yMultiplier  float64
	xzFactor     float64
	yFactor      float64
	MaxValue     float64
}

func NewBlendedNoise(r *Xoroshiro, xzScale, yScale, xzFactor, yFactor, smearScaleMultiplier float64) *BlendedNoise {
	baseScale := 684.412
	b := &BlendedNoise{xzFactor: xzFactor, yFactor: yFactor}
	b.xzMultiplier = float64(baseScale * xzScale)
	b.yMultiplier = float64(baseScale * yScale)
	limitSmearScaleY := float64(b.yMultiplier * smearScaleMultiplier)
	mainSmearScaleY := limitSmearScaleY / yFactor
	create := func(s int, firstOctave int, smearScaleY, valueFactor float64) {
		octaves := -firstOctave + 1
		factor := 1.0
		valueFactor /= math.Pow(2.0, float64(octaves)) - 1
		for i := octaves - 1; i >= 0; i-- {
			b.noises[s] = append(b.noises[s], NewImprovedNoise(r))
			b.fudgeScales[s] = append(b.fudgeScales[s], float64(smearScaleY*factor))
			b.frequencies[s] = append(b.frequencies[s], factor)
			b.amplitudes[s] = append(b.amplitudes[s], valueFactor)
			factor /= 2
			valueFactor *= 2
		}
	}
	limitFactor := 0.99998474
	mainFactor := 12.75
	create(0, -15, limitSmearScaleY, limitFactor)
	create(1, -15, limitSmearScaleY, limitFactor)
	create(2, -7, mainSmearScaleY, mainFactor)
	rng := 0.0
	for s := 0; s < 2; s++ {
		v := 0.0
		for i, a := range b.amplitudes[s] {
			v += float64((b.fudgeScales[s][i] + 2) * a)
		}
		if v > rng {
			rng = v
		}
	}
	b.MaxValue = rng
	return b
}

func (b *BlendedNoise) Sample(x, y, z float64) float64 {
	xx := float64(x * b.xzMultiplier)
	yy := float64(y * b.yMultiplier)
	zz := float64(z * b.xzMultiplier)
	main := b.stack(2, xx/b.xzFactor, yy/b.yFactor, zz/b.xzFactor) + 0.5
	if main <= 0 {
		return b.stack(0, xx, yy, zz)
	}
	if main >= 1 {
		return b.stack(1, xx, yy, zz)
	}
	lo := b.stack(0, xx, yy, zz)
	hi := b.stack(1, xx, yy, zz)
	return flerp(main, lo, hi)
}

func (b *BlendedNoise) stack(s int, x, y, z float64) float64 {
	value := 0.0
	amplitudes := b.amplitudes[s]
	frequencies := b.frequencies[s]
	fudge := b.fudgeScales[s]
	for i, o := range b.noises[s] {
		f := frequencies[i]
		sx := float64(x * f)
		sy := float64(y * f)
		sz := float64(z * f)
		v := o.sampleCore(wrap(sx)+o.xo, wrap(sy)+o.yo, wrap(sz)+o.zo, sy, fudge[i])
		value += float64(amplitudes[i] * v)
	}
	return value
}
