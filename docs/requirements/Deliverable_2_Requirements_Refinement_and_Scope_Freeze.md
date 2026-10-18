# Deliverable 2 — Requirements Refinement & Scope Freeze

**Project:** Smart E-Waste Collection, Tracking and Recycling Management Platform for Kenya  
**Case study:** Nairobi County  
**Baseline:** D2 v1.0, 3 October 2026  
**Status:** Working scope baseline for subsequent design and evaluation; desk-research-informed, not interview-validated or supervisor-approved.

## 1. Purpose and continuity

This deliverable defines what the final year project must implement, what it may implement after the core is complete, and what is deferred. It gives each core requirement a stable identifier, an acceptance criterion and a route to evaluation. No application coding is required at this stage.

The current baseline follows the refined foundation from 30 September and the desk-research stakeholder validation completed provisionally on 2 October. The original idea document contained a broader menu of possible features. That menu does not override the later, narrower foundation.

### 1.1 Decisions carried forward

| Earlier decision | Treatment in this baseline |
|---|---|
| Kenya-focused project with Nairobi County as case study | Preserved; evaluation uses Nairobi-based scenarios, not a national deployment |
| Responsive web/PWA direction | Responsive web is mandatory; installability may support it, but native mobile development and offline transaction synchronization are excluded |
| Unique device identity and QR-based chain of custody | Core; QR is an implementation/design choice rather than an externally established stakeholder requirement |
| Collection route optimization | Core research component; its benefit must be measured, not assumed |
| Explainable refurbishment/recycling decision support | Core structured, rule-based assessment with human approval |
| Numeric reuse-potential score | Stretch; no arbitrary score is treated as established industry practice |
| Consumer lifecycle milestones | Core, with privacy-filtered visibility |
| Data sanitization | Supporting core recordkeeping; actual wiping and certified verification are outside the software boundary |
| Tracking after dismantling | Parent-item lineage continues through batch/material records; no claim of physically identifying every recovered fragment |
| Informal actors | Recognized stakeholders, but onboarding and operational participation are outside MVP |
| Blockchain, rewards and native apps | Future work, not MVP |
| AI image recognition and illegal-dumping reporting | Outside MVP; they are not dependencies for graduation of the core system |
| External information collection | Use public research; distinguish evidence, inference and assumptions; do not fabricate interviews or stakeholder endorsement |

### 1.2 Aim and objectives

The preserved aim is to design, develop and evaluate a Nairobi County smart e-waste platform for collection, item-level traceability, refurbishment/recycling decisions and route optimization.

The objectives are restated for traceability, without claiming these are verbatim quotations of earlier wording:

1. **O1:** Investigate and model the operational and information requirements of the selected e-waste workflow.
2. **O2:** Implement unique-ID/QR chain-of-custody tracking from registration through recorded final outcomes, with batch lineage after dismantling.
3. **O3:** Implement and evaluate constrained collection route optimization.
4. **O4:** Implement explainable rule-based refurbishment/recycling decision support with human review.
5. **O5:** Evaluate functionality, traceability, route performance, decision consistency, usability, security and performance.

Corresponding research questions are: what requirements apply to the selected workflow; how effectively the platform preserves traceability; how routing compares with a fixed baseline under identical constraints; how consistently and explainably assessment rules support pathway decisions; and how well the prototype meets its acceptance and quality targets.

## 2. Evidence and decision basis

**E = public evidence; I = inference; A = prototype assumption or design decision.** A requirement may be justified by evidence while its exact implementation and threshold remain assumptions. Public research provides secondary validation, not direct validation by actual intended users.

| Basis ID | Type | Finding or decision | Requirement implication |
|---|---|---|---|
| E1 | E | NEMA's 2025 simplified guidance discusses coordinated collection, sorting, storage, transport, processing and recordkeeping [S1] | Model multiple actors and lifecycle records |
| E2 | E | WEEE Centre advertises documented collection custody and serial-level IT asset reporting [S2] | Store item identity, custody events and receipts |
| E3 | E | WEEE Centre advertises refurbishment/reuse and data wiping/destruction services [S2] | Include pathway assessment and sanitization records; keep physical work outside software |
| E4 | E | The gazetted 2024 EPR text includes collection/logistics, traceability and reporting provisions and lists electrical/electronic equipment [S3] | Provide internal traceability and aggregate reporting; this does not establish statutory compliance |
| E5 | E | ODPC identifies the Data Protection Act, 2019 as Kenya's framework for personal-data processing and data-subject rights [S4] | Apply privacy, access restrictions and a documented data lifecycle |
| I1 | I | Keeping device identities after material mixing cannot prove the source of an individual fragment | Preserve recorded item-to-batch lineage and distinguish allocated figures from measured figures |
| I2 | I | Missing condition inputs can make an automatic pathway recommendation unreliable | Support unknown inputs and manual-review outcomes |
| I3 | I | Collection routing is a plausible coordination improvement | Evaluate routing experimentally before making savings claims |
| A1 | A | QR labels are a practical identifier carrier | Provide scanning and manual-ID fallback |
| A2 | A | Nairobi scenarios, a small simulated fleet and common ICT devices are feasible for one FYP | Freeze a controlled experimental scope |
| A3 | A | Workflow gates and numeric test targets below are prototype choices | Treat them as versioned requirements, not legal mandates or interview findings |

Research check on 3 October: the ICTA e-waste operations page returned unrelated injected links; its collection-request page could not be fetched. Enviroserve's referenced page repeatedly timed out. Neither is used as decisive new evidence here. NEMA guidance contains discussion of proposed instruments; provisions associated with proposals must not be represented as enacted law. S3 is a hosted copy of the original gazetted 2024 text, not proof that no subsequent amendments exist. This deliverable does not freeze current legal compliance rules or official filing formats. ODPC's Act PDF could not be retrieved, so no unverified section-specific legal assertion is made.

## 3. Frozen system boundary

### 3.1 System users and responsibilities

| Actor | Core responsibilities | Boundary |
|---|---|---|
| Consumer | Register own devices, choose pickup/drop-off, submit/cancel eligible requests, view own milestones and records | Cannot declare collection, processing or sanitization complete |
| Collector | View assigned jobs, acknowledge route plans, record collection attempts and handovers | Access limited to assigned work; cannot choose final processing outcomes |
| Processor | Receive devices, assess condition, approve pathways, record refurbishment/dismantling and downstream outcomes | Role covers recycler/refurbisher staff within an assigned facility; specialist subroles may follow later |
| Administrator | Review operator records, manage points and assignments, plan routes, monitor exceptions, administer access and reports | Can resolve exceptions through recorded decisions; cannot silently rewrite history |
| Organization/regulator observer | Stakeholder represented through aggregate management reports | Separate login/dashboard is stretch; MVP admin produces reports without personal-data disclosure |
| Collection-point attendant | Represents receipt at a participating point | MVP uses an authorized collector/processor account attached to that point; no fifth operational role required |

An organization is a data/access boundary, not proof of real partnership. Demonstration accounts and facilities must be visibly marked as synthetic. Admin-reviewed operator status means reviewed within the prototype; it must not be labelled NEMA-certified without independently verified evidence.

