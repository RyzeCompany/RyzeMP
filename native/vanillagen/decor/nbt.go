package decor

import (
	"bytes"
	"compress/gzip"
	"compress/zlib"
	"encoding/binary"
	"errors"
	"io"
	"math"
)

// Minimal big-endian NBT reader for GeyserMC's collisions.nbt (compressed with gzip or zlib, like zlib_decode()).

func decompress(b []byte) ([]byte, error) {
	if len(b) >= 2 && b[0] == 0x1f && b[1] == 0x8b {
		r, err := gzip.NewReader(bytes.NewReader(b))
		if err != nil {
			return nil, err
		}
		return io.ReadAll(r)
	}
	if len(b) >= 2 && b[0]&0x0f == 8 && (uint16(b[0])<<8|uint16(b[1]))%31 == 0 {
		r, err := zlib.NewReader(bytes.NewReader(b))
		if err != nil {
			return nil, err
		}
		return io.ReadAll(r)
	}
	return b, nil
}

type nbtReader struct {
	b   []byte
	err error
}

var errNBT = errors.New("invalid NBT data")

func (r *nbtReader) take(n int) []byte {
	if r.err != nil || n < 0 || len(r.b) < n {
		r.err = errNBT
		return nil
	}
	v := r.b[:n]
	r.b = r.b[n:]
	return v
}

func (r *nbtReader) u8() byte {
	if v := r.take(1); v != nil {
		return v[0]
	}
	return 0
}

func (r *nbtReader) i16() int16 {
	if v := r.take(2); v != nil {
		return int16(binary.BigEndian.Uint16(v))
	}
	return 0
}

func (r *nbtReader) i32() int32 {
	if v := r.take(4); v != nil {
		return int32(binary.BigEndian.Uint32(v))
	}
	return 0
}

func (r *nbtReader) i64() int64 {
	if v := r.take(8); v != nil {
		return int64(binary.BigEndian.Uint64(v))
	}
	return 0
}

func (r *nbtReader) str() string {
	n := int(uint16(r.i16()))
	return string(r.take(n))
}

// payload reads a tag payload: compounds become map[string]any, lists and arrays []any, numbers float64/int64.
func (r *nbtReader) payload(tag byte, depth int) any {
	if depth > 64 {
		r.err = errNBT
		return nil
	}
	switch tag {
	case 1:
		return int64(int8(r.u8()))
	case 2:
		return int64(r.i16())
	case 3:
		return int64(r.i32())
	case 4:
		return r.i64()
	case 5:
		return float64(math.Float32frombits(uint32(r.i32())))
	case 6:
		return math.Float64frombits(uint64(r.i64()))
	case 7:
		n := int(r.i32())
		v := r.take(n)
		out := make([]any, len(v))
		for i, b := range v {
			out[i] = int64(int8(b))
		}
		return out
	case 8:
		return r.str()
	case 9:
		elem := r.u8()
		n := int(r.i32())
		if n < 0 || n > len(r.b) {
			r.err = errNBT
			return nil
		}
		out := make([]any, 0, n)
		for i := 0; i < n && r.err == nil; i++ {
			out = append(out, r.payload(elem, depth+1))
		}
		return out
	case 10:
		m := map[string]any{}
		for r.err == nil {
			t := r.u8()
			if t == 0 {
				break
			}
			name := r.str()
			m[name] = r.payload(t, depth+1)
		}
		return m
	case 11:
		n := int(r.i32())
		if n < 0 || n*4 > len(r.b) {
			r.err = errNBT
			return nil
		}
		out := make([]any, n)
		for i := range out {
			out[i] = int64(r.i32())
		}
		return out
	case 12:
		n := int(r.i32())
		if n < 0 || n*8 > len(r.b) {
			r.err = errNBT
			return nil
		}
		out := make([]any, n)
		for i := range out {
			out[i] = r.i64()
		}
		return out
	}
	r.err = errNBT
	return nil
}

// readNBT reads a (possibly compressed) root compound.
func readNBT(data []byte) (map[string]any, error) {
	b, err := decompress(data)
	if err != nil {
		return nil, err
	}
	r := &nbtReader{b: b}
	if r.u8() != 10 {
		return nil, errNBT
	}
	r.str()
	v := r.payload(10, 0)
	if r.err != nil {
		return nil, r.err
	}
	return v.(map[string]any), nil
}
