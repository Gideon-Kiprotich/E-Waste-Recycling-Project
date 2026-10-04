---
name: Civic Stewardship & Traceability
colors:
  surface: '#f1fcf3'
  surface-dim: '#d1ddd4'
  surface-bright: '#f1fcf3'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#ebf7ed'
  surface-container: '#e5f1e7'
  surface-container-high: '#e0ebe2'
  surface-container-highest: '#dae5dc'
  on-surface: '#141e18'
  on-surface-variant: '#404942'
  inverse-surface: '#29332c'
  inverse-on-surface: '#e8f4ea'
  outline: '#707971'
  outline-variant: '#c0c9bf'
  surface-tint: '#296a45'
  primary: '#004526'
  on-primary: '#ffffff'
  primary-container: '#1b5e3a'
  on-primary-container: '#92d5a8'
  inverse-primary: '#92d5a8'
  secondary: '#006399'
  on-secondary: '#ffffff'
  secondary-container: '#7bc2ff'
  on-secondary-container: '#004f7b'
  tertiary: '#652a00'
  on-tertiary: '#ffffff'
  tertiary-container: '#893c00'
  on-tertiary-container: '#ffb68e'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#aef2c2'
  primary-fixed-dim: '#92d5a8'
  on-primary-fixed: '#00210f'
  on-primary-fixed-variant: '#09522f'
  secondary-fixed: '#cde5ff'
  secondary-fixed-dim: '#94ccff'
  on-secondary-fixed: '#001d32'
  on-secondary-fixed-variant: '#004b74'
  tertiary-fixed: '#ffdbca'
  tertiary-fixed-dim: '#ffb68e'
  on-tertiary-fixed: '#331200'
  on-tertiary-fixed-variant: '#763300'
  background: '#f1fcf3'
  on-background: '#141e18'
  surface-variant: '#dae5dc'
typography:
  headline-xl:
    fontFamily: Public Sans
    fontSize: 32px
    fontWeight: '700'
    lineHeight: 40px
    letterSpacing: -0.02em
  headline-xl-mobile:
    fontFamily: Public Sans
    fontSize: 26px
    fontWeight: '700'
    lineHeight: 34px
    letterSpacing: -0.01em
  headline-lg:
    fontFamily: Public Sans
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 32px
    letterSpacing: -0.01em
  headline-md:
    fontFamily: Public Sans
    fontSize: 20px
    fontWeight: '600'
    lineHeight: 28px
  headline-sm:
    fontFamily: Public Sans
    fontSize: 18px
    fontWeight: '600'
    lineHeight: 26px
  body-lg:
    fontFamily: Public Sans
    fontSize: 18px
    fontWeight: '400'
    lineHeight: 28px
  body-md:
    fontFamily: Public Sans
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
  body-sm:
    fontFamily: Public Sans
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
  label-md:
    fontFamily: Public Sans
    fontSize: 14px
    fontWeight: '600'
    lineHeight: 20px
    letterSpacing: 0.01em
  label-sm:
    fontFamily: Public Sans
    fontSize: 12px
    fontWeight: '600'
    lineHeight: 16px
    letterSpacing: 0.02em
  caption:
    fontFamily: Public Sans
    fontSize: 12px
    fontWeight: '400'
    lineHeight: 16px
rounded:
  sm: 0.125rem
  DEFAULT: 0.25rem
  md: 0.375rem
  lg: 0.5rem
  xl: 0.75rem
  full: 9999px
spacing:
  gutter: 1rem
  gutter-tablet: 1.25rem
  gutter-desktop: 1.5rem
  margin: 1rem
  margin-tablet: 1.5rem
  margin-desktop: 2rem
  space-xs: 0.25rem
  space-sm: 0.5rem
  space-md: 1rem
  space-lg: 1.5rem
  space-xl: 2rem
---

## Brand & Style

The design system establishes a civic-grade, accountable, and transparent operational visual language for circular economy infrastructure in Nairobi County. It balances institutional reliability with approachable community utility.

