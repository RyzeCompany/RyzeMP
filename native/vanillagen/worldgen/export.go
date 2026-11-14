package worldgen

// Accessors for the decoration features (package decor), which build their own float32 noises from the same parts.

// OctaveList returns the octaves of the parameters: index, frequency and amplitude of each.
func (p *NormalNoiseParameters) OctaveList() (indexes []int, frequencies, amplitudes []float64) {
	for _, o := range p.octaves {
		indexes = append(indexes, o.index)
		frequencies = append(frequencies, o.frequency)
		amplitudes = append(amplitudes, o.amplitude)
	}
	return
}

// NormalizationFactor is NormalNoiseParameters::getNormalizationFactor().
func (p *NormalNoiseParameters) NormalizationFactor() float64 { return p.normalizationFactor }

// Perm is the permutation table entry p[i & 255].
func (n *ImprovedNoise) Perm(i int) int { return int(n.p[i&255]) }

// Offsets are the noise origin offsets (xo, yo, zo).
func (n *ImprovedNoise) Offsets() (float64, float64, float64) { return n.xo, n.yo, n.zo }