### 3.2 Inside the MVP

Responsive browser interfaces; role- and organization-scoped access; operator/point records; device registration; pickup and drop-off requests; assignment; constrained route planning; unique IDs and QR lookup; collection/receipt; custody history; assessment; human-approved processing; sanitization records; batch/material lineage; final outcome recording; consumer timelines; in-app notifications; aggregate dashboards; CSV exports; audit and controlled correction; documented evaluation.

### 3.3 Outside the software boundary

Physical collection, repair, dismantling, recycling, weighing, wiping and disposal are performed by people. The platform records their assertions and attached evidence. It does not sense physical truth, grant a waste licence, certify sanitization, execute a resale transaction or ensure environmental compliance. A downstream dispatch receipt means dispatch is recorded; it does not mean the destination has finished processing.

### 3.4 Device and geography limits

**New operational refinement A4:** initial device categories are laptops, desktops, mobile phones, flat-panel monitors, printers and small ICT peripherals such as routers/keyboards. Each physical device has one item ID; a request may contain several items. Category-specific condition questions prevent laptop rules being applied to printers.

Refrigerators, air conditioners, CRT equipment, medical/industrial equipment and loose battery streams are excluded from normal MVP acceptance. An embedded battery or other hazard indicator can trigger manual review and exclusion from ordinary pickup planning. The software records the restriction; it does not provide a hazardous-material treatment procedure. This is a feasibility boundary, not a claim that these streams are unimportant.

Locations must be within the defined Nairobi case-study service area. A map pin and a textual address/landmark are supported; manual coordinates remain available when geolocation is denied. Actual partner service areas, prices and supported categories are unavailable, so any example values are labelled simulated.

## 4. Priority and scope freeze

**P0:** required for the MVP and evaluation. **P1:** stretch, attempted only after P0 passes. **P2:** future work, excluded from this FYP baseline.

| Area | P0 core | P1 stretch | P2 future/excluded |
|---|---|---|---|
| Identity and traceability | Unique IDs, QR lookup, custody receipts, batch lineage, consumer milestones | More detailed component/subassembly tracking | Blockchain |
| Collection | Requests, point directory, assignments, failed-attempt handling | Institutional bulk CSV intake | Nationwide logistics network |
| Routing | Deterministic capacity/duration-constrained plans and baseline comparison | Multiple time windows, advanced fleet heuristics | Traffic prediction, live telematics |
| Assessment | Category-aware rules, explanation, manual review and human approval | Calibrated numeric reuse score | Autonomous pathway decisions or AI image recognition |
| Processing | Recorded refurbishment/reuse, recycling batches, materials/residue and final evidence | Rich component inventory | Industrial process control, physical disposal automation |
| Sanitization | Status, method, operator, date, evidence reference and reuse gate | Downloadable prototype attestation | Automated wiping or independently certified certificates |
| Reporting | Admin/facility metrics and privacy-filtered CSV | Observer login and geographic heatmap | Official NEMA/EPR submission/integration |
| Participation | Consumer access and instructions | Simple collection-awareness content enhancements | Rewards, payouts, resale marketplace, informal-actor onboarding |
| Delivery | Responsive web, online workflow | Basic installation support | Native apps, offline event synchronization, IoT bins |
| Incident reporting | Operational exceptions for existing items/requests | None planned | Separate illegal-dumping case-management workflow |

A P1 omission does not fail the MVP. A P0 omission requires an explicit baseline change, revised objective coverage and documented rationale.

## 5. Functional requirements and acceptance criteria

All FR01–FR35 are P0. Criteria describe observable results rather than implementation details. Exact messages and visual layout are design-stage decisions.

### 5.1 Access, operators and intake

| ID | Requirement: the system shall… | Minimum acceptance criterion |
|---|---|---|
| FR01 | Authenticate users, support logout and account recovery, and deny access to suspended accounts | Valid login works; invalid/suspended login fails; logout invalidates access; expired/reused recovery token fails |
| FR02 | Enforce role, ownership, assignment and facility scope on every protected action and export | Consumer A cannot access B's device by changing its ID; collector cannot receive a processor-only action; staff cannot access unrelated facilities |
| FR03 | Let administrators record/review operator and facility details, categories and review status | Pending/suspended operators cannot receive new jobs; review changes retain actor/date/reason; synthetic status is visible |
| FR04 | Maintain participating collection points with coordinates, hours, contacts, accepted categories and active status | Inactive or incompatible points cannot be selected; directory/list works without map service |
| FR05 | Register one physical device per unique item record with category, model where known, condition and data-bearing declaration | IDs remain unique across concurrent registration; unknown model/serial is allowed; missing required category/condition is rejected |
| FR06 | Store serial/IMEI when available, optional photographs, source of observations and potential duplicate flags | Matching known identifiers prompt review; missing identifiers do not block intake; duplicate flags do not automatically destroy a record |
| FR07 | Create multi-item collection requests with pickup/drop-off mode, location, date preference and contact details | Only eligible items belonging to the consumer can be added; an item cannot be in two active requests |
| FR08 | Validate supported category, service area and declared hazards before ordinary scheduling | Unsupported/out-of-area requests show a clear reason; hazard-flagged items require review and are withheld from normal routes |
| FR09 | Allow eligible request cancellation, rescheduling and recorded failed-attempt outcomes | Cancellation before physical receipt releases assignment; collected items cannot be cancelled through consumer controls; retries retain prior attempts |
| FR10 | Let administrators assign approved collectors and receiving facilities, with acknowledgment and conflict handling | Simultaneous assignment attempts produce only one active assignment; collector sees its own accepted work; suspension/reassignment is audited |

### 5.2 Routing and collection

| ID | Requirement: the system shall… | Minimum acceptance criterion |
|---|---|---|
| FR11 | Prepare a frozen routing input snapshot with eligible jobs, depot, vehicle capacities, availability, estimated masses, coordinates and service times | Missing coordinates/mass are flagged before planning; the snapshot records assumptions and distance-data source |
| FR12 | Produce deterministic proposed routes subject to vehicle mass capacity and maximum route duration | Each planned job appears once, route starts/ends at depot, constraints hold, and infeasible jobs are listed with reasons |
| FR13 | Compare a proposed plan with a fixed request-time-order baseline on identical inputs and constraints | Stores both plans, served/unserved jobs, total distance, duration estimate, algorithm version and runtime; no selective scenario deletion |
| FR14 | Let administrators approve route versions and collectors view ordered stops and record outcomes | Editing an approved plan creates a new version; changed/cancelled jobs invalidate affected stops; unapproved plans are not dispatched |
| FR15 | Generate QR labels/lookup for items and batches with manual-ID fallback | QR opens the correct authorized record; unreadable label can be replaced without changing ID; QR contains no contact/address/serial data |
| FR16 | Record item-specific collection or point receipt with actor, timestamp, source/destination and acknowledgment/evidence | Consumer self-report cannot mark physical collection complete; partial collection updates only actually received items |
| FR17 | Support explicit custody handovers with sending and receiving acknowledgment | Initiated transfer remains pending until receiver acknowledgment; discrepancy creates an exception; only one current confirmed custodian exists |
| FR18 | Let authorized processors accept or dispute a facility receipt | Missing/damaged/wrong-item discrepancies are recorded; disputed receipts block routine processing until resolved |