### Brand Personality & Demeanor
- **Accountable & Civic:** Direct, structured, and free of marketing fluff or decorative excess. It operates with the seriousness of public infrastructure.
- **Dignified & Pragmatic:** Prioritizes clarity, physical handover verification, and legible data over speculative tech trends. Strictly excludes gamification, badges, tokens, and speculative gimmicks.
- **Accessible & Robust:** Designed for cross-device utility in high-glare field environments, varied connectivity, and diverse digital literacy levels.

### Design Movement
- **Modern Utilitarian / Institutional Functionalism:** Clean surfaces, structured data density, explicit borders, tactile card containment, and purposeful chromatic status signals.

## Colors

The palette is anchored in an earthy, warm-neutral foundation with restrained natural forest green for primary intentional actions, supported by high-contrast operational status tokens that meet WCAG 2.1 AA (minimum 4.5:1 for body text, 3:1 for large text and UI components).

### Palette Architecture
- **Primary Canvas:** Warm neutral foundations (`#F8F7F4` for application root, `#F4F1EA` for recessed containers and table headers).
- **Surface Elevation:** Crisp white (`#FFFFFF`) for cards, modular panels, modals, and input fields to maximize legibility.
- **Typography:** Deep forest ink (`#111814` for headings/critical metrics, `#1F2923` for primary body copy, `#4B5563` for secondary metadata).
- **Primary Brand / Action:** Forest Green (`#1B5E3A` default, `#154D30` active/emphasis, `#0F3B24` hover).
- **Operational Status Tokens:**
  - **In-Transit / Processing:** Slate Blue (`#0369A1` text/border on `#E0F2FE` background).
  - **Pending / In Review:** Warm Amber (`#B45309` text/border on `#FEF3C7` background).
  - **Exception / Flagged / Critical:** Crimson (`#B91C1C` text/border on `#FEE2E2` background).
  - **Neutral / Draft / Archived:** Muted Slate (`#4B5563` text on `#F3F4F6` background).
- **Form Controls:** Structural borders use `#D1D5DB` (default) and `#9CA3AF` (hover/interactive), with high-visibility emerald focus rings (`#15803D`).

## Typography

The typographic system utilizes `Public Sans`, an open, institutional humanist typeface engineered for rigorous government and public sector interfaces.

### Hierarchy & Usage
- **Headlines:** Set in `headline-xl` through `headline-md` for county manifests, batch ledgers, and operational facility dashboards. Headings always maintain crisp tracking to preserve legibility under direct sunlight.
- **Body:** Standardized on `body-md` (16px) for instructional, checklist, and verification content. Line height is fixed to a minimum 1.5x ratio (`24px` on `16px`) for enhanced readability.
- **Labels & Metadata:** `label-sm` (12px bold) is strictly reserved for status badges, manifest tags, and table header fields.

## Layout & Spacing

Layout is structured around an adaptive 12-column grid on desktop/tablet views and a single-column layout with safe borders on mobile handheld devices.

### Form Factor Adaptations
- **Mobile Handheld (320px – 639px):** 4 columns, `margin: 1rem` (`16px`), `gutter: 1rem`. Bottom navigation or full-width stacked handover cards. All interactive touch targets must meet a strict minimum of `48px x 48px` (exceeding the base 44px threshold).
- **Tablet / Field Terminal (640px – 1023px):** 8 columns, `margin-tablet: 1.5rem` (`24px`), `gutter-tablet: 1.25rem` (`20px`). Side-by-side inspection checklists and receipt logs.
- **Desktop Administration (1024px+):** 12 columns, `margin-desktop: 2rem` (`32px`), `gutter-desktop: 1.5rem` (`24px`). Max container constraint capped at `1280px` for optimal visual scanning.

## Elevation & Depth

To guarantee high visibility across varied field hardware, elevation avoids deep drop shadows, tinted ambient glows, and glossy glassmorphism. Visual depth is established primarily through structural borders, tonal shifts, and minimal offset keyline shadows.

