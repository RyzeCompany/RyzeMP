package decor

import "errors"

var errLibm = errors.New("the C math library does not give the expected results")

// sessionError is panicked by code deep inside a feature when the session cannot continue (e.g. a failed callback
// to PHP); the session recovers it and reports the error.
type sessionError struct{ err error }