### 5.3 Assessment, processing and traceability

| ID | Requirement: the system shall… | Minimum acceptance criterion |
|---|---|---|
| FR19 | Capture category-specific inspection observations, unknown values, hazards and data-bearing status | Cannot silently default unknown to healthy; inspection is distinct from consumer self-report; inspector/date are retained |
| FR20 | Apply versioned deterministic assessment rules and explain the resulting refurbish/recycle/manual-review recommendation | Same inputs/rules yield same output; explanation lists decisive inputs/rules; insufficient/conflicting inputs produce manual review |
| FR21 | Require a processor to approve the pathway and justify any override | Recommendation alone cannot start processing; override retains original output, new decision, actor and reason |
| FR22 | Record refurbishment work, functional-test result and release outcome or fallback to recycling | Reuse release requires passed recorded test and cleared data gate; failed refurbishment can enter recycling with reason |
| FR23 | Record sanitization required/not-applicable/pending/completed/failed status with method, date, operator and evidence reference | Completed requires metadata; data-bearing reuse is blocked while pending/failed; not-applicable requires justification |
| FR24 | Record dismantling against a batch and preserve input-item membership and input mass | A dismantled item cannot be added again as an intact input; all input IDs remain traceable; missing measured mass prevents finalized balance |
| FR25 | Record batch output materials/components, measured mass, residue, stock and downstream movements | Negative quantities are rejected; output/movement identifiers prevent duplicate counting; estimated allocations are separately labelled |
| FR26 | Record final outcomes and destination evidence, keeping external dispatch separate from verified completion | Dispatch alone remains awaiting external confirmation; batch closure requires reconciled stock/movements and recorded evidence or resolution |
| FR27 | Provide an owner-visible milestone timeline and authorized staff-level lineage view | Owner sees own collection/receipt/assessment/outcome and batch association, without other owners' identities or private staff/contact data |
| FR28 | Maintain append-only audit history and controlled corrective records | Prior event cannot be edited/deleted through ordinary UI/API; correction references prior event and stores reason/actor/time |
| FR29 | Create and resolve operational exceptions for loss, disputed receipt, hazard, assessment uncertainty and material discrepancy | Exception has type, assignee, reason and resolution; affected transition remains blocked where required; unresolved items remain visible |

### 5.4 Reporting, notifications and data lifecycle

| ID | Requirement: the system shall… | Minimum acceptance criterion |
|---|---|---|
| FR30 | Notify relevant users in-app of assignment, scheduling, receipt, milestone and exception changes | Notification references committed event, is visible only to permitted recipient and can be marked read; duplicate event retry creates no duplicate alert |
| FR31 | Present role-scoped dashboards with date/category/facility filters and explicitly defined metrics | Known fixture counts match; same-device transfers are not counted as additional collections; estimated and measured mass are distinct |
| FR32 | Export authorized aggregate and operational CSV reports with generation time and filters | Export matches filtered dashboard totals, excludes unauthorized personal fields, and treats formula-like text as text |
| FR33 | Support privacy notice acknowledgment, own-record access and administrative correction/deletion requests | Notice version/date retained; request can be tracked; personal-data redaction can preserve non-identifying event linkage |
| FR34 | Validate inputs/uploads, reject stale/duplicate mutations and commit workflow state with its event atomically | Invalid file/coordinates/mass rejected; retry does not duplicate receipt; conflicting version rejected; simulated failure leaves neither partial state nor orphan event |
| FR35 | Supply labelled synthetic seed data and reproducible evaluation/export facilities | Evaluation scenarios can be rebuilt from documented seed/configuration; metrics retain versions and provenance; generated data is never described as field observations |

## 6. Non-functional requirements

All thresholds below are **A5: proposed prototype acceptance targets**, not established stakeholder service levels. Tests must report hardware, dataset, concurrency and method. Targets can change only through the change process.

**Reference workload:** 10,000 item records, 50,000 lifecycle events, 20 concurrent authenticated sessions; routing test size up to 50 eligible requests and three vehicles. Development feasibility assumes a normal 16 GB student laptop; no dedicated GPU is required.

| ID | Attribute | Measurable target | Verification |
|---|---|---|---|
| NF01 | Authorization/privacy | Zero unauthorized successes across the documented role/owner/facility test matrix, including QR and exports | Negative API/UI access tests; inspect response payloads |
| NF02 | Authentication/input safety | No plaintext passwords; recovery tokens single-use/expiring; deployed app uses HTTPS; login throttling activates after 5 failed attempts in 15 minutes; controlled uploads only | Configuration review and focused security tests; upload/link tests |
| NF03 | Routine performance | 95th-percentile server response under 2 seconds for intake, receipt, timeline and dashboard at reference workload, excluding third-party map/network latency | Repeatable load test with raw timing results |
| NF04 | Routing performance | Plan up to 50 requests/3 vehicles within 10 seconds on declared reference hardware | Benchmark at least 10 repeat runs per size; report failures/timeouts |
| NF05 | Integrity | Zero duplicate item IDs, simultaneous custodians, duplicate committed receipts or invalid terminal transitions in defined tests | Concurrent, retry, transition and rollback tests |
| NF06 | Trace completeness | 100% of committed critical events contain object ID, actor, event type, recorded time and required references | Automated queries across complete scenario data |
| NF07 | Usability | At least 80% unassisted completion across register/request, collect/receive, assess/approve and trace tasks if appropriate evaluators are available | Task protocol and results; simulated walkthroughs alone cannot meet this human-usability target |
| NF08 | Accessibility/responsiveness | Core flows usable at 360 px and desktop widths; keyboard access and labels/errors verified; no critical automated accessibility findings on core pages | Browser/manual checks and automated scan; no full-conformance claim based only on scans |
| NF09 | Recovery | Demonstrate backup restore within 60 minutes with at most 24 hours of committed data loss under a daily-backup prototype policy | Timed restore exercise plus item/event count checks |
| NF10 | Dependency resilience | Core list/manual-coordinate intake and timeline remain usable when map/email services fail; notification delivery failure does not roll back a completed custody event | Inject provider failure and verify outcome/status |
| NF11 | Maintainability/reproducibility | Every P0 requirement maps to acceptance evidence; assessment/routing versions and evaluation seeds are documented | Traceability review; fresh-environment setup rehearsal |
| NF12 | Reporting correctness | 100% agreement with predefined fixture totals and no mixed-unit/estimate-as-measurement presentation | Independent calculation versus dashboard/CSV output |

No 99.9% production availability, national scale, industrial safety certification or guaranteed environmental outcome is promised. If representative usability participants cannot be obtained, report NF07 as unvalidated and use a heuristic review as supplementary evidence, not invented participant results.

## 7. Workflow and lifecycle rules

### 7.1 Separate state dimensions

Keep request state, custody state, processing state and sanitization state separate. A failed pickup is a request attempt, not a recycling outcome; a transfer pending acknowledgment is not a received device; sanitization can be pending during assessment.

