# Device-parts scope assessment

Current decision — 5 October 2026: **CR-PARTS-01 approves a bounded standalone-intake design for individual HDDs, SSDs and small low-voltage ICT cooling fans. Implementation is not authorized by this documentation increment.** Phase 2 is closed at `c12fc2cc4c148e6e780ed0a38676996fbd6161aa`. The identifier migration blocker was resolved before this branch; it is not a parts-design dependency.

Settled contracts and implementation tests: [parts intake design report](parts-intake-design-report.md). Registration/list/detail guidance: [written UI handoff](design/parts-intake-handoff.md). Matching appended D2/D3 CR-PARTS-01 entries form v1.1 while preserving the baseline and CR-ID-01. The approved scope is software design authority from the project user, not stakeholder/collection-point validation. Loose batteries and processor-derived work are deferred; no parts have been implemented.

## Earlier assessment — retained history

The following assessment records the proposal and unresolved state before bounded design approval. Its proposed schema, broad workflow work list, old Phase 2 status and approval dependencies are historical; the final decision section and settled specification supersede them where they conflict. This does not erase the research or assert that deferred work is complete.

Date: 5 October 2026. Status: **proposal only, not authorized implementation**. Prepared at the user's request during Phase 2. No parts categories, screens, schema, inspection rules or collection permissions have been added. Phase 2 remains open; this assessment does not start Phase 3.

## Baseline and evidence

D2 §3.4/A4 limits ordinary intake to laptops, desktops, mobile phones, flat-panel monitors, printers, routers and keyboards; loose batteries are expressly excluded. More detailed component tracking/inventory is stretch scope (§4). D3 §3.5 and §4.5 already propose minimal `storage_assets` linked to data-bearing parents and batch output lots for materials/components/residue. That supports storage accountability after dismantling, not consumer registration of arbitrary loose components. Neither reviewed S03 nor existing Phase 2 code completes standalone HDD/SSD/fan/battery intake.

**Verified public findings:** Kenya's NEMA simplified guidance includes components/subassemblies/consumables in its e-waste definition and lists end-of-life batteries among waste categories. It discusses segregated handling and appropriate collection/recycling channels. This supports considering parts as e-waste, but does not prove a particular Nairobi point accepts a battery, drive or fan, validate a project stakeholder, or authorize an unrestricted pickup service. Source: [NEMA, Simplified E-Waste Guidelines (2025), glossary and category/handler sections](https://nema.go.ke/wp-content/uploads/2025/11/E-WASTE-GUIDELINES.pdf). This assessment does not interpret draft provisions as enacted law or claim a regulatory integration.

