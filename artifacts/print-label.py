#!/usr/bin/env python3
"""
Decantalize label printer for Brother QL-820NWB
Usage: print-label.py <size> <title> <brand> [--preview]

  size:    1ml | 5ml | 10ml | Order | Bundle
  title:   product title (will be uppercased), or order number for Order size,
           or line 1 for Bundle size
  brand:   brand name, or item count for Order size, or line 2 for Bundle size
  --preview: save a PNG preview instead of printing (for testing)

Examples:
  print-label.py 5ml "Valentino Donna Born in Roma" "Valentino"
  print-label.py 1ml "Chanel No. 5" "Chanel" --preview
  print-label.py Order 1234 5
  print-label.py Bundle "Summer Sampler" "Five Florals"
"""

import sys
import os
from PIL import Image, ImageDraw, ImageFont

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

DPI           = 300
TAPE_MM       = 12
TAPE_PX       = 106                          # brother QL 12mm printable dots (hardware limit)

FONT_PATH     = os.path.join(os.path.dirname(__file__), 'FRADM.TTF')
FALLBACK_FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'
LOGO_PATH     = os.path.join(os.path.dirname(__file__), 'decantalize_logo.png')

REBOTTLED_SHORT = 'Rebottled by decantalize.com'
REBOTTLED_LONG  = 'Independently Rebottled by decantalize.com'

PRINTER       = 'tcp://10.0.0.228'        # update with your printer IP
LABEL_12MM    = '12'

# Per-size configuration
SIZE_CONFIG = {
    '1ml': {
        'margin_h': 4,
        'margin_v': 6,
        'print_h':  TAPE_PX - (6 * 2),
        'length':   int(2.00 * DPI) - int(0.25 * DPI),
        'rotation': 270,
    },
    '5ml': {
        'margin_h': 1,
        'margin_v': 1,
        'print_h':  TAPE_PX - (1 * 2),
        'length':   int(1.25 * DPI) - int(0.125 * DPI),
        'rotation': 270,
    },
    '10ml': {
        'margin_h': 1,
        'margin_v': 1,
        'print_h':  TAPE_PX - (1 * 2),
        'length':   int(3.25 * DPI) - int(0.50 * DPI),
        'rotation': 90,
    },
    'Order': {
        'margin_h': 1,
        'margin_v': 1,
        'print_h':  TAPE_PX - (1 * 2),
        'length':   None,  # dynamic — computed from text width
        'rotation': 270,
    },
    # Bundle name label — copy of 1ml so it can diverge later (different layout,
    # font, strip behavior, etc.) without touching the product 1ml path.
    'Bundle': {
        'margin_h': 4,
        'margin_v': 6,
        'print_h':  TAPE_PX - (6 * 2),
        'length':   int(2.00 * DPI) - int(0.25 * DPI),
        'rotation': 270,
    },
}

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def load_font(size):
    if os.path.exists(FONT_PATH):
        return ImageFont.truetype(FONT_PATH, size)
    if os.path.exists(FALLBACK_FONT):
        return ImageFont.truetype(FALLBACK_FONT, size)
    raise RuntimeError(f"No usable font found. Place FRADM.TTF in {os.path.dirname(FONT_PATH)}")


def text_width(draw, text, font):
    bbox = draw.textbbox((0, 0), text, font=font)
    return bbox[2] - bbox[0]


def text_height(draw, text, font):
    # Use a reference string with ascenders and descenders for consistent line height
    bbox = draw.textbbox((0, 0), 'Agpqy', font=font)
    return bbox[3] - bbox[1]


def fit_font_to_height(target_px, num_lines, line_spacing=1.15):
    """Find largest font size where num_lines fit within target_px height."""
    for size in range(60, 4, -1):
        font = load_font(size)
        dummy = Image.new('RGB', (1, 1))
        draw  = ImageDraw.Draw(dummy)
        line_h = text_height(draw, 'Ag', font)
        total  = line_h * num_lines + int(line_h * (line_spacing - 1) * (num_lines - 1))
        if total <= target_px:
            return size, font
    return 6, load_font(6)


def fit_font_to_width(draw, text, max_px, max_size):
    """Scale font down until text fits within max_px width."""
    for size in range(max_size, 4, -1):
        font = load_font(size)
        if text_width(draw, text, font) <= max_px:
            return size, font
    return 4, load_font(4)