| Object | Allowed progression and exceptions |
|---|---|
| Collection request | SUBMITTED → REVIEWED → ASSIGNED → SCHEDULED → IN_PROGRESS → COMPLETED; REJECTED/CANCELLED before receipt; failed attempts return eligible items for rescheduling; multi-item request may remain PARTIALLY_COMPLETED |
| Custody | With owner → confirmed collector/point receipt → pending transfer → confirmed facility receipt; disputes preserve last confirmed custodian and record pending recipient |
| Processing | REGISTERED → RECEIVED_AT_FACILITY → ASSESSED → REFURBISHMENT_APPROVED or RECYCLING_APPROVED or ON_HOLD |
| Refurbishment branch | APPROVED → IN_REFURBISHMENT → TEST_PASSED → RELEASED_FOR_REUSE; failure → reassessment/recycling approval; sanitization gate applies before release |
| Recycling branch | APPROVED → DISMANTLED_IN_BATCH → recorded output/stock/downstream transfers → verified batch outcomes; the parent device remains dismantled, linked to that history |
| Sanitization | NOT_APPLICABLE with reason, or REQUIRED/PENDING → COMPLETED or FAILED; failed can have a new attempt, preserving previous evidence |
| Exception | OPEN → UNDER_REVIEW → RESOLVED; loss is separately recorded and never counted as successful recycling |

### 7.2 Binding business rules

1. **BR01 Identity:** IDs are unique, permanent and never reused; QR replacement preserves identity. Serial/IMEI is optional and protected, not the primary key.
2. **BR02 Ownership:** consumers act only on owned items; ownership changes are recorded, not inferred from scanning.
3. **BR03 Active request:** an intact item belongs to at most one active request/assignment.
4. **BR04 Operator eligibility:** only active reviewed operators can receive new assignments. Suspension preserves old records and routes unresolved custody to administrative review.
5. **BR05 Physical receipt:** authorized recipient records receipt with acknowledgment/evidence; consumer submission alone is insufficient.
6. **BR06 Custody:** a confirmed item has one current custodian. Cross-party transfers require receiver acknowledgment; rejected/missing items stay disputed.
7. **BR07 Assessment:** human inspection supersedes unverified self-report for processing; preserve both versions. Unknown inputs never become positive observations automatically.
8. **BR08 Human approval:** rules advise; authorized processors approve or override with reasons. A scan does not approve a pathway.
9. **BR09 Data gate:** a data-bearing device cannot be released for reuse until sanitization completion and required metadata are recorded. For dismantling, any surviving data-bearing storage has a linked controlled record or destruction evidence before final closure.
10. **BR10 Dismantling:** intact-item tracking stops at dismantling; parent-to-batch membership remains permanent. Batch masses cannot be represented as physically traced per-device fragments.
11. **BR11 Mass accounting:** use kg; distinguish estimated pickup mass and measured input/output mass. Track output stock, reused components, dispatched materials, residue and documented variance once each. Initial tolerance is max(0.1 kg, 2% of measured batch input), a prototype assumption; exceeding it opens an exception and prevents ordinary closure until explained. No tolerance proves physical recovery truth.
12. **BR12 Evidence of final outcome:** dispatch, receipt and completed processing are different events. External confirmation can be manually recorded against evidence; unknown outcomes remain pending.
13. **BR13 Reporting:** collection counts unique first receipts, not registration or transfer counts. Refurbished-release rate uses released items divided by facility-received items in an explicitly defined cohort; outcome shares disclose incomplete records. Material recovery is kg recorded by batch, not a device count.
14. **BR14 Corrections:** no destructive editing of committed custody/processing history; corrective events supersede prior facts and regenerate current projections with review where needed.
15. **BR15 Time:** store timestamps unambiguously in UTC and display Africa/Nairobi time. Record both observed event time and recording time when they differ; restrict retrospective input and record explanation.
16. **BR16 Routing:** every eligible job is served at most once in a plan; enforce mass and route-duration constraints; report unserved jobs. Changed request data invalidates the affected plan version.
17. **BR17 Hazards:** flagged unsupported hazards are excluded from ordinary routes until reviewed. The app supplies no assurance of vehicle safety or hazardous-treatment authorization.
18. **BR18 Finality:** released/dismantled/closed records cannot return to registered state through ordinary edits. A later returned device starts a new lifecycle linked to the old ID.
19. **BR19 Truthfulness:** prototype attestations are marked as records of operator assertions; no automatic claim of regulator approval, proven sanitization or measured emissions avoided.
20. **BR20 Data lifecycle:** restrict and redact personal fields separately from event identifiers; retention/deletion decisions must be recorded so audit history is not used as a reason to retain unnecessary personal details indefinitely.

## 8. Permission matrix

All permissions require server-side checks, not merely hidden buttons. “Scoped” means owned, assigned or facility-related records; administrators have accountable access for administration and exception handling.

| Action | Consumer | Collector | Processor | Administrator |
|---|---|---|---|---|
| Register/edit before receipt | Own | No | Intake correction via event | Review/correct via event |
| Request/cancel eligible pickup | Own | Record attempt only | No | Review/reschedule |
| Read pickup address/contact | Own | Assigned active work only | Only where intake requires it | Operational need |
| Create/approve route | No | View assigned plan | No | Yes |
| Record collection | No | Assigned | Authorized point intake | Exception handling only |
| Initiate/acknowledge handover | No | Scoped sender/recipient | Scoped sender/recipient | Resolve dispute; no silent acknowledgment |
| Inspect/approve pathway | View own result | No | Own facility | Monitor; processor role required to inspect |
| Record processing/sanitization | View own filtered record | No | Own facility | Monitor/correct with evidence |
| Read full custody history | Own milestones | Assigned events | Facility-related lineage | Yes, audited |
| Read aggregate dashboard | Own activity | Assigned workload | Own facility | All prototype aggregates |
| Export | Own record access | Scoped manifest | Facility report | Privacy-filtered report |
| Review operators/access | No | No | No | Yes |
| Change committed event | No destructive edit | No destructive edit | Corrective event | Controlled correction |

Organization/regulator observers, if later implemented, receive aggregate read-only access and no consumer addresses, identifiers or photos.

## 9. Minimum information model

This is the requirements-level data inventory; final tables, relationships, indexes and schema belong to design work.

| Record | Minimum information |
|---|---|
| User/role/membership | Account ID, contact needed for access, role, facility/organization membership, active status, notice version |
| Operator/facility/point | ID, contacts, address/coordinates, accepted categories, hours, review evidence/date/status, synthetic flag |
| Item | Unique ID, owner reference, category, make/model if known, optional serial/IMEI, condition observations, data-bearing/hazard flags, timestamps |
| Request/request items | ID, owner, item links, mode, location/landmark, preferred date, estimates, current state, cancellation/attempt history |
| Assignment/vehicle/route | Approved collector, facility, depot, vehicle capacity/availability, route version, input snapshot, ordered stops, approval and outcome |
| Custody event/transfer | Item/batch ID, sender, recipient, observed/recorded times, location, acknowledgment/evidence, status, corrective link |
| Assessment/decision | Item, inspector, answers including unknown, rule version, recommendation/explanation, approved outcome/override reason |
| Sanitization record | Item/storage reference, requirement/status, method, operator, date, result, evidence reference, synthetic flag |
| Processing/batch membership | Item pathway, work/testing history, batch ID, item-input links, measured input, dismantling timestamp |
| Material/output/movement | Batch/output ID, category, quantity/kg, measured/estimated marker, stock/destination, dispatch/receipt/completion evidence |
| Exception/audit/notification | Related object, event/type, actor, status, reason/resolution, recipient, time, read state |
| Evaluation run | Scenario seed, input provenance, software/rule/algorithm versions, constraints, plans, baseline, timings and metrics |
| Privacy request | Requester, requested access/correction/deletion, review decision, action date and redaction references |

