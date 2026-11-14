//go:build !windows

package main

import "syscall"

// processCPU is the CPU time (user + system) used by this process, in nanoseconds.
func processCPU() uint64 {
	var ru syscall.Rusage
	if syscall.Getrusage(syscall.RUSAGE_SELF, &ru) != nil {
		return 0
	}
	return uint64(ru.Utime.Nano() + ru.Stime.Nano())
}