Lithium-ion batteries can retain energy and present fire hazards, especially when damaged or mishandled; ordinary household rubbish/recycling is not an appropriate channel. Damaged batteries require advice from an appropriate manufacturer/handler. Source: [US EPA, Used Lithium-Ion Batteries](https://www.epa.gov/recycle/used-lithium-ion-batteries). This is technical safety evidence, not Kenyan legal advice or proof of local acceptance. Other chemistries need separate competent review; do not assume lithium guidance covers all batteries.

HDDs and SSDs are information storage media. Sanitization must match the media and its capabilities, with verification/validation records; applying magnetic-media techniques to flash may leave data exposed, and degaussing is unsuitable for SSDs. Source: [NIST SP 800-88 Rev. 2 (September 2025), §§3–4](https://nvlpubs.nist.gov/nistpubs/SpecialPublications/NIST.SP.800-88r2.pdf). Recording a method or consumer “empty” claim is not proof of sanitization, and the platform cannot itself certify physical work.

Sources were inspected on 5 October 2026. No partner interviews, acceptance agreements, licences, screenshots or physical trials were obtained. The proposals below are engineering choices; their operational parameters and stakeholder approval remain unresolved.

## Two different origins

1. **Separately discarded part:** the consumer already has a loose HDD, SSD or fan, with no tracked parent in this system. Register one physical unit with its own permanent subject/item identity, owner custody, origin `standalone`, optional protected serial and dedicated observations. Do not invent a parent or historic removal event. Consumer statements about origin remain self-report.
2. **Detached component from a tracked parent:** an authorized processor records removal from a known parent during approved work/dismantling. Link the existing storage/component identity, parent, removal event, actor, facility and batch/output lineage as applicable. Reuse an already tracked storage identity rather than assigning two identities to the same drive. Inherit current facility custody through an atomic recorded operation; do not create another consumer registration or first-collection receipt.

A consumer must not be able to claim a parent link or detach someone else's device through the ordinary form. A loose part allegedly removed before registration stays standalone with a narrative claim unless an authorized, evidenced reconciliation establishes the tracked parent. This preserves the distinction between observed provenance and statements.

## Smallest coherent extension proposed

Approve a bounded **small ICT parts** extension: individually registered HDDs, SSDs and small low-voltage ICT cooling fans only, with a shared origin/lineage contract and media-specific inspection. Exclude industrial/mains-powered fans, mixed scrap heaps, arbitrary components, repair inventory, resale and bulk imports. Use a versioned subtype/reference vocabulary rather than “Other part”. Batch quantities can remain output lots; every independently tracked storage medium must remain individually identifiable for its data gate.

**Batteries remain outside ordinary pickup.** A future restricted intake option should be considered only after competent safety review and an actual accepting channel is documented. If approved, it needs a distinct battery subtype/chemistry and condition declaration, blocking review/hold state, qualified handling authorization and point/facility capabilities. Unknown chemistry, swelling, leakage, damage or heat must not default to routine pickup, and an administrator cannot simply override an absent safe channel. The application must not instruct consumers to dismantle, test, charge or transport suspect batteries. Until those decisions and operating arrangements exist, keep the current exclusion visible; recording a hazard on a whole device remains available.

This recommendation covers all four examples but deliberately separates ordinary drive/fan eligibility from an unresolved restricted battery stream. A dropdown addition alone would bypass data, safety, custody and reporting rules and is insufficient.

## Required changes by workflow

### Registration and identifiers

Add approved subtype labels, origin rules and dedicated self-report questions to S03. Ask about physical condition/unknowns without requiring a consumer to power, open or read the part. Drive observations distinguish HDD/SSD, interface/model if known, and suspected data; fans distinguish the small ICT scope. Battery declarations, if approved later, need chemistry/size/condition unknowns and a clear restricted-channel outcome. Review accessibility, feedback, scope messages and safe retained fields.

Reuse CR-ID-01: a known serial shares the global normalized encrypted/HMAC namespace with existing devices. Unknown or absent serials remain acceptable and never use a shared literal “unknown” hash. An IMEI is not a substitute for a drive/fan/battery serial. Do not generate manufacturer identifiers or infer them from photos. The opaque platform label still works for a part with no readable serial. Manufacturer-specific serial collisions would require an explicit requirements change, not a hidden exemption.

### Inspection, hazards and data-bearing status

Version category/subtype question sets and deterministic assessment rules; do not apply laptop boot/display questions to a loose fan or drive. Technician inspection and human approval remain separate from consumer self-report. Drives need media/capability and work-test observations without uploading contents; unsafe/inaccessible/unidentified media goes to review, not an invented “pass”. No software rule certifies battery safety.

Engineering choice: HDD/SSD intake defaults to data-bearing **YES** and cannot become eligible for reuse merely because the consumer selects NO. Unsupported media/capabilities or uncertain sanitization block release under BR09. Keep successive attempts, method, tool/version, actor, date, evidence, verification and validation tied to the same physical storage identity. A simple passive fan normally has no user-data storage; that is an assumption for the narrow fan subtype, not a verified statement about every smart controller. Ambiguous electronics remain UNKNOWN until inspection.

Battery flags require explicit chemistry/condition information and safe-channel review, not one unrestricted “battery” checkbox. Hazardous/unknown battery-containing parts and whole devices retain holds across routing, custody and processing.

### Collection-point compatibility and routing

Extend `categories`, `place_categories`/acceptance configuration, service-area eligibility and operator capabilities to the approved part types. A point's acceptance of laptops does not imply acceptance of loose batteries or storage media. Record who reviewed each capability and its evidence/limits; demonstration values must remain simulated. For batteries, acceptance must cover chemistry, condition, quantity/storage/transport limits and the actual restricted receiving channel before offering a point or pickup. Do not infer compatibility from public guidance.

Later constrained routing must include the accepted subtype, risk/hold restrictions and realistic capacity/mass assumptions. Small parts do not mean zero mass or unrestricted load. Real measured values and conservative estimates must remain distinguishable. This assessment adds no points, services or routes.

### Custody and processing lineage

Standalone parts follow the existing item-specific acknowledged receipt/handover rules. Derived parts inherit custody at documented detachment, then need their own subject-specific transfers when physically separated. Detachment must lock affected subjects, recheck facility/parent authorization, command key and versions, and atomically append origin links/events/custody. It must reject a second detachment of the same physical identity, stale parent state and conflicting batch membership. Parent terminal/dismantled state and unresolved exceptions remain enforced.

For dismantling, link recovered parts to the parent input and batch/output lot, retaining measured production and subsequent movements. If a detached drive already has a `storage_assets` identity, promote/use that physical identity; do not create an unrelated standalone identity to avoid its history or sanitization gate. A reusable fan/component outcome needs recorded testing and destination evidence, not a marketplace workflow. Dispatch, acknowledgment and completed downstream processing remain different events.

A drive/fan/battery mass derived from a weighed parent/batch is not a second input mass. Choose a documented allocation method for component lots and remaining residue, reconcile balance, and never add parent kg plus child kg as two first collections. Standalone parts received independently are new input units; derived parts are outputs/provenance descendants.

### Reports, privacy and tests

Report whole-device units, standalone-part units and derived components separately; label denominators and origin cohorts. Count first collection once, keep parent-to-batch-to-output lineage, and separate measured kg from estimates. Consumer views must suppress other owners' batch inputs and private identifiers/contents. Export templates need new subtype/origin fields and the required spreadsheet-injection protection.

Tests must cover each approved subtype, excluded parts, optional identifiers/global collisions, forged parent links, origin permissions, versioned questions, unknowns, drive data defaults and failed/uncertain sanitization gates, battery holds and incompatible points, denied role/facility access, stale/concurrent detachment, same-command retries, inherited custody, batch/output identity reuse, rollback/evidence cleanup, mass balance and no double counting. Add browser/manual acceptance for subtype guidance, keyboard focus, mobile layout and privacy-safe timelines. Physical handling/point acceptance needs real operational validation separate from software assertions.

## Requirements, screens and schema to revise before implementation

**D2:** §3.4/A4 category limits and battery exclusion; §4 P0/P1 component boundary; FR04–FR06 registration/directory, FR08 hazard/category eligibility, FR15–FR18 identity/receipt/custody, FR19–FR26 inspection/sanitization/work/batches, FR27–FR29 lineage/corrections, FR31–FR32 report definitions and FR34 atomic commands. Revise BR01, BR09 data gate, BR10–BR13 lineage/mass/reporting, BR17 hazards and BR18 terminal-state transitions. Extend AT02/AT03/AT05–AT11 where these behaviors apply. Use a new explicitly approved change entry; do not silently amend baseline or treat this proposal as approval.

**D3:** US03/US04 and processing stories US09–US17; UC03/UC04 and UC08–UC17; §3.5 origin/detachment lineage; §§4.2–4.6 ERDs/dictionary/constraints; §5 commands and events; §6 inspection/rule sets; §8 acceptance/report cohorts. Reviewed screens needing amendments: S03 registration/detail, S04 request compatibility, S05 point directory, S08 receipt, S09 custody/intake, S10 inspection/decision, S11 sanitization/work/release, S12 batch/output workspace, S13 history and S15 reports; S14 correction/exception handling must include origin disputes. Obtain reviewed handoffs for these states before implementing them; do not fabricate screens or claim Stitch already covers them.

**Schema proposal requiring review:** keep `subjects` as the physical identity and `items` as the intake projection. Extend `categories`/versioned subtype reference definitions and observations. Add constrained origin linkage such as `part_origins(subject_id PK/FK, origin_kind, parent_subject_id?, detachment_event_id?, batch_id?)`, with standalone requiring null parent/removal references and derived requiring both. Parent/child cannot be equal; cycles and actual parent eligibility require locked cross-record service checks. Preserve category/model/identifier immutability; parent corrections use recorded review.

Revise `storage_assets` from a mandatory-parent-only definition to a **storage facet on the same physical subject**, linked either to standalone intake or a tracked parent. Relax/review subject-kind assumptions so item intake and storage facets can share one physical identity; do not produce separate item/storage IDs for the same standalone drive. Existing parent-linked storage subjects, when later implemented, must gain the intake facet on their existing identity if needed. Define FK/XOR origin constraints and unique facet/physical-unit rules before migrations. This is a proposed dictionary change, not a completed schema or a decision to implement general component inventory.

Add part-aware capability/hold metadata to places/operator acceptance, typed inspection/sanitization applicability, subject-specific custody transitions, and recoverable component identity references in `output_lots`/lineage. Existing `batch_members` and movement ledgers need explicit rules preventing the same child from being counted as a new independent input to its originating batch. Extend safe event vocabulary and report projections; never copy plaintext serials or data contents into events.

## Unresolved decisions and handoff

- User/stakeholder approval of the narrow HDD/SSD/fan scope and the distinction between standalone and derived units; no such approval is assumed here.
- Actual Nairobi point/operator acceptance, facilities, media handling authorization, quantities/capacity and evidence. No real partner capability is verified.
- Whether any loose battery category should be admitted at all; accepted chemistry/condition/size, qualified handling and emergency procedures, and restricted transport/receiving arrangements require competent review. The default proposal keeps ordinary pickup excluded.
- Final physical-subject/storage-facet schema and rules for detached storage during repair versus terminal dismantling, serial-less matching and controlled provenance correction. Do not infer physical sameness from a photograph or self-report.
- Approved subtype inspection/sanitization methods, evidence retention and fan controller classification; technical guidance is not a stakeholder-approved local operating procedure.
- Component measurement/allocation and report denominators, reviewed screens and migration/backfill plan. No historic records should be reclassified automatically.

After approval, record a separate D2/D3 change, obtain reviewed UI states, settle origin/storage identity and capability contracts, then schedule a bounded implementation phase with acceptance tests. Resolve the current Phase 2 identifier migration blocker independently; do not mix parts implementation into its repair or use this assessment to close Phase 2.

## Approved bounded design — CR-PARTS-01

The user now authorizes the following decisions; they supersede the earlier approval/schema/handoff uncertainties for this subset, without claiming unavailable interviews or real service acceptance.

- **Vocabulary settled:** category `standalone_part`, subtypes `hdd`, `ssd`, `ict_cooling_fan`. Existing categories remain unchanged. One already separate physical unit per form; no unknown storage category, mixed components, quantity, bulk inventory or resale.
- **Fan settled:** dedicated ICT fan/blower no more than 24 V DC and 140 mm largest housing/frame dimension excluding free leads; integral motor-driver/tachometer/PWM circuitry allowed. Mains/industrial fans and separate control/network/storage modules are excluded. Scope NO/UNKNOWN blocks initial intake without requiring a test. Known additional electronics block initially; otherwise eligible unknown electronics gives data UNKNOWN/review guidance. These limits are project scope choices, not technical certification. Consumer observations and later newly learned uncertainty remain distinct from technician findings.
- **Identity/storage settled:** parts reuse permanent item/subject/EWC and the unchanged encrypted global serial comparison namespace; no manufacturer/owner/type exception. Parts accept optional Serial, not IMEI; unknowns remain null. Every HDD/SSD has a two-column `storage_assets` facet on that exact subject ID, with database-backed reciprocal/immutable guards. No second identity, data-status projection, custody/count or parent/removal history. Existing six-category rows/history/retries stay compatible through a forward migration; no backfill guesses or reset.
- **Data/questions settled:** canonical HDD/SSD data YES is enforced on registration/revision/direct writes and never cleared by a consumer. Frozen `PARTS-SELF-REPORT-1.0` asks part-specific prior observations/unknowns, with no power/dismantling/internal-content requirement. Future treatment/release gates recognize the same drive; registration does not verify sanitization.
- **Origin and first increment settled:** consumer-supplied parent/removal/origin/storage fields are rejected, not resolved into links. First implementation is limited to standalone registration, owned views/revisions/labels, metadata and tests, through existing services/styles/policies. The handoff is a concrete written extension of reviewed S02/S03; no new Stitch export exists or is required to finish the design.

**Derived components remain deferred processor work.** D3's future parent-linked storage becomes a facet of the same canonical physical item, with constrained parent/placement/removal metadata added only with that workflow. An already identified drive is reused at detachment, not registered again. Future facility custody and parent/batch lineage must not fabricate consumer first collection or duplicate parent/child kg. No processor detachment, component inventory or provenance reconciliation is implemented now.

**Batteries remain excluded, not an unresolved dropdown choice in this increment.** Any later restricted battery stream needs a separate approved scope, competent safety review and a verified receiving/handling channel; no ordinary unrestricted pickup or handling instruction is added. Arbitrary parts, industrial/mains fans, bulk inventory and resale are also excluded.

**Operational and later-module limits:** real Nairobi point/facility/operator acceptance, safety arrangements, media treatment capabilities and mass parameters remain unverified. The design binds subtype compatibility, inspection, sanitization, custody, measured mass and report-cohort contracts in report §6; it does not schedule their implementation now. Later modules must settle their own technical methods/allocation/evidence before offering those operations. Lack of interviews is not a blocker for this bounded software specification. No material registration/identity/schema decision remains open within the approved scope.