Coordinates must have valid ranges; mass is non-negative, with strictly positive measured input for finalized batches. Unknown values remain explicit. Referential integrity and atomic mutations prevent orphaned lineage.

**Prototype privacy policy A6:** use synthetic personal data by default. If voluntarily provided evaluator data is needed, collect the minimum, explain its purpose, restrict access and remove identifiable raw feedback/contact data within 90 days after final assessment unless a documented university obligation requires otherwise. This 90-day period is a project choice, not an asserted statutory retention period. Operational deployment requires its own confirmed retention and lawful-processing policy. Device contents are never uploaded.

## 10. Algorithm and external-service boundaries

### 10.1 Core routing experiment

The core is a small capacitated vehicle-routing prototype: one depot, up to three vehicles, up to 50 requests, mass capacity and maximum route duration. It is not an exact optimizer and must not claim a globally optimal result.

**A7 design choice:** use deterministic nearest-feasible-stop construction, split routes when constraints require it, and improve stop order using a feasible 2-opt procedure. The baseline processes jobs in submission-time order, applying the same feasibility logic and fleet constraints. A stable ID breaks ties. Both use the same depot, jobs, capacities, service times and distance matrix. Preserve the baseline specification before running tests.

Prefer a documented road-distance matrix if a suitable service is accessible. If unavailable, use a fixed straight-line distance matrix, clearly label it a geometric simulation and avoid presenting it as Nairobi road travel. Duration can use a documented constant-speed assumption plus per-stop service time; do not claim it captures traffic. Future provider selection must not introduce mandatory paid services.

Run at least 30 fixed scenarios spanning sparse/clustered demand, capacity pressure, uncertain estimates and infeasible jobs, at sizes 10, 25 and 50. Test identical input snapshots. Report distance, estimated duration, vehicles used, served/unserved count, constraint violations and runtime. Compare distance savings only when served-job sets are equal; otherwise explicitly show the coverage difference or evaluate a common served set. Report negative/zero gains and infeasible scenarios. Fuel/cost savings require additional justified parameters and are not core evidence. The pass gate is feasibility, reproducibility and honest comparison, not a guaranteed percentage improvement.

### 10.2 Core decision rules

Start with a category-specific decision table, not a numerical score:

- Declared safety concerns or an unsupported category → hold/manual review.
- Missing decisive test results or conflicting observations → manual review.
- Working core functions with repairable observed issues → refurbishment candidate, subject to technician approval.
- Confirmed non-repairable condition under the documented prototype criteria → recycling candidate, subject to approval.
- Every recommendation lists its input evidence, triggered rule and missing information.

The exact technical “repairable/non-repairable” rule definitions must be documented before implementation and clearly labelled as prototype assumptions where no credible category-specific evidence exists. Do not invent market values, repair costs or scientific score thresholds. Use at least 40 fixed cases across supported categories, including boundary, unknown and hazard cases, with expectations authored before execution. Agreement with those expectations measures conformance to the rule table; it does not prove expert or real-world diagnostic accuracy.

### 10.3 External dependencies

| Dependency | Allowed use | Required fallback/boundary |
|---|---|---|
| Maps/geolocation | Point selection, distance inputs and route display | Text/list view and manual location; label unavailable/estimated distances |
| Browser camera | QR scanning | Manual ID entry; camera denial is recoverable |
| Email | Optional account-recovery delivery | Evaluation may use a local test mailbox; no SMS/WhatsApp dependency or sending to real contacts |
| Sanitization/processing evidence | Operator-entered records/reference attachments | Software does not perform wiping or authenticate external certificate truth automatically |
| Official systems | Public reference information only | No NEMA, county, EPR or payment integration in MVP |

Framework and database selection are not frozen here. The initial document suggested Laravel or NestJS and PostgreSQL/PostGIS, with React/Next.js options; suggestions are not confirmed selections. Do not import the PHP/MySQL-only decision from the separate financial reconciliation project. Architecture should choose tools against these requirements in its own stage.

## 11. Assumptions, dependencies and risks

| ID | Status/uncertainty | Handling and validation trigger |
|---|---|---|
| A8 | No cooperating operator or real dataset guaranteed | Use labelled synthetic facilities/events and desk research; revise only if credible access appears |
| A9 | Typical weights, service times and vehicle capacities unavailable | Publish configurable scenario parameters and sensitivity tests; never describe generated values as measurements |
| A10 | A processor can represent recycler and refurbisher in MVP | Separate permissions by facility; revisit if stakeholder evidence requires distinct duties |
| A11 | User-interface language is initially English | Test clarity; Kiswahili localization is deferred unless evidence/supervisor requirements justify a scope change |
| A12 | QR labels remain attached long enough for intake | Allow manual lookup, label replacement and mismatch handling; no hardware purchase dependency |
| A13 | Public sources can describe practice but not user acceptance | State evidence level; do not mark desirability/usability as validated by desk research |
| A14 | Batch output allocation cannot establish molecular/device-specific attribution | Show batch linkage and aggregate outcomes only; label any allocation formula as estimated |
| A15 | External receipts/certificates can be incomplete | Keep pending/unverified states; preserve source/reference and operator assertion |
| A16 | Academic schedule and supervisor rubric are not available | Avoid invented deadlines; handle mandatory rubric changes through baseline change control |

Principal risks are uncontrolled feature growth, unavailable credible route data, unrealistic synthetic parameters, unreliable operator assertions, incomplete downstream evidence and false certainty from assessment rules. The scope boundaries, versioned inputs, sensitivity testing and explicit pending/manual-review states address these risks.

## 12. Traceability and verification plan

| Objective | Basis | Requirements | Acceptance/evaluation evidence |
|---|---|---|---|
| O1 Requirements/workflow model | E1–E5, I1–I3, assumptions | FR03–FR10, FR33; all business rules | Source-to-need matrix, scope review, actor/workflow walkthrough; no interview-validation claim |
| O2 Identity and custody | E2, E4, I1, A1 | FR05–FR06, FR15–FR18, FR24–FR29, FR34 | End-to-end lineage tests, partial/disputed transfers, batch membership, completeness and access tests |
| O3 Routing | I3, A2, A7, A9 | FR10–FR14, FR35 | Fixed baseline and 30 scenarios; feasibility, distance/coverage, runtime and sensitivity results |
| O4 Decision support | E3, I2 | FR19–FR23, FR29 | 40 fixed cases; unknown/hazard handling, explanations, overrides, sanitization gate |
| O5 System evaluation | E5 and quality assumptions | FR01–FR02, FR28–FR35; NF01–NF12 | Functional/security/load/recovery tests; participant usability where available, otherwise explicitly unvalidated |

