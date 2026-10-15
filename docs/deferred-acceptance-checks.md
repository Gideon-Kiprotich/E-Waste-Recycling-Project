# Deferred acceptance checks

## Phase 5 — provisional acceptance, 15 October 2026

The user reports first-look satisfaction and authorizes continued development with Phase 5 provisionally accepted. Thorough manual acceptance is deferred, **not passed**. No detailed test evidence, screenshots or named assistive-technology results were supplied. Recorded automated and bounded browser/runtime results remain in [the Phase 5 report](phase-5-report.md); they do not complete the checklist below.

No unresolved blocking functional defect is recorded at implementation commit `46454d98df98f9b2514d56dde942d09a473ab5e0`. The previously demonstrated allocation guidance/access/recovery defects and guarded demo-population blocker are documented as resolved in the report. The unchecked items below are unperformed or unreported acceptance checks, not claims of known bugs. Record any newly reproduced defect separately with its reproduction, impact and disposition; provisional acceptance does not waive it.

- [ ] **Provisioning and operator scope:** use supported Administrator controls to create/link/deactivate a Collector; verify approved operator/place and exact subtype capabilities, session revocation, same-link no-op and role boundaries. Finish directory/access setup before the fresh Ready review; verify recovery after a later setup change.
- [ ] **Assignment journey:** offer an eligible Ready pickup, inspect it as the named Collector, accept and inspect the Consumer milestone; separately exercise decline, withdrawal and reassignment with fresh acknowledgment. Check old tabs/retries and competing submissions. Acceptance must remain **not scheduled**, with no receipt/custody claim.
- [ ] **Eligibility changes and partial cancellation:** change date/observation/review/directory/access facts and cancel selected items. Verify stale work cannot proceed, recovery requires the appropriate new review/offer, only selected reservations release and custody remains unchanged.
- [ ] **Private-detail removal:** Offered hides private contact/address/coordinates; eligible Accepted work exposes only necessary details to its named scoped Collector. Withdrawal, replacement, invalidation, suspension and eligibility drift remove access immediately, including old links; check other Collectors, Consumers and Processors cannot cross scope.
- [ ] **Notice delivery/read permissions:** deliver only supported assignment notices using the documented scheduler/one-shot command. Verify recipient-only list/read behavior, retry deduplication, safe old notice text, reauthorization of links after revocation and preservation of historical non-assignment pending outbox rows. This is in-app delivery, not inbox delivery.
- [ ] **Mobile and accessibility journeys:** complete Administrator/Collector/Consumer tasks at desktop and 360px, including actual keyboard navigation, visible focus, error-summary recovery and screen-reader announcements. Check readable contrast and real-device/touch behavior. Record the actual browser/device/assistive technology and outcomes only when tested.

Use labelled synthetic accounts/records, keep credentials and private evidence outside Git, and record date, tested revision, role, expected/actual result and any defect for each completed check. Do not check an item solely because HTML assertions or automated tests passed. Real operator/collection-point acceptance, surveyed entrances/service geometry, licensing/hours and operational suitability remain unverified. Real inbox delivery remains unconfigured/unverified.

**Final end-to-end acceptance is required before deployment or declaring the project fully validated.** Recheck affected journeys after later changes; this deferral permits continued development and is not a deployment or operational approval.

## Phase 6 — manual acceptance pending

No Phase 6 user acceptance is claimed. Computer-use inventory exposed no browser surfaces; automated HTTP/HTML and JavaScript assertions are separate evidence. These are unperformed checks, not established defects; record reproduced failures separately.

- [ ] Complete reviewed operator/depot/receiving setup, Collector scope and vehicle declarations through supported Administrator controls. Confirm the documented order and fresh-review/reacknowledgment recovery after directory changes.
- [ ] Freeze/compare eligible Accepted pickups; inspect exclusions, both ordered lists, capacities/durations/return, coverage differences, zero/negative changes and computation-limit messaging. Include standalone drives and an ineligible/changed job.
- [ ] Approve, attempt a conflicting approval in another session, explicitly supersede, withdraw and change source eligibility/vehicle/access. Confirm route claims release, old histories remain, item reservations follow only existing cancellation rules and custody never changes.
- [ ] Check named Collector ordered stops, accepted-job private details and immediate loss of access after invalidation/revocation; verify another Collector, Consumer and Processor cannot cross scope.
- [ ] Exercise optional live tiles, disabled/failed provider and JavaScript-off ordered-list fallback. Verify no external call before **Show simulated map**, visible attribution and clear geometric/non-navigation labels.
- [ ] Complete desktop/360px and real-device/touch journeys, full keyboard navigation/focus/error recovery, screen-reader announcements and contrast checks. Record actual tools/devices/results only when performed; do not infer them from HTML or JavaScript assertions.

Use synthetic data. Phase 6 does not claim real operator/point acceptance, surveyed geometry/entrances, vehicle suitability, road/traffic realism or configured inbox delivery. Final integrated end-to-end acceptance remains required before deployment/full validation.