def wrap_title(draw, title, font, max_px):
    """
    Wrap title into at most 2 lines. Returns (line1, line2).
    line2 may be empty string if title fits on one line.
    """
    if text_width(draw, title, font) <= max_px:
        return title, ''
    words = title.split()
    best  = (title, '')
    for i in range(1, len(words)):
        l1 = ' '.join(words[:i])
        l2 = ' '.join(words[i:])
        if text_width(draw, l1, font) <= max_px:
            best = (l1, l2)
    return best


def make_canvas(width_px):
    img  = Image.new('RGB', (width_px, TAPE_PX), 'white')
    draw = ImageDraw.Draw(img)
    return img, draw


def draw_text_left(draw, x, y, text, font, fill='black'):
    draw.text((x, y), text, font=font, fill=fill)


def draw_text_right(draw, right_x, y, text, font, fill='black'):
    w = text_width(draw, text, font)
    draw.text((right_x - w, y), text, font=font, fill=fill)


def paste_logo(img, right_x, y, line_h):
    """Paste logo at right_x, scaled to line_h, return logo width."""
    logo = Image.open(LOGO_PATH).convert('RGBA')
    aspect = logo.width / logo.height
    new_h  = line_h
    new_w  = int(aspect * new_h)
    logo   = logo.resize((new_w, new_h), Image.LANCZOS)
    img.paste(logo, (right_x - new_w, y), logo)
    return new_w


def calc_y_start(total_text_h, margin_v, font=None):
    """Center text block vertically within tape, accounting for bbox offset."""
    # textbbox often has a top offset (bbox[1] > 0) that shifts text down
    # compensate by subtracting that offset from y start
    top_offset = 0
    if font:
        dummy = Image.new('RGB', (1,1))
        d = ImageDraw.Draw(dummy)
        bbox = d.textbbox((0, 0), 'Ag', font=font)
        top_offset = bbox[1]
    y = (TAPE_PX - total_text_h) // 2 - top_offset
    return max(margin_v, y)


# ---------------------------------------------------------------------------
# Layout functions
# ---------------------------------------------------------------------------

def build_1ml(title, brand, cfg):
    """
    1ml — max 2" wide, no logo, no size indicator.
    Title ALL CAPS. Strategy:
      1. Fit title on one line at natural 3-line font size
      2. If too wide, scale font down to MIN_READABLE_SIZE to avoid wrapping
      3. If still too wide at min size, wrap to 2 lines + brand on L3
    No wrap: L1=title, L2=brand, L3=rebottled
    Wrap:    L1=title_part1, L2=title_part2, L3=brand
    """
    MIN_READABLE_SIZE = 14   # smallest comfortable font size at 300dpi / 12mm tape

    margin_h   = cfg['margin_h']
    margin_v   = cfg['margin_v']
    print_h    = cfg['print_h']
    width_px   = cfg['length']
    max_text_w = width_px - (margin_h * 2)

    img, draw = make_canvas(width_px)

    title_uc = title.upper()

    # Base font sized to fit 3 lines vertically
    _, font = fit_font_to_height(print_h, 3)
    line_h  = text_height(draw, 'Ag', font)
    spacing = int(line_h * 0.15)

    # --- Attempt single-line title, scaling down to MIN_READABLE_SIZE before wrapping ---
    single_line_font = None
    if text_width(draw, title_uc, font) <= max_text_w:
        single_line_font = font
    else:
        # Try scaling down until title fits or we hit the minimum readable size
        for size in range(60, MIN_READABLE_SIZE - 1, -1):
            f  = load_font(size)
            lh = text_height(draw, 'Ag', f)
            total = lh * 3 + int(lh * 0.15) * 2
            if total <= print_h and text_width(draw, title_uc, f) <= max_text_w:
                single_line_font = f
                break

    if single_line_font is not None:
        # Single-line path: also ensure brand and rebottled fit at this size
        font    = single_line_font
        line_h  = text_height(draw, 'Ag', font)
        spacing = int(line_h * 0.15)

        brand_font = font
        if text_width(draw, brand, font) > max_text_w:
            _, brand_font = fit_font_to_width(draw, brand, max_text_w, 60)

        rebottled_font = font
        if text_width(draw, REBOTTLED_SHORT, font) > max_text_w:
            _, rebottled_font = fit_font_to_width(draw, REBOTTLED_SHORT, max_text_w, 60)

        total_h = line_h * 3 + spacing * 2
        y = calc_y_start(total_h, margin_v, font)

        draw_text_left(draw, margin_h, y, title_uc, font)
        y += line_h + spacing
        draw_text_left(draw, margin_h, y, brand, brand_font)
        y += line_h + spacing
        draw_text_left(draw, margin_h, y, REBOTTLED_SHORT, rebottled_font)

    else:
        # Wrap path: title must split across 2 lines, brand on L3, no rebottled
        line1, line2 = wrap_title(draw, title_uc, font, max_text_w)

        # Scale font so both title lines and brand fit vertically and horizontally
        for size in range(60, 4, -1):
            f  = load_font(size)
            lh = text_height(draw, 'Ag', f)
            sp = int(lh * 0.15)
            total = lh * 3 + sp * 2
            if (total <= print_h
                    and text_width(draw, line1, f) <= max_text_w
                    and text_width(draw, line2, f) <= max_text_w):
                font    = f
                line_h  = lh
                spacing = sp
                break

        brand_font = font
        if text_width(draw, brand, font) > max_text_w:
            _, brand_font = fit_font_to_width(draw, brand, max_text_w, 60)

        total_h = line_h * 3 + spacing * 2
        y = calc_y_start(total_h, margin_v, font)

        draw_text_left(draw, margin_h, y, line1, font)
        y += line_h + spacing
        draw_text_left(draw, margin_h, y, line2, font)
        y += line_h + spacing
        draw_text_left(draw, margin_h, y, brand, brand_font)

    return img


