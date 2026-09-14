"""Renders the FacultyLens brand mark (the boot-screen graduation-cap emblem from frontend/index.html) as a PNG for
e-mail templates, which cannot use SVG. Run from repo root:  python backend/resources/brand/render_email_logo.py"""
from __future__ import annotations

import pathlib

from PIL import Image, ImageDraw

OUT = pathlib.Path(__file__).with_name("facultylens-logo.png")
SIZE = 192  # 2x of the 96px slot used in the e-mail header
SCALE = SIZE / 48  # source viewBox is 48x48
RING = "#1E6F5C"      # sage-700 (project primary)
INK = "#1B2A27"       # sage-800 (project text)
HALO = "#E2E6E1"      # sage-200


def p(x: float, y: float) -> tuple[float, float]:
    return (x * SCALE, y * SCALE)


img = Image.new("RGBA", (SIZE * 4, SIZE * 4), (0, 0, 0, 0))
d = ImageDraw.Draw(img)
s = 4  # supersample


def P(x: float, y: float) -> tuple[float, float]:
    return (x * SCALE * s, y * SCALE * s)


w = 1.7 * SCALE * s
# soft halo + dashed outer ring (rendered as a thin solid ring for mail clients)
d.ellipse([P(3, 3), P(45, 45)], outline=HALO, width=int(1.5 * SCALE * s))
d.ellipse([P(8.5, 8.5), P(39.5, 39.5)], fill="#FFFFFF", outline=RING, width=int(1.6 * SCALE * s))
# orbit dot
d.ellipse([P(21.8, 0.8), P(26.2, 5.2)], fill=RING)
# cap top (diamond)
d.polygon([P(14, 22.5), P(24, 18), P(34, 22.5), P(24, 27)], outline=INK, fill="#FFFFFF", width=int(w))
d.line([P(14, 22.5), P(24, 18), P(34, 22.5), P(24, 27), P(14, 22.5)], fill=INK, width=int(w), joint="curve")
# cap base (U shape)
d.line([P(18.5, 24.5), P(18.5, 28.7)], fill=INK, width=int(w))
d.line([P(29.5, 24.5), P(29.5, 28.7)], fill=INK, width=int(w))
d.arc([P(18.5, 25.7), P(29.5, 31.7)], start=0, end=180, fill=INK, width=int(w))
# tassel
d.line([P(34, 22.5), P(34, 28.5)], fill=INK, width=int(w))
d.ellipse([P(33, 27.6), P(35, 29.6)], fill=INK)

img = img.resize((SIZE, SIZE), Image.LANCZOS)
img.save(OUT, optimize=True)
print(f"wrote {OUT} ({OUT.stat().st_size} bytes)")
