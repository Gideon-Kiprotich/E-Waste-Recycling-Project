# Standalone-parts intake UI handoff

Date: 5 October 2026. Change: **CR-PARTS-01**. Status: implementation-ready written handoff; no new Stitch export, rendered screen or browser acceptance is claimed. Scope/schema/test authority: [parts intake design report](../parts-intake-design-report.md). D2/D3 v1.1 retain their baseline exports and CR-ID-01.

## Visual continuity and screen boundary

Use reviewed S02 consumer overview/list, S03 registration/detail and [shared Stitch guidance](stitch_e_waste_connect_nairobi_prototype/civic_stewardship_traceability/DESIGN.md) with the existing Blade/CSS implementation. Retain civic green actions, pale green background, white bordered cards, two-column form/guidance and detail/QR composition at desktop, restrained corners and local system-font fallbacks. Use existing CSS tokens (`--primary`, `--muted`, `--border`), error/success/information feedback, field/select components and visible focus style. Do not import export scripts/remote fonts or add notification/component libraries.

Only S02/S03 and their existing edit, history, label/manual-lookup views change in the first increment. No collection point, pickup, detachment, inspection, sanitization, batch, inventory or resale control becomes available. Later screen obligations in the report are contracts for their own modules, not new reviewed exports.

## Registration and category loading

Keep the existing server-rendered GET category picker and POST registration flow usable without JavaScript. Add **Standalone ICT part** (`standalone_part`) alongside the six existing categories. For it, show a required **Part type** select with **Hard disk drive (HDD)** (`hdd`), **Solid-state drive (SSD)** (`ssd`), **Small ICT cooling fan** (`ict_cooling_fan`). Keep Router/Keyboard restricted to Peripheral; omit irrelevant subtype controls from the submitting form. No “Other”, battery or bulk option. Invalid GET selections show safe scope guidance and a usable picker, without loading an unsupported form or retaining a private identifier.

“Load category questions” performs the existing reload. Explain that changing the selection discards unsaved answers. Render the POST form only for a valid category/subtype with the correct question schema. A failed POST must show its original safe selection/questions rather than silently reverting to Laptop. Preserve a valid submission reference. Each successful new selection gets a fresh reference; a failed unchanged submission retains its reference for retry.

For all parts, the registration heading is **Register a standalone part**; button **Register part and create label**. Optional **Make / model** remains 150 characters. Show **Serial number (optional)** with guidance **“Leave blank if unknown. A known serial can belong to only one registered item.”** IMEI selection is unavailable for parts; server validation still rejects forged IMEI. Old device forms retain Serial/IMEI behavior. Do not collect capacity, data contents, credentials, removal date, parent device, batch or quantity. One physical unit per form.

Retain the private JPEG/PNG uploader and 5 MiB limit. Guidance: **“Use a photo of the part only. Do not include people, private screens, labels with sensitive details or stored data. Select the file again after a failed submission.”** A photo never establishes type, safety, parentage or data clearance.

## Self-report controls and guidance

Render the exact keyed questions/vocabularies in report §3; do not reuse laptop power/display tests. Functional options read **Worked when previously used**, **Did not work when previously used**, **Unknown / not previously observed**. YES/NO/UNKNOWN questions and typed fan electronics options remain explicit labelled selects with no preselected healthy answer.

Common guidance: **“Record only what you already know. Unknown is a useful answer. Do not power, dismantle or inspect inside a part to complete this form.”** Notes remain required, max 1,000 characters, with the existing warning against contacts, passwords, serials and contents. Visible physical damage and safety concern retain unknown choices; a safety answer never certifies suitability for pickup.

For HDD/SSD, replace editable data declaration with visible text **“Data-bearing: Yes — storage medium.”** Explain **“This remains a data-bearing item even if it is empty, broken or you previously erased it. Registration does not verify sanitization or approve reuse.”** No editable or authoritative hidden data-status control. The consumer supplies no sanitization status or proof-of-empty claim.

For fans, place eligibility guidance before the remaining questions: **“One computer/ICT cooling fan or blower, up to 24 V DC and 140 mm across its housing, excluding free leads. Mains/industrial fans and separate control modules are excluded.”** Scope choices are Yes/No/Unknown; no powered measurement or dismantling is suggested. If unknown, explain that registration cannot continue until the type is known from existing knowledge or an accessible label, without instructing a new test.

Fan electronics options read **Basic integral fan circuitry**, **Additional control/network/storage module**, **Unknown**. Explain that ordinary motor-driver/tachometer/PWM circuitry counts as basic. A positively qualified fan with Unknown electronics can register, with **“Electronics/data status needs review. Registration does not approve collection or treatment.”** Known additional modules block initial registration. Never call all fans data-free. Data-bearing display is derived, not editable: **“No — consumer self-report”** only for scope Yes/basic circuitry; otherwise **“Unknown — review needed.”** Newly learned out-of-scope/electronics information may be recorded in a later self-report revision without erasing its identity/history.

