---
name: ShuttleSync Kinetic
colors:
  surface: '#111223'
  surface-dim: '#111223'
  surface-bright: '#37384b'
  surface-container-lowest: '#0c0d1d'
  surface-container-low: '#191a2c'
  surface-container: '#1d1e30'
  surface-container-high: '#28283b'
  surface-container-highest: '#323346'
  on-surface: '#e1e0f9'
  on-surface-variant: '#c3c6d8'
  inverse-surface: '#e1e0f9'
  inverse-on-surface: '#2e2f41'
  outline: '#8d90a1'
  outline-variant: '#424655'
  surface-tint: '#b4c5ff'
  primary: '#b4c5ff'
  on-primary: '#002a77'
  primary-container: '#0d5ceb'
  on-primary-container: '#e2e6ff'
  inverse-primary: '#0053da'
  secondary: '#dcfdff'
  on-secondary: '#00373a'
  secondary-container: '#00f1fd'
  on-secondary-container: '#006a6f'
  tertiary: '#4edea3'
  on-tertiary: '#003824'
  tertiary-container: '#007751'
  on-tertiary-container: '#83ffc6'
  error: '#ffb4ab'
  on-error: '#690005'
  error-container: '#93000a'
  on-error-container: '#ffdad6'
  primary-fixed: '#dbe1ff'
  primary-fixed-dim: '#b4c5ff'
  on-primary-fixed: '#00174b'
  on-primary-fixed-variant: '#003ea8'
  secondary-fixed: '#6ff6ff'
  secondary-fixed-dim: '#00dce6'
  on-secondary-fixed: '#002022'
  on-secondary-fixed-variant: '#004f53'
  tertiary-fixed: '#6ffbbe'
  tertiary-fixed-dim: '#4edea3'
  on-tertiary-fixed: '#002113'
  on-tertiary-fixed-variant: '#005236'
  background: '#111223'
  on-background: '#e1e0f9'
  surface-variant: '#323346'
typography:
  display-lg:
    fontFamily: Josefin Sans
    fontSize: 32px
    fontWeight: '700'
    lineHeight: 40px
    letterSpacing: -0.02em
  headline-md:
    fontFamily: Josefin Sans
    fontSize: 24px
    fontWeight: '700'
    lineHeight: 32px
    letterSpacing: -0.01em
  headline-md-mobile:
    fontFamily: Josefin Sans
    fontSize: 20px
    fontWeight: '700'
    lineHeight: 28px
    letterSpacing: -0.01em
  title-md:
    fontFamily: Josefin Sans
    fontSize: 18px
    fontWeight: '600'
    lineHeight: 24px
    letterSpacing: 0em
  body-base:
    fontFamily: Josefin Sans
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
    letterSpacing: 0em
  label-caps:
    fontFamily: Josefin Sans
    fontSize: 11px
    fontWeight: '700'
    lineHeight: 16px
    letterSpacing: 0.12em
rounded:
  sm: 0.125rem
  DEFAULT: 0.25rem
  md: 0.375rem
  lg: 0.5rem
  xl: 0.75rem
  full: 9999px
spacing:
  base: 8px
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 32px
  gutter: 16px
  margin-mobile: 16px
  margin-desktop: 32px
---

## Brand & Style

The design system embodies a high-energy, tech-forward athletic aesthetic tailored for the precision of badminton and the power of AI analysis. It is built on a "Digital Court" philosophy—merging the physical intensity of the sport with the data-rich telemetry of modern performance tracking.

The visual style is **Corporate Modern with a High-Contrast/Cyber influence**. It utilizes a deep, immersive dark-mode foundation that mimics a floodlit arena, allowing vibrant accents to pop with functional urgency. The interface should feel disciplined, rapid, and premium, evoking the sensation of a professional coaching dashboard.

**Key Principles:**
- **Velocity:** Use slanted lines, sharp geometry, and condensed tracking to imply movement.
- **Precision:** Maintain a strict 8px grid to reflect the boundary-accuracy of court lines.
- **Illumination:** Utilize "Action Cyan" for interactive elements to simulate digital overlays and real-time tracking data.

## Colors

