// Command vanillagen is the native core of PocketMine-MP's "vanilla" world generator. It computes chunks up to the
// carver stage (terrain, 4x4x4 biomes, material rules, carvers and the carving mask) exactly like the PHP
// implementation in src/world/generator/vanilla, several times faster.
//
// PocketMine-MP starts it by itself (see VanillaNativeBackend.php): it listens on a loopback TCP port, writes the
// port to -port-file and serves every async worker of the server over its own connection (one goroutine each).
// With -watch-stdin it exits as soon as its stdin is closed, so it never outlives the server.
package main

import (
	"bufio"
	"crypto/subtle"
	"encoding/binary"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"os"
	"path/filepath"
	"runtime/debug"
	"strconv"
	"sync"
	"time"

	"github.com/PocketMine-MP-Reborn/PocketMine-MP/native/vanillagen/decor"
	"github.com/PocketMine-MP-Reborn/PocketMine-MP/native/vanillagen/worldgen"
)

var version = "dev"

func main() {
	listen := flag.String("listen", "127.0.0.1:0", "address to listen on (loopback only)")
	portFile := flag.String("port-file", "", "write the listening port to this file once ready")
	watchStdin := flag.Bool("watch-stdin", false, "exit when stdin is closed (the parent process died)")
	showVersion := flag.Bool("version", false, "print the version and exit")
	bench := flag.Bool("bench", false, "benchmark: generate chunks without the network and exit")
	benchData := flag.String("data", "", "bench: vanilla worldgen data directory")
	benchSeed := flag.Int64("seed", 12345, "bench: world seed")
	benchChunks := flag.Int("chunks", 400, "bench: chunks per worker")
	benchWorkers := flag.Int("workers", 1, "bench: concurrent generators")
	benchStage := flag.Int("stage", 2, "bench: 0 terrain, 1 material rules, 2 carvers")
	benchSettings := flag.String("settings", "overworld", "bench: noise settings (overworld, nether, end)")
	flag.Parse()

	log.SetFlags(log.Ldate | log.Ltime | log.Lmicroseconds)
	log.SetPrefix("[vanillagen] ")

	if *showVersion {
		fmt.Printf("vanillagen %s protocol %d algorithm %d\n", version, protocolVersion, algorithmVersion)
		return
	}
	if *bench {
		if err := runBench(*benchData, *benchSettings, *benchSeed, *benchChunks, *benchWorkers, *benchStage); err != nil {
			log.Fatal(err)
		}
		return
	}

	token := os.Getenv("PM_VANILLAGEN_TOKEN")
	ln, err := net.Listen("tcp", *listen)
	if err != nil {
		log.Fatalf("listen: %v", err)
	}
	port := ln.Addr().(*net.TCPAddr).Port
	if *portFile != "" {
		tmp := *portFile + ".tmp"
		if err := os.WriteFile(tmp, []byte(strconv.Itoa(port)), 0o600); err != nil {
			log.Fatalf("port file: %v", err)
		}
		if err := os.Rename(tmp, *portFile); err != nil {
			log.Fatalf("port file: %v", err)
		}
	}
	log.Printf("version %s (protocol %d, algorithm %d) listening on %s", version, protocolVersion, algorithmVersion, ln.Addr())

	go releaseWhenIdle(releaseIdleAfter)

	stopProfile := startProfile()
	if *watchStdin {
		go func() {
			_, _ = io.Copy(io.Discard, os.Stdin)
			log.Printf("stdin closed, shutting down")
			stopProfile()
			os.Exit(0)
		}()
	}

	for {
		conn, err := ln.Accept()
		if err != nil {
			if errors.Is(err, net.ErrClosed) {
				return
			}
			log.Printf("accept: %v", err)
			time.Sleep(50 * time.Millisecond)
			continue
		}
		go serve(conn, token)
	}
}

// ---- shared data (read-only after loading) ----

type dataSet struct {
	data  *worldgen.Data
	trees map[string]*worldgen.ClimateBiomeTree // by noise settings id (the dimension)
}

// supportedSettings are the noise settings a session may use: the dimensions of the vanilla generator.
var supportedSettings = map[string]bool{"overworld": true, "nether": true, "end": true}

// tree returns the biome tree of a dimension: its multi-noise tree, or for the End (no multi-noise biome source) a
// single-biome tree (the real End biomes are chosen by the PHP side).
func (d *dataSet) tree(settings string) (*worldgen.ClimateBiomeTree, error) {
	dataMu.Lock()
	defer dataMu.Unlock()
	if t, ok := d.trees[settings]; ok {
		return t, nil
	}
	var t *worldgen.ClimateBiomeTree
	if settings == "end" {
		t = worldgen.NewSingleBiomeTree("minecraft:the_end")
	} else {
		var err error
		if t, err = worldgen.NewClimateBiomeTree(d.data, settings); err != nil {
			return nil, err
		}
	}
	d.trees[settings] = t
	return t, nil
}