### Elevation Hierarchy
- **Base Canvas (Level 0):** `#F8F7F4` background with no elevation.
- **Surface Layer (Level 1):** Solid `#FFFFFF` cards, checklists, and panels defined by a `1px` crisp border (`#E5E7EB` or `#D1D5DB`) paired with a minimal mechanical contact shadow: `box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)`.
- **Raised Interactive Layer (Level 2):** Dropdowns, audit popovers, and sticky sub-headers: `box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.07), 0 2px 4px -2px rgba(0, 0, 0, 0.05)` with `#D1D5DB` borders.
- **Overlays & Dialogs (Level 3):** Verification modals, handover confirmation prompts, and emergency overrides: `box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1)` anchored above a high-contrast backing scrim (`rgba(17, 24, 20, 0.5)`).

## Shapes

The design system maintains a disciplined, soft-cornered geometry (`roundedness: 1`). Radius is restrained to 6px (`rounded-md`, 0.375rem) for form controls and action triggers, and 8px (`rounded-lg`, 0.5rem) for operational cards, data modals, and alert containers. Fully circular shapes (pills) are prohibited, ensuring badges and buttons convey structural utility rather than playful consumer aesthetics.

## Components

### Buttons & Operational Actions
- **Primary Action:** Solid forest green (`#1B5E3A`), white label (`#FFFFFF`), `6px` radius. Hover state is `#0F3B24`; active state is `#154D30`. Minimum height `44px` (recommended `48px` for field operations).
- **Secondary / Action Outlined:** White background (`#FFFFFF`), border `1.5px` solid `#D1D5DB`, label `#1F2923`. Hover applies `#F4F1EA` background and `#9CA3AF` border.
- **Destructive Action:** Solid crimson (`#B91C1C`) or outlined `#B91C1C` border with `#B91C1C` label. Must invoke an audit confirmation dialog before triggering ledger mutations.

### Status Badges
- **Composition:** Mandatory trio of textual label, accessible icon (inline SVG), and explicit color pair.
- **Pending / In Review:** `#FEF3C7` background, `#B45309` border and text, Clock icon.
- **In-Transit / Processing:** `#E0F2FE` background, `#0369A1` border and text, Refresh or Arrow icon.
- **Verified / Completed:** `#DCFCE7` background, `#15803D` border and text, Check-circle icon.
- **Flagged / Hazard Alert:** `#FEE2E2` background, `#B91C1C` border and text, Alert-triangle icon.
- **Geometry:** Border radius `4px`, padding `2px 8px`, typography `label-sm`.

### Form Controls & Input Fields
- **Fields:** Height `44px`, background `#FFFFFF`, border `1px` solid `#D1D5DB`, placeholder `#9CA3AF`.
- **Focus State:** Explicit `ring-2 ring-[#15803D]` with zero ambient blur, ensuring high-contrast keyboard navigability.
- **Error States:** Border changes to `1.5px solid #B91C1C`, accompanied by an inline icon and explicit error text in `#B91C1C` directly below the control.

### Operational Role Indicator
- **Role Bar:** Persistent global header element indicating current active capacity: `Consumer`, `Collector`, `Processor`, or `County Administrator`.
- **Styling:** Recessed pill tab on `#F4F1EA` with an active state in `#FFFFFF`, high-contrast text `#111814`, and a subtle `1px` border to clarify permission boundaries.

### Milestone Timelines & Checklists
- **Handover Checklist:** Checkbox tiles with touch target `48px` min height, `#D1D5DB` borders that transition to `#1B5E3A` with a green tick on completion.
- **Custody Timeline:** Vertical step rail (`2px` solid `#D1D5DB`). Completed milestones use a `#1B5E3A` circular node with check icon; active milestones display a pulsing amber/blue hollow ring; future stages use a static `#9CA3AF` dot.

### Banners & Confirmation Dialogs
- **Data Quality / Demo Banner:** Sticky full-width indicator for test/synthetic environments (`#FEF3C7` background, `#B45309` text, `1px` border bottom `#FDE68A`) stating clearly: *"Synthetic Demo Environment — Manifest records are not legally binding."*
- **Audit Confirmation Dialog:** High-contrast modal containing the manifest summary, dual-party verification status, and an explicit checklist requiring confirmation before irreversible state progression.