No parent selector or removal timeline exists. Origin guidance: **“Register an already separate part. This registration does not record a parent device or removal history.”** The page never instructs removal from a tracked device.

## Success, rejection and error states

Successful registration redirects to the same owned item-detail route with green text/icon feedback: **“Your part is registered. Its identity label is ready.”** Revisions use the existing retained-history success message. An identical successful retry goes to that same detail/label; changed information under the same reference keeps the existing conflict guidance. Reloading/printing never mints another identity.

New duplicate registration keeps exactly **“This serial/IMEI is already registered. Please recheck the number entered.”** Associate it with the Serial number input even though the shared message includes IMEI. Do not show the matched owner/item/model/code/link. Unknowns remain valid and display “Unknown / not provided”, never a shared identifier literal.

Fan qualification failures use the exact messages in report §1 and link to `answers.fan_scope` or `answers.fan_electronics`. Missing/invalid answers link to their own control; invalid category/subtype links to the picker. A rejected parent/origin/quantity/data-status request uses **“This field is not accepted for standalone registration.”** in the single summary and links to the registration heading when no user input corresponds to that forged field. Do not render a fabricated parent input or run a private parent lookup. Unexpected storage failure uses safe retry guidance; unrelated SQL failures are not mislabeled duplicates. Unsupported streams show the exclusions, with no promised disposal destination.

Use the existing feedback summary and field ID mapping. Errors use one appropriate `role="alert"` summary, meaningful text/icon and red styling; field messages do not each create another live region. Success/information uses `role="status"`; static data/origin guidance is not a repeated announcement. Keep messages visible. After a failed submission, focus the summary; summary links focus the relevant field/picker/heading. Use unique IDs, `aria-describedby` for hints/errors and `aria-invalid` on invalid controls. Never echo a malicious submitted value into an error message.

Retain safe category/type/model/answers/notes and valid submission reference. Identifier and photo remain blank and need private re-entry/reselection; passwords/tokens never flash. A rejected forged parent/storage value is neither displayed nor retained. HTML rendering escapes all text. The part form must not expose internal hashes/UUID facet plumbing, SQL, credentials or application configuration.

## Owned list, detail, history and labels

Keep the existing owned list/cards/pagination, manual lookup and route policies. The surrounding **My devices** navigation may remain; introductory copy and empty state say **“Register one supported device or standalone part at a time.”** CTA may say **Register a device or part**. Use friendly subtype labels, not raw codes. Part cards show **Standalone ICT part · HDD/SSD/Small ICT cooling fan**, optional model/unknown, opaque code and actual state. A storage facet is never a second card/unit. Do not add speculative counts or operational milestones.

Detail displays **Origin: Standalone registration — no parent/removal history recorded**, type, actual registration/custody, private-serial presence, effective data-bearing text, current self-report and retained versioned answers. A drive's storage facet is summarized as its media type/data obligation on that same page, never linked to another physical ID. Present **“Sanitization has not been verified by this registration.”** as guidance, not a fabricated sanitization status/attempt. Ambiguous fan/safety information shows review guidance; no approved collection/pathway badges. Updating observations cannot edit classification, owner, model or identifier.

History contains only actual registration and self-report revisions with their own question versions. No invented parent, removed-from, inspection, collection or treatment events. Existing whole-device history remains readable. QR/manual code, private photo access, printable label and downloaded PNG continue to use the one physical subject. The print page may include friendly part type/model and code, never serial/data contents/contacts or an extra storage identifier. Label replacement preserves the code.

## Mobile, accessibility and acceptance

At 360px, stack the form/detail and guidance/QR cards; keep 16px or larger body text, readable hints, at least 48px primary/input touch heights, visible focus and natural wrapping for codes/long feedback. No horizontal page overflow or clipped selects, error summary or print controls. Keep source/keyboard order aligned: picker, facts/serial/photo, eligibility/observations, submit, then guidance/detail actions. Do not steal focus on every select change; only explicit reload/submission navigation manages focus.

Verify actual contrast for existing/new state text and control/focus boundaries; preserve skip link, labelled fieldsets/legends and accessible QR/photo descriptions. Colour never carries the entire meaning. New controls use standard semantic HTML and existing minimal focus JavaScript. Do not claim a pass from design-token values or HTML assertions alone.

PI09/PI10 in the report require separate automated markup/feedback tests and user/agent-observed desktop/360px, keyboard, screen-reader, error, upload, retry, label/scan/manual-lookup checks after implementation. No such new checks have been performed for this written handoff.
