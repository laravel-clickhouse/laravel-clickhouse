# Unit test mirroring is split by assertion target

Mounting one core trait onto two framework parents creates two execution
paths, but mirroring every unit test would duplicate ~300 SQL-compilation
scenarios for little return. The line is the assertion target: a test that
asserts **behaviour** (exception type/message, return value/type, execution
dispatch) must exist in both bridges' unit suites — parent differences act
exactly there; a test that asserts **compiled SQL output** (string in,
string out) is written once, in the Laravel unit suite — the compilation
body is a single core trait, and a parent difference that changed its
output would surface in the mirrored feature suites against a real server.
Feature tests are outside this split: they always mirror, whatever they
assert — they are the layer that catches output drift for the unmirrored
compilation tests. Framework-specific scenarios (getPdo() throwing, pool
lifecycle, Capsule boot) remain single-sided as before.

Escalation clause: if a compilation-parity bug ever slips through to the
Hypervel bridge — same builder calls, different SQL — the affected
scenarios are promoted to mirrored unit tests when fixed, so the class of
bug that actually bites is the class that gains permanent double coverage.
