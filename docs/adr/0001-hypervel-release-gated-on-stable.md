# v2.0.0 release is gated on a stable Hypervel 0.4

The Hypervel bridge targets `hypervel/components 0.4.x-dev` — no stable 0.4
exists on Packagist yet, and its API still moves (a testbench dispatch gap
was found during development). Releasing v2.0.0 now would turn "supports
Hypervel 0.4" into a semver commitment whose stability we do not control:
any upstream signature change would force a breaking release on us. We
therefore hold the v2.0.0 tag until Hypervel publishes a stable 0.4; the
branch stays mergeable and CI-green against `0.4.x-dev` in the meantime.