var (
	dataMu   sync.Mutex
	dataSets = map[string]*dataSet{}
)

func loadData(dir string) (*dataSet, error) {
	abs, err := filepath.Abs(dir)
	if err != nil {
		return nil, err
	}
	dataMu.Lock()
	defer dataMu.Unlock()
	if d, ok := dataSets[abs]; ok {
		return d, nil
	}
	data, err := worldgen.OpenData(abs)
	if err != nil {
		return nil, err
	}
	d := &dataSet{data: data, trees: map[string]*worldgen.ClimateBiomeTree{}}
	dataSets[abs] = d
	return d, nil
}

// ---- sessions ----

func serve(conn net.Conn, token string) {
	defer conn.Close()
	if tc, ok := conn.(*net.TCPConn); ok {
		_ = tc.SetNoDelay(true)
	}
	br := bufio.NewReaderSize(conn, 64*1024)
	bw := bufio.NewWriterSize(conn, 128*1024)
	sendError := func(msg string) {
		w := &writer{}
		w.u8(msgError)
		w.str(msg)
		_ = writeFrame(bw, w.b)
	}

	_ = conn.SetReadDeadline(time.Now().Add(30 * time.Second))
	frame, err := readFrame(br)
	if err != nil {
		return
	}
	_ = conn.SetReadDeadline(time.Time{})
	r := &reader{b: frame}
	if r.u8() != msgHello || string(r.take(4)) != "PMVG" {
		sendError("expected HELLO")
		return
	}
	proto := r.u16()
	clientToken := r.str()
	dataDir := r.str()
	settings := r.str()
	seed := r.i64()
	flags := r.u32()
	var bedrockBiomes []uint32
	if flags&flagPackedBiomes != 0 {
		bedrockBiomes = readBedrockBiomes(r)
	}
	if r.err != nil {
		sendError("malformed HELLO")
		return
	}
	if proto != protocolVersion {
		sendError(fmt.Sprintf("protocol version mismatch: server %d, client %d", protocolVersion, proto))
		return
	}
	if subtle.ConstantTimeCompare([]byte(token), []byte(clientToken)) != 1 {
		sendError("bad token")
		return
	}
	if !supportedSettings[settings] {
		sendError("unsupported noise settings " + settings)
		return
	}

	started := time.Now()
	ds, err := loadData(dataDir)
	if err != nil {
		sendError(err.Error())
		return
	}
	tree, err := ds.tree(settings)
	if err != nil {
		sendError(err.Error())
		return
	}
	var gen *worldgen.Generator
	err = protect(func() error {
		var e error
		gen, e = worldgen.NewGenerator(ds.data, seed, worldgen.Options{
			Settings:        settings,
			NoAquifers:      flags&flagNoAquifers != 0,
			DeepslateFloats: flags&flagDeepslateFloats != 0,
			Materials:       flags&(flagMaterials|flagCarvers) != 0,
			Carvers:         flags&flagCarvers != 0,
		}, tree.Clone())
		return e
	})
	if err != nil {
		sendError(err.Error())
		return
	}
	gen.ReuseChunks = true // every chunk is encoded before the next request
	if bedrockBiomes != nil && len(bedrockBiomes) != len(gen.Tree.Names()) {
		sendError(fmt.Sprintf("HELLO has %d Bedrock biome ids for %d biomes", len(bedrockBiomes), len(gen.Tree.Names())))
		return
	}
	w := &writer{}
	w.u8(msgReady)
	w.u32(algorithmVersion)
	w.str(ds.data.Fingerprint())
	w.str(version)
	w.i32(int32(gen.Router.MinY))
	w.i32(int32(gen.Router.Height))
	names := gen.Tree.Names()
	w.u16(uint16(len(names)))
	for _, n := range names {
		w.str(n)
	}
	var palette []string
	if gen.Materials != nil {
		palette = gen.Materials.Palette.Names()
	}
	w.u16(uint16(len(palette)))
	for _, n := range palette {
		w.str(n)
	}
	if err := writeFrame(bw, w.b); err != nil {
		return
	}
	log.Printf("session %s: %s seed %d ready in %s", conn.RemoteAddr(), settings, seed, time.Since(started).Round(time.Millisecond))

	out := &writer{}
	var heightClasses []byte // CLASSES: terrain heights in CHUNK when set
	chunks := 0
	var busy time.Duration
	var decoration *decor.Session
	var decorBusy time.Duration
	decorations := 0
	transport := decor.Transport{
		Send:    func(payload []byte) error { return writeFrame(bw, payload) },
		Receive: func() ([]byte, error) { return readFrame(br) },
	}
	for {
		frame, err := readFrame(br)
		touch()
		if err != nil {
			if !errors.Is(err, io.EOF) && !errors.Is(err, net.ErrClosed) {
				log.Printf("session %s: %v", conn.RemoteAddr(), err)
			}
			if chunks > 0 {
				log.Printf("session %s closed after %d chunks (%.2f ms/chunk)", conn.RemoteAddr(), chunks, float64(busy.Microseconds())/1000/float64(chunks))
			} else {
				log.Printf("session %s closed", conn.RemoteAddr())
			}
			if decorations > 0 {
				log.Printf("session %s: %d decoration batches (%.2f ms/batch)", conn.RemoteAddr(), decorations, float64(decorBusy.Microseconds())/1000/float64(decorations))
			}
			return
		}
		r := &reader{b: frame}
		switch msg := r.u8(); msg {
		case msgFill:
		case msgClasses:
			if heightClasses, err = readClasses(r, len(palette)); err != nil {
				sendError(err.Error())
				return
			}
			continue
		case msgStats:
			w := &writer{}
			w.u8(msgStatsReply)
			w.b = binary.LittleEndian.AppendUint64(w.b, processCPU())
			if err := writeFrame(bw, w.b); err != nil {
				return
			}
			continue
		case decor.MsgInit:
			var reply []byte
			t0 := time.Now()
			err := protect(func() error {
				var e error
				decoration, reply, e = decor.NewSession(ds.data, seed, r.b, gen.SampleBiome)
				return e
			})
			if err != nil {
				decoration = nil
				log.Printf("session %s: native decoration unavailable: %v", conn.RemoteAddr(), err)
				w := &writer{}
				w.u8(decor.MsgFail)
				w.str(err.Error())
				reply = w.b
			} else {
				log.Printf("session %s: decoration ready in %s", conn.RemoteAddr(), time.Since(t0).Round(time.Millisecond))
			}
			if err := writeFrame(bw, reply); err != nil {
				return
			}
			continue
		case decor.MsgBegin, decor.MsgPlace:
			if decoration == nil {
				sendError("decoration message before INIT")
				return
			}
			t0 := time.Now()
			if err := decoration.Handle(msg, r.b, transport); err != nil {
				log.Printf("session %s: decoration: %v", conn.RemoteAddr(), err)
				return
			}
			if msg == decor.MsgPlace {
				decorBusy += time.Since(t0)
				decorations++
			}
			continue
		default:
			sendError("unexpected message")
			return
		}
		cx := int32(r.u32())
		cz := int32(r.u32())
		beard := readBeardifier(r)
		if r.err != nil {
			sendError("malformed FILL")
			return
		}
		var chunk *worldgen.Chunk
		t0 := time.Now()
		if err := protect(func() error { chunk = gen.FillWithBeardifier(int(cx), int(cz), beard); return nil }); err != nil {
			log.Printf("session %s: chunk %d %d: %v", conn.RemoteAddr(), cx, cz, err)
			sendError(err.Error())
			return
		}
		busy += time.Since(t0)
		out.b = out.b[:0]
		out.u8(msgChunk)
		out.i32(cx)
		out.i32(cz)
		packed := flags&flagPackedSections != 0
		if packed {
			out.u32(uint32(len(chunk.Materials)))
			out.b = append(out.b, chunk.Materials...)
		} else {
			appendRuns(out, chunk.Materials)
		}
		out.u32(uint32(len(chunk.Biomes)))
		for _, b := range chunk.Biomes {
			out.u16(b)
		}
		out.u8(byte(chunk.Stage))
		appendMask(out, chunk.Mask)
		if packed {
			appendPackedSections(out, chunk)
			if bedrockBiomes != nil {
				appendBiomeSections(out, chunk, bedrockBiomes)
				if heightClasses != nil && chunk.Stage > 0 {
					out.u8(1)
					appendTerrainHeights(out, chunk, heightClasses)
				} else {
					out.u8(0)
				}
			}
		}
		if err := writeFrame(bw, out.b); err != nil {
			return
		}
		chunks++
	}
}