def build_bundle(title, brand, cfg):
    """
    Bundle name label — currently identical to 1ml.  Kept as a separate path
    so the bundle-name layout can diverge (different strip/wrap rules, logo
    placement, etc.) without affecting product labels.  The caller supplies
    title=line1 and brand=line2, already split by the user in the UI.
    """
    MIN_READABLE_SIZE = 14

    margin_h   = cfg['margin_h']
    margin_v   = cfg['margin_v']
    print_h    = cfg['print_h']
    width_px   = cfg['length']
    max_text_w = width_px - (margin_h * 2)

    img, draw = make_canvas(width_px)

    title_uc = title.upper()

    _, font = fit_font_to_height(print_h, 3)
    line_h  = text_height(draw, 'Ag', font)
    spacing = int(line_h * 0.15)

    single_line_font = None
    if text_width(draw, title_uc, font) <= max_text_w:
        single_line_font = font
    else:
        for size in range(60, MIN_READABLE_SIZE - 1, -1):
            f  = load_font(size)
            lh = text_height(draw, 'Ag', f)
            total = lh * 3 + int(lh * 0.15) * 2
            if total <= print_h and text_width(draw, title_uc, f) <= max_text_w:
                single_line_font = f
                break

    if single_line_font is not None:
        font    = single_line_font
        line_h  = text_height(draw, 'Ag', font)
        spacing = int(line_h * 0.15)

        brand_font = font
        if text_width(draw, brand, font) > max_text_w:
            _, brand_font = fit_font_to_width(draw, brand, max_text_w, 60)

        rebottled_font = font
        if text_width(draw, REBOTTLED_SHORT, font) > max_text_w:
            _, rebottled_font = fit_font_to_width(draw, REBOTTLED_SHORT, max_text_w, 60)

        total_h = line_h * 3 + spacing * 2
        y = calc_y_start(total_h, margin_v, font)

        draw_text_left(draw, margin_h, y, title_uc, font)
        y += line_h + spacing
        draw_text_left(draw, margin_h, y, brand, brand_font)
        y += line_h + spacing
        draw_text_left(draw, margin_h, y, REBOTTLED_SHORT, rebottled_font)

    else:
        line1, line2 = wrap_title(draw, title_uc, font, max_text_w)

        for size in range(60, 4, -1):
            f  = load_font(size)
            lh = text_height(draw, 'Ag', f)
            sp = int(lh * 0.15)
            total = lh * 3 + sp * 2
            if (total <= print_h
                    and text_width(draw, line1, f) <= max_text_w
                    and text_width(draw, line2, f) <= max_text_w):
                font    = f
                line_h  = lh
                spacing = sp
                break

        brand_font = font
        if text_width(draw, brand, font) > max_text_w:
            _, brand_font = fit_font_to_width(draw, brand, max_text_w, 60)

        total_h = line_h * 3 + spacing * 2
        y = calc_y_start(total_h, margin_v, font)

        draw_text_left(draw, margin_h, y, line1, font)
        y += line_h + spacing
        draw_text_left(draw, margin_h, y, line2, font)
        y += line_h + spacing
        draw_text_left(draw, margin_h, y, brand, brand_font)

    return img


