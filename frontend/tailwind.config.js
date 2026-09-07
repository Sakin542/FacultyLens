/** @type {import('tailwindcss').Config} */
export default {
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
      },
      fontFamily: {
        sans: [
          '"Plus Jakarta Sans"',
          'Inter',
          '-apple-system',
          'BlinkMacSystemFont',
          '"Segoe UI"',
          'Roboto',
          'sans-serif',
        ],
        mono: [
          '"JetBrains Mono"',
          'Fira Code',
          'Menlo',
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
