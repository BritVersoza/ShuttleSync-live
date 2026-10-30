<?php
// includes/tailwind_config.php
// Single source of truth for the HI-POWER Tailwind theme.
// Every page that includes this file inherits the brand palette, type scale,
// radii and shadows. Page-specific styles should only add layout rules.
?>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="/assets/css/hipower.css">
<script id="tailwind-config">
    tailwind.config = {
        darkMode: "class",
        theme: {
            extend: {
                colors: {
                    "primary": "#ff5722",
                    "primary-light": "#ff7a50",
                    "primary-dark": "#ed4817",
                    "accent": "#ed4817",
                    "accent-light": "#ff7a50",
                    "accent-dark": "#c2410c",
                    "on-primary": "#ffffff",
                    "on-accent": "#ffffff",
                    "background": "#f4f6f8",
                    "page": "#f4f6f8",
                    "surface": "#f4f6f8",
                    "surface-dim": "#eef1f4",
                    "surface-container": "#e6ebf0",
                    "surface-container-low": "#f1f4f7",
                    "surface-container-lowest": "#ffffff",
                    "surface-container-high": "#dbe2e9",
                    "surface-container-highest": "#cfd8e3",
                    "surface-bright": "#ffffff",
                    "surface-variant": "#e2e8f0",
                    "on-surface": "#14264a",
                    "on-surface-variant": "#64748b",
                    "on-background": "#14264a",
                    "outline": "#94a3b8",
                    "outline-variant": "#e2e8f0",
                    "error": "#dc2626",
                    "error-container": "#fef2f2",
                    "on-error": "#ffffff",
                    "on-error-container": "#991b1b",
                    "primary-container": "#ff5722",
                    "on-primary-container": "#ffffff",
                    "success": "#087f5b",
                    "warning": "#f59e0b",
                    "danger": "#dc2626",
                    "info": "#0284c7",
                    "dark": "#080f17",
                    "dark-2": "#101c29",
                    "navy": "#14264a",
                    "line": "#e2e8f0",
                    "brand": { "blue": "#ff5722", "red": "#ed4817", "dark": "#080f17" }
                },
                borderRadius: {
                    DEFAULT: "0.25rem",
                    "btn": "10px",
                    "card": "13px",
                    "panel": "14px",
                    "lg": "0.5rem",
                    "xl": "0.75rem",
                    "2xl": "1rem",
                    "full": "9999px"
                },
                boxShadow: {
                    "soft": "0 8px 24px rgba(15,23,42,.06)",
                    "lift": "0 12px 32px rgba(15,23,42,.10)"
                },
                spacing: {
                    "base": "8px",
                    "margin-sm": "16px",
                    "gutter": "24px",
                    "margin-md": "32px",
                    "margin-lg": "64px",
                    "container-max": "1120px"
                },
                fontFamily: {
                    sans: ["Manrope", "sans-serif"],
                    "headline-md": ["Manrope", "sans-serif"],
                    "headline-xl": ["Manrope", "sans-serif"],
                    "headline-lg": ["Manrope", "sans-serif"],
                    "headline-lg-mobile": ["Manrope", "sans-serif"],
                    "body-md": ["Manrope", "sans-serif"],
                    "body-lg": ["Manrope", "sans-serif"],
                    "label-sm": ["Manrope", "sans-serif"],
                    "label-md": ["Manrope", "sans-serif"]
                },
                fontSize: {
                    "headline-md": ["24px", { "lineHeight": "1.3", "letterSpacing": "-0.02em", "fontWeight": "700" }],
                    "headline-xl": ["48px", { "lineHeight": "1.1", "letterSpacing": "-0.04em", "fontWeight": "800" }],
                    "body-md": ["16px", { "lineHeight": "1.55", "letterSpacing": "0", "fontWeight": "400" }],
                    "headline-lg-mobile": ["24px", { "lineHeight": "1.2", "letterSpacing": "-0.02em", "fontWeight": "700" }],
                    "headline-lg": ["32px", { "lineHeight": "1.2", "letterSpacing": "-0.03em", "fontWeight": "700" }],
                    "body-lg": ["18px", { "lineHeight": "1.6", "letterSpacing": "-0.01em", "fontWeight": "400" }],
                    "label-sm": ["12px", { "lineHeight": "1", "letterSpacing": "0.05em", "fontWeight": "600" }],
                    "label-md": ["14px", { "lineHeight": "1.4", "letterSpacing": "0.01em", "fontWeight": "500" }]
                }
            }
        }
    }
</script>
<style>
    /* Base typography and surfaces. Component styles live in hipower.css. */
    html { scroll-behavior: smooth; }
    body { font-family: 'Manrope', sans-serif; background: var(--page, #f4f6f8); color: var(--navy, #14264a); }
    h1, h2, h3, h4, h5, h6 { font-family: 'Manrope', sans-serif; color: var(--navy, #14264a); }
    .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
    .glass-card { background: rgba(255, 255, 255, .7); backdrop-filter: blur(12px); border: 1px solid rgba(226, 232, 240, .8); }
    .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 4px; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #ff5722; border-radius: 4px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
    .shadow-kinetic { box-shadow: 0 8px 24px rgba(15, 23, 42, .06); }
    .radial-gauge { position: relative; width: 120px; height: 120px; border-radius: 50%; }
    .radial-gauge::before { content: ''; position: absolute; top: 12px; left: 12px; right: 12px; bottom: 12px; background: #ffffff; border-radius: 50%; z-index: 1; }
    .hero-gradient { background: linear-gradient(135deg, #080f17 0%, #101c29 55%, #ff5722 100%); }
    .btn-primary { background: #ff5722; color: #fff; transition: all .2s; }
    .btn-primary:hover { background: #ed4817; transform: translateY(-1px); box-shadow: 0 8px 25px rgba(255, 87, 34, .35); }
    .btn-accent { background: #ed4817; color: #fff; transition: all .2s; }
    .btn-accent:hover { background: #c2410c; transform: translateY(-1px); box-shadow: 0 8px 25px rgba(237, 72, 23, .35); }
    @keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-12px); } }
    .animate-float { animation: float 3s ease-in-out infinite; }
    .shuttlecock-shadow { filter: drop-shadow(0 20px 40px rgba(255, 87, 34, .3)); }
    :focus-visible { outline: 3px solid rgba(255, 87, 34, .45); outline-offset: 2px; }
</style>