func protect(f func() error) (err error) {
	defer func() {
		if p := recover(); p != nil {
			err = fmt.Errorf("internal error: %v", p)
			log.Printf("panic: %v\n%s", p, debug.Stack())
		}
	}()
	return f()
}

// ---- benchmark ----

func runBench(dir, settings string, seed int64, chunks, workers, stage int) error {
	ds, err := loadData(dir)
	if err != nil {
		return err
	}
	tree, err := ds.tree(settings)
	if err != nil {
		return err
	}
	gens := make([]*worldgen.Generator, workers)
	start := time.Now()
	for i := range gens {
		if gens[i], err = worldgen.NewGenerator(ds.data, seed, worldgen.Options{Settings: settings, Materials: stage >= 1, Carvers: stage >= 2}, tree.Clone()); err != nil {
			return err
		}
	}
	fmt.Printf("setup: %d generators in %s\n", workers, time.Since(start).Round(time.Microsecond))
	start = time.Now()
	var wg sync.WaitGroup
	for i, g := range gens {
		wg.Add(1)
		go func(i int, g *worldgen.Generator) {
			defer wg.Done()
			for c := 0; c < chunks; c++ {
				g.Fill(c%20-10+i*40, c/20-10)
			}
		}(i, g)
	}
	wg.Wait()
	el := time.Since(start)
	total := chunks * workers
	fmt.Printf("%d chunks with %d workers in %s: %.2f ms/chunk per worker, %.1f chunks/s total\n",
		total, workers, el.Round(time.Millisecond), float64(el.Microseconds())/1000/float64(chunks), float64(total)/el.Seconds())
	return nil
}

