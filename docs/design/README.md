# Reviewed design handoff

The supplied, reviewed Stitch export is `stitch_e_waste_connect_nairobi_prototype/`. Phase 1 uses [S01 access/recovery HTML](stitch_e_waste_connect_nairobi_prototype/s01_access_and_recovery_workspace/code.html), its [screen image](stitch_e_waste_connect_nairobi_prototype/s01_access_and_recovery_workspace/screen.png), and the [shared design guidance](stitch_e_waste_connect_nairobi_prototype/civic_stewardship_traceability/DESIGN.md).

S01 supplies the green civic palette, header, access navigation, form cards and privacy sidebar for login, Consumer registration, recovery, reset and notice screens. The original export is reference material; its scripts and remote assets are not executed or imported by the application. Blade and local CSS implement the reviewed visual direction with system-font fallbacks.

Deliverable 2 controls scope and Deliverable 3 controls behavior. Generated role selection, national-ID/passkey access, regulator/IPRS integrations, ledger telemetry, certification claims and invented contacts are omitted. Real routes replace simulated tabs/errors; no later operational functionality is presented as available. See [Phase 1 report](../phase-1-report.md).

The full export remains intact locally. Phase 1 tracks S01 HTML/image and shared guidance. Phase 2 additionally tracks the unchanged S02 consumer overview/device-list and S03 registration/detail HTML/images; unused S04–S16 exports remain ignored, not deleted. Review the relevant original screens again when their phases begin.

Phase 2 translates only the owned-device list, registration, self-report detail/history and identity label. The registration/detail composition, card panels and palette remain references; real scoped routes replace simulation. Unsupported category/subtypes, required model/estimated mass, duplicate match disclosures, invented operational milestones, certifications and later workflow controls are omitted according to Deliverables 2 and 3. See [Phase 2 report](../phase-2-report.md) for coverage and manual acceptance.

After Phase 2 closure, CR-PARTS-01 adds an approved documentation-only [standalone-parts handoff](parts-intake-handoff.md) for S02/S03 and existing owned views/labels. It uses the reviewed visual direction; no new Stitch exports or implemented screens are claimed. See [the bounded schema/behavior specification](../parts-intake-design-report.md) before any separately authorized implementation. Downstream screens and processor detachment remain later-module work.
