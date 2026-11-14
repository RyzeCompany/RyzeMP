package main

import (
	"log"
	"os"
	"runtime"
	"runtime/pprof"
)

// startProfile starts a CPU profile when PM_VANILLAGEN_CPUPROFILE names a file, and prepares a heap profile of the
// live data when PM_VANILLAGEN_MEMPROFILE names one (development aids); the returned function writes them.
func startProfile() func() {
	stopCPU := func() {}
	if path := os.Getenv("PM_VANILLAGEN_CPUPROFILE"); path != "" {
		if f, err := os.Create(path); err != nil {
			log.Printf("cpu profile: %v", err)
		} else if err := pprof.StartCPUProfile(f); err != nil {
			log.Printf("cpu profile: %v", err)
			_ = f.Close()
		} else {
			stopCPU = func() {
				pprof.StopCPUProfile()
				_ = f.Close()
			}
		}
	}
	memPath := os.Getenv("PM_VANILLAGEN_MEMPROFILE")
	return func() {
		stopCPU()
		if memPath == "" {
			return
		}
		f, err := os.Create(memPath)
		if err != nil {
			log.Printf("heap profile: %v", err)
			return
		}
		runtime.GC()
		if err := pprof.WriteHeapProfile(f); err != nil {
			log.Printf("heap profile: %v", err)
		}
		_ = f.Close()
	}
}
