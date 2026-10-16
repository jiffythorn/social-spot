#!/usr/bin/env python3
"""Generates the site's default icon set (the mark members will recognize).

    python3 tools/dev/make-icons.py

Why generate rather than commit hand-made art: the icon is a described shape (a
house on a rounded tile), so anyone can re-run this to get byte-identical
output, and changing the brand colour is one number rather than four exports.

Writes into theme/orgtool/images/, which is served as a static file
(`view/theme/orgtool/images/...`), and an officer can replace the whole set
with their own logo from Organization settings without touching these files.

Two shapes come out of here, and they are deliberately not the same picture:

  - `icon-<size>.png`     the mark on a rounded tile, transparent around it.
                          Purpose "any": favicons, browser tabs, iOS.
  - `icon-maskable-<size>.png`
                          the mark on a *full-bleed* square of the brand colour,
                          shrunk into the maskable safe zone. Android crops a
                          maskable icon to whatever shape its launcher wants
                          (circle, squircle, teardrop), so the background has to
                          reach every edge - a transparent corner becomes whatever
                          the phone paints behind icons - and anything outside the
                          safe-zone circle is at risk of being cut off.

The brand colour TILE is mirrored in `Branding::MASKABLE_BACKGROUND` on the PHP
side (an uploaded logo gets the same treatment at request time), so the two must
agree; tools/dev/e2e-orgsuite.py asserts the served pixels against it.
"""

from __future__ import annotations

import math
import os

from PIL import Image, ImageDraw

OUT_DIR = os.path.join(
    os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))),
    "theme",
    "orgtool",
    "images",
)

# Brand tile colour. One number: change it here and re-run.
TILE = (15, 118, 90)        # deep green - reads as "home / community", and is
                            # deliberately not Friendica's teal so the icon is
                            # recognizable as *this* site, not the network.
INK = (255, 255, 255)       # the house

# Sizes we ship: PWA manifest (192, 512), iOS home screen (180),
# browser tab favicon (48, 32), and a generous master for resizing.
SIZES = [512, 192, 180, 48, 32]

# The maskable variants Android asks for by name in `purpose: "maskable"`.
MASKABLE_SIZES = [512, 192]

# Everything worth seeing must sit inside a centred circle whose diameter is 80% of
# the image; the outer 10% at each edge may be cropped by the launcher's mask.
# https://web.dev/articles/maskable-icon
SAFE_ZONE = 0.80

# How much of that circle the mark is allowed to fill. 0.9 leaves visible breathing
# room, so a phone whose mask is tighter than the guidance still cannot shave the
# chimney off the roof.
MASKABLE_FIT = 0.90

# The master is drawn large so downscaling stays smooth.
MASTER = 1024

# The mark itself, authored on the MASTER canvas and scaled from there, so the shape
# is identical in every file that carries it.
BODY = [
    (300, 500),
    (724, 500),
    (724, 830),
    (300, 830),
]
ROOF = [
    (250, 510),
    (512, 250),
    (774, 510),
]
CHIMNEY = [(640, 300), (700, 300), (700, 430), (640, 430)]
# Door, punched back out of the tile so it reads at small sizes.
DOOR = [(452, 640), (572, 640), (572, 830), (452, 830)]
# Two windows: enough detail to look deliberate without turning to mush.
WINDOWS = [
    [(340, 560), (404, 560), (404, 624), (340, 624)],
    [(644, 560), (708, 560), (708, 624), (644, 624)],
]

# Painted in this order in both shapes: the house first, then the holes cut back in.
WHITE_SHAPES = [BODY, ROOF, CHIMNEY]
TILE_SHAPES = [DOOR] + WINDOWS


def mark_bounds() -> tuple[float, float, float, float]:
    """The mark's bounding box on the master canvas: (min_x, min_y, max_x, max_y)."""
    points = [point for poly in WHITE_SHAPES + TILE_SHAPES for point in poly]
    xs = [p[0] for p in points]
    ys = [p[1] for p in points]
    return (min(xs), min(ys), max(xs), max(ys))


def draw_icon(size: int) -> Image.Image:
    """Rounded tile with a white house, drawn at `size` pixels square."""
    s = size / MASTER
    img = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)

    # The tile. radius scaled so a 32px icon keeps the same proportions.
    radius = int(round(0.18 * size))
    draw.rounded_rectangle([0, 0, size - 1, size - 1], radius=radius, fill=TILE)

    def scale(poly):
        return [(int(round(x * s)), int(round(y * s))) for x, y in poly]

    for poly in WHITE_SHAPES:
        draw.polygon(scale(poly), fill=INK)
    for poly in TILE_SHAPES:
        draw.polygon(scale(poly), fill=TILE)

    return img


def draw_maskable(size: int) -> Image.Image:
    """Full-bleed square of the brand colour with the mark inside the safe zone.

    Sized by the mark's bounding *circle* rather than its box: the mask Android
    applies is round, so it is the corner-to-corner measurement that has to fit
    inside the 80%-of-width safe zone.
    """
    min_x, min_y, max_x, max_y = mark_bounds()
    centre_x = (min_x + max_x) / 2
    centre_y = (min_y + max_y) / 2
    half_diagonal = math.hypot(max_x - min_x, max_y - min_y) / 2
    allowed = (SAFE_ZONE * size / 2) * MASKABLE_FIT
    k = allowed / half_diagonal

    def place(poly):
        return [
            (int(round((x - centre_x) * k + size / 2)),
             int(round((y - centre_y) * k + size / 2)))
            for x, y in poly
        ]

    # Opaque, edge to edge. This is the whole point of the variant: nothing here is
    # transparent, so whatever the launcher masks away, it masks brand colour.
    img = Image.new("RGB", (size, size), TILE)
    draw = ImageDraw.Draw(img)

    for poly in WHITE_SHAPES:
        draw.polygon(place(poly), fill=INK)
    for poly in TILE_SHAPES:
        draw.polygon(place(poly), fill=TILE)

    return img


def main() -> int:
    os.makedirs(OUT_DIR, exist_ok=True)

    # Draw once at master size and downsample with a high-quality filter: that
    # beats drawing at the target size, which leaves jaggies on the roof edges.
    master = draw_icon(MASTER)

    for size in SIZES:
        icon = master.resize((size, size), Image.LANCZOS)
        path = os.path.join(OUT_DIR, f"icon-{size}.png")
        icon.save(path, "PNG", optimize=True)
        print(f"wrote {path} ({os.path.getsize(path)} bytes)")

    # The conventional iOS name; Safari and iOS look for this exact filename
    # when no apple-touch-icon link resolves, so keep one on hand.
    icon = master.resize((180, 180), Image.LANCZOS)
    path = os.path.join(OUT_DIR, "apple-touch-icon.png")
    icon.save(path, "PNG", optimize=True)
    print(f"wrote {path} ({os.path.getsize(path)} bytes)")

    # Maskable variants. Saved as RGB (no alpha channel at all), which is the
    # strongest possible statement that the background reaches every edge.
    maskable = draw_maskable(MASTER)

    for size in MASKABLE_SIZES:
        icon = maskable.resize((size, size), Image.LANCZOS)
        path = os.path.join(OUT_DIR, f"icon-maskable-{size}.png")
        icon.save(path, "PNG", optimize=True)
        print(f"wrote {path} ({os.path.getsize(path)} bytes)")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