// appendRuns writes the materials run-length encoded: u32 total, u32 runs, u8[runs] values, u16[runs] lengths.
// Columns are mostly a few long runs (stone, water, air), and PHP rebuilds the array much faster from runs
// (array_fill) than from one byte per block.
func appendRuns(w *writer, m []byte) {
	w.u32(uint32(len(m)))
	countAt := len(w.b)
	w.u32(0)
	var lengths []uint16
	for i := 0; i < len(m); {
		v := m[i]
		j := i + 1
		for j < len(m) && m[j] == v && j-i < 0xffff {
			j++
		}
		w.u8(v)
		lengths = append(lengths, uint16(j-i))
		i = j
	}
	binary.LittleEndian.PutUint32(w.b[countAt:], uint32(len(lengths)))
	for _, l := range lengths {
		w.u16(l)
	}
}

// appendPackedSections writes every 16-block subchunk of the chunk in the layout of chunkutils2's
// PalettedBlockArray::fromData() (see protocol.go), so that the PHP side builds its subchunks without touching the
// blocks: u8 sections, then per section u8 paletteCount (0 = only air), u8[paletteCount] palette materials,
// u8 bitsPerBlock, u32 wordBytes, words (u32 little endian, blocks packed from the low bits, index (x<<8)|(z<<4)|y).
func appendPackedSections(w *writer, chunk *worldgen.Chunk) {
	sections := chunk.Height >> 4
	w.u8(byte(sections))
	var palette [256]int16 // material -> palette index + 1 (0 = not in the palette)
	var indexes [4096]byte
	var materialBuf [256]byte
	var wordBuf [1024]uint32
	for s := 0; s < sections; s++ {
		for i := range palette {
			palette[i] = 0
		}
		materials := materialBuf[:0]
		base := s << 4
		air := true
		for x := 0; x < 16; x++ {
			for z := 0; z < 16; z++ {
				column := ((x << 4) | z) * chunk.Height
				for y := 0; y < 16; y++ {
					m := chunk.Materials[column+base+y]
					if m != 0 {
						air = false
					}
					p := palette[m]
					if p == 0 {
						materials = append(materials, m)
						p = int16(len(materials))
						palette[m] = p
					}
					indexes[(x<<8)|(z<<4)|y] = byte(p - 1)
				}
			}
		}
		if air {
			w.u8(0)
			continue
		}
		bits := 1
		for _, candidate := range []int{1, 2, 3, 4, 5, 6, 8, 16} {
			bits = candidate
			if 1<<candidate >= len(materials) {
				break
			}
		}
		perWord := 32 / bits
		words := wordBuf[:(4096+perWord-1)/perWord]
		clear(words)
		for i, p := range &indexes {
			words[i/perWord] |= uint32(p) << (uint(i%perWord) * uint(bits))
		}
		w.u8(byte(len(materials)))
		w.b = append(w.b, materials...)
		w.u8(byte(bits))
		w.u32(uint32(len(words) * 4))
		for _, word := range words {
			w.u32(word)
		}
	}
}

// appendMask writes the carving mask as runs of set entries: u32 runs, then (u32 start, u16 length) per run.
func appendMask(w *writer, mask []bool) {
	countAt := len(w.b)
	w.u32(0)
	runs := 0
	for i := 0; i < len(mask); {
		if !mask[i] {
			i++
			continue
		}
		j := i + 1
		for j < len(mask) && mask[j] && j-i < 0xffff {
			j++
		}
		w.u32(uint32(i))
		w.u16(uint16(j - i))
		runs++
		i = j
	}
	binary.LittleEndian.PutUint32(w.b[countAt:], uint32(runs))
}