### 12.1 Acceptance scenario register

| Test ID | Scenario | Requirement coverage |
|---|---|---|
| AT01 | Login, logout, suspension and account recovery | FR01–FR02, NF01–NF02 |
| AT02 | Reviewed/inactive operators and compatible points | FR03–FR04, FR08–FR10 |
| AT03 | Registration, duplicate flags, multi-item pickup, cancellation and partial collection | FR05–FR10, FR16, FR34 |
| AT04 | Route generation, infeasibility, baseline comparison, plan invalidation and approval | FR11–FR14, FR35, NF04 |
| AT05 | QR/manual lookup, replacement label, denied access and no personal payload | FR02, FR15, FR27, NF01 |
| AT06 | Drop-off, collection, transfer acknowledgment and disputed/missing-item receipt | FR16–FR18, FR29, NF05–NF06 |
| AT07 | Refurbishment recommendation, unknown inputs, approval and justified override | FR19–FR21, NF11 |
| AT08 | Refurbishment failure, repeat sanitization, reuse release gate and final outcome | FR22–FR23, FR26, NF05 |
| AT09 | Dismantling, batch lineage, mass discrepancy, stock/movement and external confirmation | FR24–FR29, NF05–NF06, NF12 |
| AT10 | Notifications, correction/redaction, dashboard/export totals and scope | FR27–FR33, NF01, NF10, NF12 |
| AT11 | Duplicate retries, concurrent receipt, stale route/action and forced rollback | FR10, FR14, FR17, FR28, FR34, NF05 |
| AT12 | Reference-workload load, accessibility, usability protocol and backup restore | FR35, NF03–NF04, NF07–NF11 |

These are design-stage verification obligations, not tests already executed. Subsequent design and implementation must expand them into reproducible steps, expected outcomes and actual evidence.

## 13. Scope baseline and change control

The P0 requirements and explicit exclusions form **D2 v1.0**, the working scope baseline. This freezes the planned implementation boundary while acknowledging that underlying assumptions and real-world acceptance remain provisional. It does not assert user sign-off on each new numeric parameter or supervisor approval.

Changes require a short log entry containing: change ID; evidence/trigger; affected requirement IDs; before/after wording; effect on objectives, implementation and evaluation; decision/date; new baseline version. Clarifying wording without changing observable behavior is a patch; adding/removing a P0 capability or changing acceptance thresholds creates a new baseline version.

Decision rules:

1. Complete P0 before starting P1.
2. Do not add a new role, external integration or workflow merely because it appeared in the original brainstorm.
3. Preserve the three core differentiators: custody/lineage, constrained routing and explainable pathway support.
4. Reassess a core change when credible research, supervisor requirements or verified stakeholder information contradicts a working assumption.
5. Do not retroactively weaken a target to hide a failed evaluation; disclose the result and explain any subsequent revision.

### 13.1 Completion check

| Required D2 output | Present |
|---|---|
| System boundary and actor definitions | Sections 3 and 8 |
| Core/stretch/future scope | Section 4 |
| Prioritized functional requirements with criteria | Section 5 |
| Measurable non-functional requirements | Section 6 |
| Lifecycle and business rules | Section 7 |
| Permissions and minimum data | Sections 8–9 |
| Algorithm/external boundaries | Section 10 |
| Assumptions/dependencies/constraints | Sections 2, 3, 6 and 11 |
| Objective/requirement/test traceability | Section 12 |
| Baseline and change control | Section 13 |

The next work should consume this baseline to produce detailed system models and design before coding. The exact previously named Deliverable 3 title was not recoverable from available context and is not invented here.

## 14. References and provenance

Public references were checked on 3 October 2026. The concrete requirements, workflow gates, numeric thresholds, dataset sizes, algorithm choices and priority assignments are project design decisions unless expressly marked E above.

- **S1:** National Environment Management Authority (2025), *E-Waste Management in Kenya: Simplified Guidelines*. https://nema.go.ke/wp-content/uploads/2025/11/E-WASTE-GUIDELINES.pdf — used for broad workflow and recordkeeping context, not to treat proposed regulations as law.
- **S2:** WEEE Centre, *Services*. https://weeecentre.com/services — operator's self-description of custody documentation, serial reporting, refurbishment and data destruction; no independent certification verification performed.
- **S3:** Kenya Gazette, Legal Notice 176 of 2024, *The Sustainable Waste Management (Extended Producer Responsibility) Regulations, 2024*, copy hosted by NEMA. https://nema.go.ke/wp-content/uploads/2025/06/LN176_2024.pdf — regulations 16/19 and First Schedule provide contextual traceability/reporting and product coverage; the PDF also contains a separate instrument, which is not used here.
- **S4:** Office of the Data Protection Commissioner, *Data Protection Laws Kenya*. https://www.odpc.go.ke/data-protection-laws-kenya/ — confirms framework and available regulatory instruments; this is not a section-by-section legal-compliance assessment.
- **P1:** User's current *E-waste Recycling Project.docx*, read 3 October 2026 — original broad concept and optional feature list.
- **P2:** Recovered project conversation decisions, 30 September and 2 October 2026 — refined aim/objectives, Nairobi scope, core innovations, supporting sanitization, exclusions and desk-research method. Recovered summaries are used for continuity, not represented as verbatim transcripts.

No interviews, partnerships, real route measurements, usability results or real processing outcomes are asserted by this deliverable.

## User-authorized change CR-ID-01 — 5 October 2026

Authority: the project user's explicit Phase 2 instruction replaces duplicate-identifier warnings with blocking validation. This is an authorized change to FR05/FR06, BR01 and the registration portions of AT03/AT11, not newly claimed stakeholder validation. The preceding baseline revision and its original warning acceptance criteria remain preserved for history. This entry takes precedence wherever that baseline describes matching identifiers as warnings.

**Revised rule:** a known serial/IMEI belongs to only one device across consumers, categories, manufacturers, identifier types and lifecycle states. Serial/IMEI remains optional, private and distinct from the permanent opaque device ID. A genuinely new registration with an already registered comparison key is rejected with exactly: “This serial/IMEI is already registered. Please recheck the number entered.” No matched device, owner or private details may be disclosed. Unknown/omitted identifiers do not conflict. Successful same-command, same-payload retries return the original device; changed payloads retain the command-conflict protection.

**Exact comparison contract:** retain the existing normalization and keyed hashes: trim the input, remove whitespace and ASCII hyphens, uppercase ASCII letters, preserve leading zeroes, and compute the existing context-separated HMAC-SHA256 with the private application key. Comparison uses only `identifier_hmac`; it is not partitioned by owner, category, manufacturer, serial/IMEI type or key-version label. A numeric serial and IMEI with the same normalized text conflict. Other punctuation/non-ASCII identifier text is rejected. Blank or separator-only text has no known identifier. Typed IMEI input must normalize to 15 ASCII decimal digits and satisfy the printed check digit; it is not an allocation/ownership verification. Only one optional identifier is recorded per device; multiple IMEIs are not implemented.

