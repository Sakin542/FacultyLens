/** @type {import('tailwindcss').Config} */
export default {
  // Dark variants only apply when ThemeContext adds the `dark` class — never from the OS setting.
  darkMode: 'class',
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        theme: {
          black: "#111111",
          white: "#FFFFFF",
          "off-white": "#F7F7F5",
          "light-gray": "#E5E5E5",
          gray: "#737373",
          "dark-gray": "#262626",
        },
        semantic: {
          green: {
            DEFAULT: "#16A34A",
            light: "#F0FDF4",
            border: "#BBF7D0",
            text: "#166534",
          },
          amber: {
            DEFAULT: "#D97706",
            light: "#FFFBEB",
            border: "#FDE68A",
            text: "#92400E",
          },
          red: {
            DEFAULT: "#DC2626",
            light: "#FEF2F2",
            border: "#FECACA",
            text: "#991B1B",
          },
        },
        // Landing/app palette (cream + light-gray boxes + deep green, orange accent)
        sage: {
          50: "#FAFAF7",
          100: "#F1F2EE",
          200: "#E2E6E1",
          300: "#A9CFC3",
          // 4.9:1 on white — WCAG AA for the small helper text that uses text-sage-400
          400: "#5F7D74",
          500: "#4C6B62",
          600: "#2A8A72",
          700: "#1E6F5C",
          800: "#1B2A27",
        },
        accent: {
          DEFAULT: "#5A67D8",
          hover: "#4C56C4",
          light: "#EEF0FC",
        },
      },
      fontFamily: {
        // System font stack — no web fonts are loaded.
        sans: [
          'ui-sans-serif',
          'system-ui',
          '-apple-system',
          'BlinkMacSystemFont',
          '"Segoe UI"',
          'Roboto',
          '"Helvetica Neue"',
          'Arial',
          'sans-serif',
        ],
        // `font-serif` is kept as a heading utility but renders in the same system sans (weight set in index.css)
        serif: [
          'ui-sans-serif',
          'system-ui',
          '-apple-system',
          '"Segoe UI"',
          'Roboto',
          'Arial',
          'sans-serif',
        ],
        mono: [
          'ui-monospace',
          'SFMono-Regular',
          'Menlo',
          'Consolas',
          '"Liberation Mono"',
          'monospace',
        ],
      },
      letterSpacing: {
        tightest: '-0.04em',
        tighter: '-0.03em',
        tight: '-0.015em',
        normal: '0',
        wide: '0.025em',
        wider: '0.05em',
        widest: '0.1em',
      },
      boxShadow: {
        subtle: "0 1px 2px 0 rgba(0, 0, 0, 0.04)",
        card: "0 2px 4px -1px rgba(0, 0, 0, 0.05), 0 1px 2px -1px rgba(0, 0, 0, 0.03)",
        elevated: "0 10px 15px -3px rgba(0, 0, 0, 0.06), 0 4px 6px -4px rgba(0, 0, 0, 0.04)",
      },
      borderRadius: {
        'academic': '10px',
      }
    },
  },
  plugins: [],
}
