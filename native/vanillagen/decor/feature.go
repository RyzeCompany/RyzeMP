package decor

// Ctx is feature/FeatureContext.php.
type Ctx struct {
	Level    *Region
	R        *Random
	B        *Blocks
	Reg      *Registry
	Surv     *Survival
	MinGenY  int
	GenDepth int
	SeaLevel int
	Seed     int64
	// Top is the placed feature being decorated with a biome check (PlacementContext.topFeature()), or nil.
	Top *PlacedFeature

	oreData   []float64 // scratch of oreFeature.doPlace()
	oreTested []uint64
}

// Feature is a configured feature (vanilla's Feature.place(): whether anything was placed).
type Feature interface {
	Place(c *Ctx, x, y, z int) bool
}

type pos = [3]int

// Modifier is a placement modifier: the positions for one input position (feature/Placements.php).
type Modifier func(c *Ctx, x, y, z int) []pos

// PlacedFeature is feature/PlacedFeature.php.
type PlacedFeature struct {
	ID        string
	Feature   Feature
	modifiers []Modifier
}

// Place is PlacedFeature.place(): without biome check (nested features).
func (p *PlacedFeature) Place(c *Ctx, x, y, z int) bool {
	previous := c.Top
	c.Top = nil
	defer func() { c.Top = previous }()
	return p.placeFrom(c, 0, x, y, z)
}

// PlaceWithBiomeCheck is FeaturePlacer.placeWithBiomeCheck().
func (p *PlacedFeature) PlaceWithBiomeCheck(c *Ctx, x, y, z int) bool {
	previous := c.Top
	c.Top = p
	defer func() { c.Top = previous }()
	return p.placeFrom(c, 0, x, y, z)
}

func (p *PlacedFeature) placeFrom(c *Ctx, index, x, y, z int) bool {
	if index == len(p.modifiers) {
		return p.Feature.Place(c, x, y, z)
	}
	placed := false
	positions := p.modifiers[index](c, x, y, z)
	if index+1 == len(p.modifiers) {
		for _, q := range positions {
			placed = p.Feature.Place(c, q[0], q[1], q[2]) || placed
		}
	} else {
		for _, q := range positions {
			placed = p.placeFrom(c, index+1, q[0], q[1], q[2]) || placed
		}
	}
	return placed
}

type noOpFeature struct{}

func (noOpFeature) Place(*Ctx, int, int, int) bool { return true }
