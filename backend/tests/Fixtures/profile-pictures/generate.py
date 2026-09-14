"""
Generates the synthetic profile-picture fixtures used by backend + Playwright tests.
Run once (requires Pillow):  python backend/tests/Fixtures/profile-pictures/generate.py
All fixtures are tiny, synthetic and safe; the "malicious" ones are harmless text/polyglot stubs.
"""
from __future__ import annotations

import io
import os
import struct
import zlib

from PIL import Image, ImageDraw

HERE = os.path.dirname(os.path.abspath(__file__))


def _solid(size: tuple[int, int], color: tuple[int, int, int]) -> Image.Image:
    img = Image.new("RGB", size, color)
    d = ImageDraw.Draw(img)
    # a diagonal so that the crop/resize output is visibly not a flat colour
    d.line([(0, 0), (size[0] - 1, size[1] - 1)], fill=(255, 255, 255), width=max(1, size[0] // 40))
    return img


def write(name: str, data: bytes) -> None:
    with open(os.path.join(HERE, name), "wb") as fh:
        fh.write(data)
    print(f"{name:24s} {len(data):>8d} bytes")


def encode(img: Image.Image, fmt: str, **kw) -> bytes:
    buf = io.BytesIO()
    img.save(buf, fmt, **kw)
    return buf.getvalue()


def png_solid_raw(width: int, height: int) -> bytes:
    """Hand-rolled PNG (no Pillow limits) — used for the very large-dimension fixture."""
    def chunk(tag: bytes, body: bytes) -> bytes:
        return struct.pack(">I", len(body)) + tag + body + struct.pack(">I", zlib.crc32(tag + body) & 0xFFFFFFFF)

    row = b"\x00" + b"\x80\x90\xa0" * width
    raw = zlib.compress(row * height, 9)
    ihdr = struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0)
    return b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", ihdr) + chunk(b"IDAT", raw) + chunk(b"IEND", b"")


if __name__ == "__main__":
    write("valid.jpg", encode(_solid((160, 120), (94, 125, 116)), "JPEG", quality=80))
    write("valid.png", encode(_solid((128, 128), (30, 111, 92)), "PNG", optimize=True))
    write("valid.webp", encode(_solid((140, 140), (76, 107, 98)), "WEBP", quality=80))
    write("valid-portrait.png", encode(_solid((120, 200), (42, 138, 114)), "PNG", optimize=True))
    write("tiny.png", encode(_solid((50, 50), (0, 0, 0)), "PNG"))            # below 100×100
    write("huge-dimensions.png", png_solid_raw(5001, 120))                    # exceeds 5000 px width
    write("invalid.txt", b"This is not an image. Profile pictures must be real JPG/PNG/WEBP files.\n")
    write("fake.jpg", b"<?php echo 'not an image'; ?>\n" + b"GIF89a" + b"\x00" * 64)  # wrong content, image extension
    write("svg-vector.svg", b'<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="1" height="1"/></svg>\n')
    # Valid PNG pixels with a harmless script appended after IEND (polyglot stub). Re-encoding must strip it.
    write("polyglot.png", encode(_solid((128, 128), (10, 10, 10)), "PNG") + b"\n<?php echo 'payload'; ?>\n")
