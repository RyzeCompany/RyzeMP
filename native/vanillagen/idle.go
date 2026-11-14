package main

import (
	"runtime/debug"
	"sync/atomic"
	"time"
)

// Generation comes in bursts (a player joins or explores, then nothing for minutes). Go's garbage collector lets the
// heap grow to twice the live data during a burst and gives the unused pages back to the OS only slowly, so after a
// burst the process kept ~100 MB it no longer needed, counted as the server's memory (e.g. in a Pterodactyl panel).
// When no request has arrived for releaseIdleAfter, the garbage is collected and returned to the OS once.

const releaseIdleAfter = 30 * time.Second

var (
	lastActivity atomic.Int64 // UnixNano of the last request, 0 before the first one
	released     atomic.Bool
)

// touch records activity (called for every request frame).
func touch() {
	lastActivity.Store(time.Now().UnixNano())
	released.Store(false)
}

// releaseWhenIdle runs forever: it frees the heap's unused memory once per idle period.
func releaseWhenIdle(idle time.Duration) {
	ticker := time.NewTicker(idle / 3)
	defer ticker.Stop()
	for range ticker.C {
		last := lastActivity.Load()
		if last == 0 || released.Load() || time.Since(time.Unix(0, last)) < idle {
			continue
		}
		released.Store(true)
		debug.FreeOSMemory()
	}
}
