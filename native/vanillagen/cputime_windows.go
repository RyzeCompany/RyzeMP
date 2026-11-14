package main

import "syscall"

// processCPU is the CPU time (user + kernel) used by this process, in nanoseconds.
func processCPU() uint64 {
	var creation, exit, kernel, user syscall.Filetime
	h, err := syscall.GetCurrentProcess()
	if err != nil || syscall.GetProcessTimes(h, &creation, &exit, &kernel, &user) != nil {
		return 0
	}
	ft := func(f syscall.Filetime) uint64 { return uint64(f.HighDateTime)<<32 | uint64(f.LowDateTime) }
	return (ft(kernel) + ft(user)) * 100
}
