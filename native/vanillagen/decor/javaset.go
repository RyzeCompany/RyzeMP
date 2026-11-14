package decor

import "sort"

// posSet is a set of positions in insertion order, iterated like a java.util.HashSet<BlockPos>
// (feature/JavaHashSet.php).
type posSet struct {
	list []pos
	idx  map[int64]bool
}

func newPosSet() *posSet { return &posSet{idx: map[int64]bool{}} }

func posKey(x, y, z int) int64 {
	return (int64(x&0x3ffffff) << 38) | (int64(z&0x3ffffff) << 12) | int64(y&0xfff)
}

// Add inserts a position if it is not in the set yet.
func (s *posSet) Add(x, y, z int) {
	k := posKey(x, y, z)
	if !s.idx[k] {
		s.idx[k] = true
		s.list = append(s.list, pos{x, y, z})
	}
}

func (s *posSet) Len() int { return len(s.list) }

func (s *posSet) Contains(x, y, z int) bool { return s.idx[posKey(x, y, z)] }

// javaHash is Vec3i.hashCode() as an unsigned 32-bit value.
func javaHash(x, y, z int) uint32 {
	return uint32(int64((int64(y)+int64(z)*31)*31 + int64(x)))
}

// IterationOrder is JavaHashSet::iterationOrder(): bucket (spread hash & (capacity - 1)) then insertion order.
func (s *posSet) IterationOrder() []pos {
	size := len(s.list)
	capacity := 16
	for float64(size) > float64(capacity)*0.75 {
		capacity <<= 1
	}
	mask := uint32(capacity - 1)
	type entry struct {
		bucket uint32
		i      int
	}
	entries := make([]entry, size)
	for i, p := range s.list {
		h := javaHash(p[0], p[1], p[2])
		h ^= h >> 16
		entries[i] = entry{h & mask, i}
	}
	sort.SliceStable(entries, func(a, b int) bool { return entries[a].bucket < entries[b].bucket })
	out := make([]pos, size)
	for i, e := range entries {
		out[i] = s.list[e.i]
	}
	return out
}