The palette is optimized for high-visibility in dark environments. 

- **Primary (ShuttleSync Blue):** Anchors the brand. Used for major CTAs and structural branding elements.
- **Secondary (Action Cyan):** The high-energy interactive color. Use for hover states, progress indicators, and active telemetry data.
- **Tertiary (Emerald Green):** Dedicated to "Available" states, success confirmations, and positive performance metrics.
- **Neutral (Deep Space Navy):** The core scaffold. Backgrounds should use the deepest navy, while surfaces utilize slightly lighter variants to create perceived depth.
- **Court White:** Reserved for primary headlines and thin, high-contrast borders that mimic court tape.

## Typography

This design system uses **Josefin Sans** exclusively to maintain a sleek, geometric, and futuristic appearance. 

**Usage Guidelines:**
- **Emphasis:** Use `label-caps` for all form labels and small metadata headers. The wide letter-spacing is critical for the "telemetry" look.
- **Dynamics:** Headlines should use tighter tracking and heavier weights to feel impactful and "heavy," like a powerful smash.
- **Readability:** Body text is kept at a clean 14px to allow for high data density on dashboards without sacrificing legibility against the dark background.

## Layout & Spacing

The layout follows a **12-column fluid grid** for desktop and a **4-column grid** for mobile. 

- **Rhythm:** All spacing must be a multiple of the 8px base unit. 
- **Grids:** Court availability grids and AI analysis cards should use the `md` (16px) gutter to maintain a tight, technical feel.
- **Margins:** Page-level containers must adhere to the `margin-mobile` or `margin-desktop` tokens to ensure content never hits the edge of the screen, preserving the "floating dashboard" aesthetic.

## Elevation & Depth

This design system avoids traditional heavy shadows in favor of **Tonal Layering and Glows**.

- **Surface Tiers:**
    - **L0 (Background):** Deep Space Navy (#0F1021).
    - **L1 (Cards/Containers):** Atmospheric Surface (#1D1E33).
    - **L2 (Popovers/Modals):** Surface Variant (#1B1A28).
- **Interactive Depth:** Primary buttons and active states use an **ambient glow** instead of a black shadow. For example, a blue button uses a 40% opacity blue shadow with a 12px blur to suggest it is "powered on."
- **Outlines:** Use 1px "Court White" or "Action Cyan" borders at 10-20% opacity to define boundaries without adding visual weight.

## Shapes

The shape language is **disciplined and architectural**. 

- **Default (8px):** Used for standard buttons and input fields to provide a modern, approachable touch.
- **Large (16px):** Used for dashboard cards and product containers to soften the high-contrast UI.
- **Pill (Full):** Reserved exclusively for status badges (e.g., "Live", "Premium", "Low Stock") and active navigation indicators.

## Components

### Buttons
- **Primary:** Solid ShuttleSync Blue, 56px height, bold white text. Includes a color-matched ambient glow on hover.
- **Ghost/Outline:** 1px Action Cyan border with transparent background for secondary actions.

### Court Availability Grid
- **Header:** Court name in `title-md` with a status pill.
- **Time Slots:** Horizontal scrolling row of cells. 
    - *Open:* Dark surface with a thin gray border.
    - *Booked:* Deep Purple (`#332A4C`) background with Action Cyan text.
    - *Maintenance:* Dark Red background with high-contrast text.

### Product Cards
- **Structure:** `surface` background, 12px rounded corners.
- **Badges:** Top-right placement, high-contrast pink/red for urgency (e.g., "LOW STOCK").
- **Pricing:** Always in `Action Cyan` to draw the eye to the transaction value.

### AI Analysis Upload
- **State - Idle:** Dashed `Action Cyan` border, pulsing center icon.
- **State - Processing:** Radial progress loader in Cyan with a "Scanning" scanline effect moving vertically across the container.
- **Telemetry Data:** Data points displayed in two-column grids using `label-caps` for titles and `headline-md` for values (e.g., "88% ACCURACY").

### Input Fields
- **Style:** Dark background, 1px subtle border. On focus, the border glows `Action Cyan` and the label (in `label-caps`) shifts color to match.