def build_5ml(title, brand, cfg):
    """
    5ml — exactly 1.25" wide, no logo.
    L1: title ALL CAPS (+ '5ml' right-aligned if brand line is too crowded)
    L2: brand (left) + '5ml' (right) — if it fits comfortably
    L3: rebottled
    """
    MIN_GAP    = 8   # minimum comfortable px gap between brand and '5ml'

    margin_h   = cfg['margin_h']
    margin_v   = cfg['margin_v']
    print_h    = cfg['print_h']
    width_px   = cfg['length']
    max_text_w = width_px - (margin_h * 2)
    right_x    = width_px - margin_h

    img, draw = make_canvas(width_px)

    title_uc = title.upper()

    # Fit font to 3 lines
    _, font = fit_font_to_height(print_h, 3)
    line_h  = text_height(draw, 'Ag', font)
    spacing = int(line_h * 0.15)

    # Scale title down if needed
    if text_width(draw, title_uc, font) > max_text_w:
        _, font = fit_font_to_width(draw, title_uc, max_text_w, 60)
        line_h  = text_height(draw, 'Ag', font)
        spacing = int(line_h * 0.15)

    # Scale rebottled line independently if needed
    rebottled_font = font
    if text_width(draw, REBOTTLED_SHORT, font) > max_text_w:
        _, rebottled_font = fit_font_to_width(draw, REBOTTLED_SHORT, max_text_w, 60)

    ml_text      = '5ml'
    ml_w         = text_width(draw, ml_text, font)
    brand_w      = text_width(draw, brand, font)
    combined_w   = brand_w + MIN_GAP + ml_w
    brand_font   = font
    ml_on_line1  = False

    if combined_w > max_text_w:
        # Can '5ml' fit on line 1 next to the title?
        title_w       = text_width(draw, title_uc, font)
        title_ml_gap  = max_text_w - title_w - ml_w
        if title_ml_gap >= MIN_GAP:
            # Enough room — put '5ml' on line 1
            ml_on_line1 = True
            if text_width(draw, brand, font) > max_text_w:
                _, brand_font = fit_font_to_width(draw, brand, max_text_w, 60)
        else:
            # Shrink brand text until MIN_GAP is satisfied
            available_brand_w = max_text_w - ml_w - MIN_GAP
            _, brand_font = fit_font_to_width(draw, brand, available_brand_w, 60)

    total_h = line_h * 3 + spacing * 2
    y = calc_y_start(total_h, margin_v, font)

    # Line 1: title (+ optional '5ml' right)
    draw_text_left(draw, margin_h, y, title_uc, font)
    if ml_on_line1:
        draw_text_right(draw, right_x, y, ml_text, font)
    y += line_h + spacing

    # Line 2: brand (+ '5ml' right if it belongs here)
    if brand_font is not font:
        # brand was shrunk — vertically center both texts within the original line_h
        brand_h  = text_height(draw, 'Ag', brand_font)
        ml_h     = text_height(draw, 'Ag', font)
        brand_y  = y + (line_h - brand_h) // 2
        ml_y     = y + (line_h - ml_h) // 2
        draw_text_left(draw,  margin_h, brand_y, brand,   brand_font)
        if not ml_on_line1:
            draw_text_right(draw, right_x,  ml_y,    ml_text, font)
    else:
        draw_text_left(draw,  margin_h, y, brand,   brand_font)
        if not ml_on_line1:
            draw_text_right(draw, right_x, y, ml_text, font)
    y += line_h + spacing

    # Line 3: rebottled
    draw_text_left(draw, margin_h, y, REBOTTLED_SHORT, rebottled_font)

    return img


