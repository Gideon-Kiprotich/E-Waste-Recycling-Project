# Phase 6 synthetic routing evidence

These reviewed artifacts contain only generated study data, not development records, credentials, real pickup locations or operational measurements. They are retained intentionally for D2 FR35 / D3 §8.2 reproducibility; private database/file preservation fingerprints remain outside Git.

- `inputs.jsonl`: all 30 frozen input cases, five families × sizes 10/25/50 × seeds 1701/2903, including synthetic study polygon, full directed matrices, declared unreachable stress edges and unknown-mass exclusions.
- `paired-results.jsonl`: both methods' complete results and coverage/qualification per case, tied to the input checksum. No case is discarded for unfavorable or incomparable coverage.
- `sensitivity-results.jsonl`: 240 additional runs. Each identifies its base scenario and one changed parameter/value; apply that override through `RoutingScenarios::variations` to reconstruct the input. Base values are already represented by the 30 paired cases.
- `benchmark-inputs.jsonl`: frozen synthetic job/vehicle/depot/matrix data for the separate three-vehicle application-path benchmarks. Random opaque fixture identities/timestamps are retained as inputs; rebuilding fixtures may generate new IDs, while replaying the frozen inputs preserves algorithm tie behavior.
- `benchmarks.jsonl`: every wall-time measurement for ten capture/calculation/validation/persistence/commit runs at each size. No provider calls, dependency downloads or fixture creation occur within the measured interval.
- `environment.json`: declared runtime/hardware, algorithm/configuration source hashes and baseline. The containing implementation commit identifies the final code revision; a self-referential commit hash is not embedded.

Reproduce with the guarded dedicated PostgreSQL testing database:

```bash
ROUTING_EVALUATION_OUTPUT=/tmp/ewaste-routing-evaluation php artisan test --filter=RoutingEvaluationTest
```

The benchmark creates and removes only its owned isolated testing schema. It never seeds or changes development data. Output timings vary with machine load; retain all measurements and any failures/timeouts. These results establish bounded prototype conformance, not road travel, fleet suitability, optimality, fuel savings or operational acceptance. See [the phase report](../../phase-6-report.md) for actual outcomes and manual limitations.
