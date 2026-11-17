"""DWAHI-ALBADIA LLC logo — 'الضاد' (Dad).

The Arabic letter ض, the company's initial and the letter Arabic is named after, drawn as one heavy
geometric stroke: the loop is the excavator's house, the tail is a bucket digging (three teeth at its tip),
the dot is the amber sun of the badia.

Usage: python make_logo_dad.py <output folder>
"""
import pathlib, sys

out = pathlib.Path(sys.argv[1]); out.mkdir(parents=True, exist_ok=True)
SUN, INK, CREAM, NIGHT, SAND = "#EC9915", "#231F1A", "#F6F1E7", "#2B386B", "#F7F3EC"
DISPLAY = "'Alexandria', 'IBM Plex Sans Arabic', Tahoma, sans-serif"

def mark(fg=INK, sun=SUN):
    return f'''<g>
    <g fill="none" stroke="{fg}" stroke-width="24" stroke-linejoin="round">
      <rect x="100" y="76" width="68" height="46" rx="23"/>
      <path d="M123,122 H94 C94,180 32,180 32,118"/>
    </g>
    <path d="M20,119 L24,106 L28,119 Z M28,119 L32,106 L36,119 Z M36,119 L40,106 L44,119 Z" fill="{fg}"/>
    <circle cx="134" cy="40" r="17" fill="{sun}"/>
  </g>'''

def svg(w, h, body, bg=None, rx=0):
    b = f'<rect width="{w}" height="{h}" rx="{rx}" fill="{bg}"/>' if bg else ""
    return f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w} {h}" width="{w}" height="{h}">{b}{body}\n</svg>\n'

def lockup(fg, bg=None):
    # RTL reading order: the mark first (right), then the name.
    body = f'<g transform="translate(530,10) scale(0.9)">{mark(fg=fg)}</g>'
    body += f'''
  <text x="512" y="102" direction="rtl" text-anchor="start" font-family="{DISPLAY}" font-weight="800" font-size="66" fill="{fg}">ضواحي البادية</text>
  <text x="512" y="156" text-anchor="end" font-family="{DISPLAY}" font-weight="800" font-size="27" letter-spacing="7" fill="{fg}">DWAHI-ALBADIA</text>'''
    return svg(720, 200, body, bg=bg)

def stacked(fg, bg=None):
    body = f'<g transform="translate(110,6)">{mark(fg=fg)}</g>'
    body += f'''
  <text x="210" y="262" direction="rtl" text-anchor="middle" font-family="{DISPLAY}" font-weight="800" font-size="58" fill="{fg}">ضواحي البادية</text>
  <text x="210" y="306" text-anchor="middle" font-family="{DISPLAY}" font-weight="800" font-size="22" letter-spacing="6" fill="{fg}">DWAHI-ALBADIA</text>'''
    return svg(420, 330, body, bg=bg)

files = {
  "dwahi-mark.svg": svg(200, 200, mark()),
  "dwahi-mark-night.svg": svg(200, 200, mark(fg=CREAM), bg=NIGHT),
  "dwahi-lockup.svg": lockup(INK),
  "dwahi-lockup-night.svg": lockup(CREAM, bg=NIGHT),
  "dwahi-lockup-stacked.svg": stacked(INK),
  "dwahi-app-icon.svg": svg(200, 200, f'<g transform="translate(22,24) scale(0.8)">{mark()}</g>', bg=SAND, rx=44),
}
for name, text in files.items():
    (out / name).write_text(text, encoding="utf-8")
print("ok", sorted(files))