def build_10ml(title, brand, cfg):
    """
    10ml — max 3.25" wide, logo on line 3.
    L1: title ALL CAPS (left) + '10ml' (right)
    L2: brand
    L3: 'Independently Rebottled by' + logo immediately after (full line height)
    """
    margin_h   = cfg['margin_h']
    margin_v   = cfg['margin_v']
    print_h    = cfg['print_h']
    width_px   = cfg['length']
    max_text_w = width_px - (margin_h * 2)
    img, draw  = make_canvas(width_px)
    title_uc   = title.upper()

    # Fit font to 3 lines
    _, font = fit_font_to_height(print_h, 3)
    line_h  = text_height(draw, 'Ag', font)
    spacing = int(line_h * 0.15)

    # Scale title down if title + '10ml' too wide
    size_label = '10ml'
    size_w     = text_width(draw, size_label, font) + margin_h
    if text_width(draw, title_uc, font) > max_text_w - size_w:
        for size in range(60, 4, -1):
            f  = load_font(size)
            sw = text_width(draw, size_label, f) + margin_h
            if text_width(draw, title_uc, f) <= max_text_w - sw:
                font    = f
                line_h  = text_height(draw, 'Ag', f)
                spacing = int(line_h * 0.15)
                break

    right_x = width_px - margin_h
    total_h = line_h * 3 + spacing * 2
    y = calc_y_start(total_h, margin_v, font) - 10

    # L1: title + size label
    draw_text_left(draw,  margin_h, y, title_uc,   font)
    draw_text_right(draw, right_x,  y, size_label, font)
    y += line_h + spacing

    # L2: brand
    draw_text_left(draw, margin_h, y, brand, font)
    y += line_h + spacing

    # L3: 'Independently Rebottled by' + logo immediately after text
    REBOTTLED_BY = 'Independently Rebottled by'

    # Load logo, crop to content bounds, scale to full line height
    logo     = Image.open(LOGO_PATH).convert('RGBA')
    _, _, _, a = logo.split()
    bbox     = a.getbbox()
    if bbox:
        logo = logo.crop(bbox)
    aspect   = logo.width / logo.height
    logo_h   = line_h + 10
    logo_w   = int(aspect * logo_h)
    logo     = logo.resize((logo_w, logo_h), Image.LANCZOS)

    gap      = 7  # small gap between text and logo

    # Scale rebottled text down if text + gap + logo exceeds available width
    rebottled_font = font
    max_text_w_l3  = max_text_w - logo_w - gap
    if text_width(draw, REBOTTLED_BY, font) > max_text_w_l3:
        _, rebottled_font = fit_font_to_width(draw, REBOTTLED_BY, max_text_w_l3, 60)

    # Draw text then paste logo immediately after
    draw_text_left(draw, margin_h, y, REBOTTLED_BY, rebottled_font)
    text_end = margin_h + text_width(draw, REBOTTLED_BY, rebottled_font) + gap
    img.paste(logo, (text_end, y), logo)

    return img


def build_order(order_number, count, cfg):
    """
    Order — single line, width fits content.
    Text: '{order_number} - {count}'
    Font sized to fill the full printable height (single line).
    """
    margin_h = cfg['margin_h']
    margin_v = cfg['margin_v']
    print_h  = cfg['print_h']

    label_text = f"{order_number} - {count}"

    # Largest font that fits a single line within print_h
    _, font = fit_font_to_height(print_h, 1, line_spacing=1.0)

    dummy = Image.new('RGB', (1, 1))
    draw  = ImageDraw.Draw(dummy)
    line_h   = text_height(draw, 'Ag', font)
    width_px = text_width(draw, label_text, font) + (margin_h * 2)

    img, draw = make_canvas(width_px)

    y = calc_y_start(line_h, margin_v, font)
    draw_text_left(draw, margin_h, y, label_text, font)

    return img


# ---------------------------------------------------------------------------
# Print / preview
# ---------------------------------------------------------------------------

def print_label(img, size):
    from brother_ql.conversion import convert
    from brother_ql.backends.helpers import send
    from brother_ql.raster import BrotherQLRaster

    # Rotate image in Pillow before sending — brother_ql ignores rotate param for narrow tape
    rotation = SIZE_CONFIG[size]['rotation']
    img = img.rotate(rotation, expand=True)

    qlr = BrotherQLRaster('QL-820NWB')
    instructions = convert(
        qlr=qlr,
        images=[img],
        label=LABEL_12MM,
        cut=True,
        rotate='0',
    )
    send(
        instructions=instructions,
        printer_identifier=PRINTER,
        backend_identifier='network',
        blocking=True,
    )
    print(f"Printed {size} label.")


def preview_label(img, size, title):
    out = f"preview_{size}_{title[:20].replace(' ','_')}.png"
    img.save(out)
    print(f"Preview saved: {out}")


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

def main():
    args    = [a for a in sys.argv[1:] if a != '--preview']
    preview = '--preview' in sys.argv

    if len(args) != 3:
        print(__doc__)
        sys.exit(1)

    size, title, brand = args

    if size not in ('1ml', '5ml', '10ml', 'Order', 'Bundle'):
        print(f"ERROR: size must be 1ml, 5ml, 10ml, Order, or Bundle (got '{size}')")
        sys.exit(1)

    builders = {
        '1ml':   build_1ml,
        '5ml':   build_5ml,
        '10ml':  build_10ml,
        'Order': build_order,
        'Bundle': build_bundle,
    }

    cfg = SIZE_CONFIG[size]
    img = builders[size](title, brand, cfg)

    if preview:
        preview_label(img, size, title)
    else:
        print_label(img, size)


if __name__ == '__main__':
    main()