**Revised acceptance:** AT03 must reject same-owner and cross-owner/category matches and normalized equivalents with the exact field error, preserve safe form values, omit private identifier/photo values from flashed input, associate the error to the input and focus the summary. Blank identifiers remain valid across registrations. AT11 must prove the partial unique database index prevents duplicate direct/competing writes, that the specific constraint failure becomes the same field error, and that rollback leaves no partial device, observation, event, custody, command, outbox or photo. Unrelated database errors are not converted to duplicate errors. Existing successful retries still resolve before duplicate validation.

**Existing data:** preflight comparison keys before adding uniqueness. Conflicts block migration; only counts and opaque references may be reported. No record deletion, identifier alteration or survivor selection is authorized by this change. Review and explicitly authorize a corrective procedure separately, preserving permanent identity and dependent history. Prior free-text identifiers cannot be automatically declared IMEIs or serials; retain an unspecified legacy type without changing ciphertext or hashes. Later return/lifecycle designs must reuse the registered physical identity or obtain an explicit rule revision; a new lifecycle ID cannot silently bypass global identifier uniqueness (BR18).

Standalone parts remain a proposed scope change only; see `docs/device-parts-scope-change.md`. This identifier change does not approve new intake categories or battery pickup.

## User-authorized change CR-PARTS-01 — 5 October 2026

**Decision/authority:** the project user explicitly authorizes an implementation-ready, documentation-only design for individually registered standalone HDDs, SSDs and small low-voltage ICT cooling fans. This approves the bounded software scope, not application implementation, stakeholder interviews or real operator acceptance. **Effective baseline D2 v1.1 = preserved D2 v1.0 + CR-ID-01 + CR-PARTS-01.** This additive entry supersedes conflicting baseline parts limits/proposal status; earlier revisions remain historical. CR-ID-01's global uniqueness/privacy/retry contract is unchanged.

