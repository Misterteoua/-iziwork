{{-- Iziwork theme — single source of truth for the brand palette.
     Palette extracted from the official logo:
       • brand blue ramp : #033299 → #0347f5 → #0367f9 (icon gradient)
       • pale blue tint  : #d9e7fa
       • ink navy        : #18222c (wordmark, approximated by the slate scale) --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = {
        theme: {
            extend: {
                fontFamily: {
                    sans: ['Inter', 'system-ui', 'sans-serif'],
                },
                colors: {
                    brand: {
                        50: '#eef6ff',
                        100: '#d9e7fa',
                        200: '#bcd7f7',
                        300: '#8fbbf4',
                        400: '#4c98f8',
                        500: '#0367f9',
                        600: '#0347f5',
                        700: '#033299',
                        800: '#0a2a70',
                        900: '#0d2450',
                    },
                },
                boxShadow: {
                    'card': '0 1px 2px 0 rgb(0 0 0 / 0.03), 0 1px 3px 0 rgb(0 0 0 / 0.04)',
                    'card-hover': '0 4px 6px -1px rgb(0 0 0 / 0.05), 0 2px 4px -2px rgb(0 0 0 / 0.04)',
                    'modal': '0 10px 15px -3px rgb(0 0 0 / 0.08), 0 4px 6px -4px rgb(0 0 0 / 0.05)',
                },
            }
        }
    }
</script>
<style>
    body {
        font-family: 'Inter', system-ui, sans-serif;
        -webkit-font-smoothing: antialiased;
    }

    /* Logo gradient (deep → primary blue). */
    .gradient-bg {
        background: linear-gradient(135deg, #033299 0%, #0347f5 55%, #0367f9 100%);
    }

    .gradient-text {
        background: linear-gradient(135deg, #0347f5 0%, #0367f9 100%);
        -webkit-background-clip: text;
        background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    /* Reduced motion */
    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after {
            animation-duration: 0.01ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.01ms !important;
            scroll-behavior: auto !important;
        }
    }

    /* Focus visible ring for all interactive elements */
    *:focus-visible {
        outline: 2px solid #0367f9;
        outline-offset: 2px;
        border-radius: 4px;
    }

    /* Tap highlight */
    a, button, input, select, textarea {
        -webkit-tap-highlight-color: rgba(3, 103, 249, 0.12);
    }

    /* Touch action for interactive elements */
    button, a, input[type="submit"] {
        touch-action: manipulation;
    }

    /* Safe area insets for notched devices */
    .safe-top { padding-top: env(safe-area-inset-top); }
    .safe-bottom { padding-bottom: env(safe-area-inset-bottom); }
    .safe-left { padding-left: env(safe-area-inset-left); }
    .safe-right { padding-right: env(safe-area-inset-right); }

    /* Custom scrollbar */
    ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }
    ::-webkit-scrollbar-track {
        background: transparent;
    }
    ::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 4px;
    }
    ::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }
</style>