**Trigger and evidence:** the user requests a concrete subset of the earlier [parts assessment](../device-parts-scope-change.md), with one physical identity/storage facet and bounded future-module contracts. Rechecked authoritative technical guidance and design assumptions are distinguished in the [settled design report](../parts-intake-design-report.md#evidence-and-design-basis). No stakeholder validation, safe collection channel or physical sanitization result is invented.

**Before → after:** §3.4/A4 and §4 limit ordinary registration to whole ICT devices/router/keyboard, with component tracking as stretch. The revised core registration boundary adds category `standalone_part` (Standalone ICT part), and exactly `hdd`, `ssd`, `ict_cooling_fan` subtypes. Keep all six existing categories unchanged. Each form registers one already separate physical unit; no loose batteries, industrial/mains fans, arbitrary/unknown components, quantity/bulk inventory or resale. The first software increment covers registration/owned views/observations/identity and storage metadata/tests; richer inventory and processor detachment remain deferred. This narrow core extension does not promote all component work to P0.

**Revised FR05/FR06, BR01/BR02/BR07/BR09:** a part gets one permanent physical subject/item ID and EWC QR/manual label, optional private serial with CR-ID-01 normalization/encryption/global uniqueness, and no invented parent or removal history. Parts reject IMEI; numeric serials still share the namespace with device IMEIs. Consumers cannot supply parent/origin/removal/storage/custody fields. Successful same-command retries return the original part; fresh duplicates receive the unchanged exact private field error and leave no partial state/photo. HDD/SSD data-bearing status is enforced as YES on registration and revision, including reportedly empty/unused/erased/broken units; consumer declarations cannot satisfy or remove future sanitization/release/destruction gates. A storage facet shares the drive's subject ID and does not create a second physical identity/custody/count.

**Fan boundary and observations:** one dedicated ICT cooling fan/blower, no more than 24 V DC and 140 mm largest housing/frame dimension excluding free leads; factory motor-driver/tachometer/PWM electronics allowed, separate/smart modules excluded. These are project limits, not legal or safety certification. Initial `fan_scope=YES` is required; NO/UNKNOWN blocks safely, without asking for a test. Known additional modules also block initial intake. An otherwise eligible fan with unknown electronics remains data UNKNOWN/review-needed; basic-circuitry data NO is unverified self-report. All condition questions have Unknown options and use existing knowledge; no power, dismantling or internal/data-content inspection is required. Later revisions may retain newly discovered scope/electronics uncertainty without erasing identity. Exact schema/vocabularies and error messages are settled in report §§1–3.

**Downstream obligations, not this increment:** FR04/FR07–FR08/FR16–FR18 require explicit subtype compatibility, hazard review and acknowledged subject custody; acceptance of a computer does not imply acceptance of a loose drive/fan. FR19–FR26 and BR07–BR12 require part-aware inspection, human approval, media-appropriate treatment and canonical storage/batch lineage. FR27–FR29/FR31–FR32 and BR13 require separate whole-device/standalone/derived cohorts, no second count for a storage facet, first receipts rather than registrations and no parent-plus-child double mass. Future derived components are processor-created/identified, not a consumer parent-link workflow. Report §6 binds these definitions when their later modules are implemented; no real point/route/treatment service is authorized now.

**Acceptance/evaluation change:** extend the registration portion of AT03, ownership/labels in AT05 and atomicity/concurrency/retries in AT11 through report PI01–PI09: exact allowed/excluded inputs, unknowns, fan qualification, drive YES and reciprocal same-ID facet guards, private serial collisions/IMEI rejection, forged parent fields, preserved legacy retries/history, safe feedback and rollback/evidence cleanup. PI10 requires distinct actual desktop/360px/keyboard/screen-reader/label manual checks after implementation. AT02/AT06–AT10 gain only the future-module obligations F01–F06; they are not scheduled as part of initial intake. Original routing/assessment evaluation thresholds are unchanged; later rule sets must add declared part cases before evaluating these subtypes.

**Objectives, migration and handoff:** extends O1 intake and O2 identity/lineage coverage without changing routing/assessment objectives or claiming their new modules exist. Use one reviewed forward migration preserving old category/subject/identifier/event/command data and handling both upgrades and fresh installs; no reset, key change or reclassification. Implementation-ready sources are [the schema/behavior and tests](../parts-intake-design-report.md) and [S02/S03 written handoff](../design/parts-intake-handoff.md). The user authorizes this specification commit only; implementation needs a separate request. Real local acceptance remains unverified, and loose batteries/derived-component workflows remain excluded from the first increment.


## User-authorized change CR-MEDIA-01 — 16 October 2026

Authority: the project user's Phase 10B implementation instruction accepts the preceding whole-device inventory design and its defaults. This appended entry preserves all baseline revisions, CR-ID-01, CR-PARTS-01, inspection rules and MEDIA-ASSURANCE-1.0. It authorizes implementation, not browser/bench or operational acceptance.

FR23 / BR09 / D3 UC11, S10–S11 and AT05–AT08/AT10–AT11 now include observed whole-device media inventory, canonical processor-only `processor_media` / `processor_derived` records (`hdd`, `ssd`, `other_nonvolatile`), versioned placement/completeness and live all-media coverage. Other nonvolatile media is inventory-only: Treatment support unavailable. Supported HDD/SSD methods retain the frozen 10A policy and separate attempt, verification, validation and applicability review.

New media has one item/subject/storage-facet ID, optional globally unique encrypted serial and evidenced facility-identification provenance anchored to an accepted parent handover, never an invented earlier receipt, detachment or transfer. Accountability ownership derives from the parent only with explicit consistency evidence. Existing identities require authorized physical matching, same owner/current facility and no conflicting placement/claim; no serial match grants access. Original standalone origins remain parent-null. Unknown presence/count/matching uses stable unresolved inventory entries, not invented physical subjects.

Inventory revisions and relationship assertions are append-only. Omission cannot unlink a known medium. A correction may resolve a genuinely unbound uncertain entry as not present, or flag a bound medium as disputed; the bound identity/claim and treatment/destruction history remain. Resolving a mistaken canonical association requires a separately reviewed correction workflow; no identity deletion/merge, actual removal or replacement is authorized here. Complete inventory requires an evidenced attestation including configuration storage, no unresolved entries/inaccessible areas, and explicit N/A justification if a current technician NO establishes no applicable storage.

Processor-derived HDD/SSD treatment uses explicit versioned parent authorization: current non-HOLD parent decision, accepted coherent facility control, confirmed placement, media-specific safety checks and exact media capability. Existing standalone media retains its own assessment/decision prerequisites. No receipt or assessment is fabricated for derived media. All treatment uses the existing ledger with an explicit authorization-context version; parent coverage composes its live result rather than maintaining competing treatment truth. Changed inventory/placement/assessment/authorization facts invalidate applicability. Failed treatment and unsupported/unknown media block coverage. Reported destruction permanently prevents reusable presentation, including after relationship disputes; coverage is not release.

Current facility Processor scope, encrypted private evidence, retry-first commands, stale-form checks, atomic writes and forced deferred integrity apply. Independent movement of embedded media and custody-changing parent actions are blocked while a placement is claimed; no implicit co-transfer is implemented. First-receipt/report cohorts exclude derived identification and facet duplication. Forward migrations infer no historic inventories or clearances. Phase 7/9 safety/identity hard holds remain blocked; this is not their correction workflow.

Excluded: physical detachment/replacement, generic components, actual wiping/hardware access, refurbishment/release, batches, disposal and universal whole-device treatment support. Final integrated acceptance remains required before deployment/full validation. See `docs/design/phase-10b-media-contract.md` for the frozen implementation contract and tests.


## User-authorized change CR-REUSE-01 — 18 October 2026

Authority: the user's Phase 11 implementation instruction authorizes bounded UC12 work/testing/reuse return and explicitly extends CR-MEDIA-01. Baseline history and CR-ID-01/CR-PARTS-01 remain unchanged. Trace: D2 FR22–FR23/FR26–FR28/FR34, BR02/05/06/09/14/15/17/18; D3 US14, UC12, S11, AT05–AT08/AT10–AT11.

Record externally performed work and separate versioned functional/safety tests, then a human release authorization and the authenticated existing owner's explicit acknowledgment of the exact actual manifest. Authorization changes no custody or ownership. Only complete acknowledged return atomically changes custody and records released-for-reuse; it is no new collection or first receipt. No automatic expiry is introduced: pending authorizations require explicit withdrawal or are closed without transfer on a reported incomplete/mismatched return. Relevant monotonic dependency-version changes invalidate authorization without revival. Completed returns remain immutable.

Only supported whole devices and independent standalone HDD/SSD/basic ICT cooling fans with current accepted compatible facility custody and a current REFURBISH decision qualify. Derived media cannot independently enter this workflow. Live all-media coverage/assurance and destruction restrictions remain authoritative; unknown/unsupported/disputed/stale coverage blocks release. A qualifying basic fan needs explicit private N/A evidence bound to current technician findings. Inspection recommendations and certificates cannot establish clearance.

CR-MEDIA-01's movement prohibition gains one narrowly ledger-backed exception: owner-acknowledged reuse return of a whole parent and every confirmed embedded medium together, with identical accountable owner, coherent facility custody, preserved canonical identities/placement/origins and atomic acknowledgment. Independent movement, disputed associations, detachment/replacement, swaps and reconstructed configurations remain blocked. This exception is not a generic component or handover system. Broader recipients, ownership transfer, hardware access, processing/batches and correction-gap resolution remain excluded. See `docs/design/phase-11-reuse-contract.md` for profiles, transitions and verification cases. Acceptance remains deferred, not operational approval.


## User-authorized change CR-BATCH-01 — 18 October 2026

Authority: the user's Phase 12 implementation request approves UC13 and bounded on-site UC14 accounting, extending CR-MEDIA-01 and CR-PARTS-01 without rewriting their history. Trace: FR23–FR25/FR27–FR29/FR34; BR05–BR06/BR09–BR15/BR17–BR18; D3 US15, UC13–UC14, S11–S13, AT06/AT09–AT12. See `docs/design/phase-12-batch-contract.md` for the settled implementation contract.

Inputs are intact whole devices with complete confirmed separable HDD/SSD inventory or evidenced empty inventory, and qualifying basic ICT fans. Current human RECYCLE approval, accepted facility custody, exact capability and active scoped Processor are mandatory; retained safety/identity/transfer/work-return restrictions block. Standalone-drive dismantling and unsupported/inaccessible media operations are excluded. Draft selection claims nothing. Actual dismantling is an explicit evidenced human assertion; batch creation performs no physical operation. Commitment atomically freezes input decisions, positive measured kg, permanent membership, terminal parent transitions and an exact all-media detachment manifest.

CR-MEDIA-01 gains a narrow same-facility detachment exception: existing canonical identity, ownership, labels, encrypted identifiers, origin, first receipts, custody and assurance remain. Append-only detachment ends active embedding without deleting historical placement or allowing re-parenting. A surviving drive uses its existing subject as its output facet. Versioned batch-controlled HDD/SSD treatment authorization reuses the existing assurance ledger and unchanged method policy, explicitly invalidates old parent applicability, and permits justified evidence revalidation without invented repeat work. Unsupported treatment is not enabled.

Batch-origin aggregate material/component/residue lots imply no individual fragment tracing or ownership transfer. Fixed decimal measured kg, measurement provenance, honest pending output accounting, and append-only reasoned measurement corrections are required. Corrections never change membership/identity or create new production. All recorded stock remains on site; future-dispatch eligibility is a separate explicitly unavailable operation. Tolerance is the unchanged prototype assumption max(0.1 kg, 2% of measured input), not evidence of recovery truth. Unexpected media opens a permanent bounded blocking discrepancy pending separately authorized resolution. No dispatch, external receipt, final recycling closure, generic correction/component/replacement/ownership workflow or hardware operation is implemented. Manual acceptance remains deferred; final integrated acceptance is required before deployment/full validation.

On-site aggregate-origin clarification: batch/aggregate-lot control uses the immutable batch facility party in this bounded increment; canonical source/media custody rows remain unchanged. This creates neither an individual aggregate owner nor a fabricated first receipt. Future aggregate transfers require explicit acknowledged custody and must reuse existing production identities rather than seed duplicate stock.
