"""Build the DWAHI-ALBADIA brand guide as print HTML (A4 landscape, RTL)."""
import pathlib, sys, importlib.util

S = pathlib.Path(sys.argv[1]); OUT = pathlib.Path(sys.argv[2])
spec = importlib.util.spec_from_file_location("dad", S / "make_logo_dad.py")
sys.argv = [sys.argv[0], str(S / "brand3")]
dad = importlib.util.module_from_spec(spec); spec.loader.exec_module(dad)

INK, SUN, NIGHT, CREAM, SAND = "#231F1A", "#EC9915", "#2B386B", "#F6F1E7", "#F7F3EC"

def mk(size, fg=INK, sun=SUN, extra="", style=""):
    return f'<svg viewBox="0 0 200 200" width="{size}" height="{size}" style="{style}" aria-hidden="true">{dad.mark(fg=fg, sun=sun)}{extra}</svg>'

def lock(w, fg=INK):
    return f'''<div class="lock" style="--w:{w}px;color:{fg}">{mk(int(w*0.24), fg=fg)}<div class="lw"><span class="ar">ضواحي البادية</span><span class="en">DWAHI-ALBADIA</span></div></div>'''

def stack(fg=INK):
    return f'''<div class="stack" style="color:{fg}">{mk(120, fg=fg)}<span class="ar">ضواحي البادية</span><span class="en">DWAHI-ALBADIA</span></div>'''

def rgb(h):
    return ", ".join(str(int(h[i:i+2], 16)) for i in (1, 3, 5))

colors = [
 ("الشمس", "sun", "#EC9915", "#F2A93B", "نقطة الضاد، والزر الرئيسي، والتمييز. النص فوقه داكن دائماً."),
 ("الحبر", "ink", "#231F1A", "#F3EDE2", "الحرف في الرمز، والنصوص."),
 ("ليل البادية", "night", "#2B386B", "#34437E", "خلفية الشعار العكسي، والتذييل، والشرائط الداكنة."),
 ("الرمل", "sand-100", "#F7F3EC", "#16130F", "خلفية الصفحات."),
 ("رمل غامق", "sand-300", "#D2CBBA", "#3D372E", "الحدود والخطوط الرفيعة."),
 ("كهرماني النص", "sun-text", "#9A5B00", "#F2A93B", "الروابط والأسعار حين يكون الكهرماني نصاً."),
]
sw = "".join(f'''<div class="sw"><div class="chip" style="background:{l}"></div><div class="chip d" style="background:{d}"></div>
  <b>{n}</b><code>{t}</code><span class="hex" dir="ltr">{l}</span><span class="hex" dir="ltr">RGB {rgb(l)}</span><span class="hex" dir="ltr">Dark {d}</span><p>{u}</p></div>''' for n, t, l, d, u in colors)

status = [("ناجح", "#1E7A3A"), ("قيد التنفيذ", "#1A5FB4"), ("بانتظار العميل", "#9A5B00"), ("سلبي", "#B3261E"), ("محايد", "#6B6256")]
badges = "".join(f'<span class="badge" style="color:{c}"><i></i>{n}</span>' for n, c in status)

anat = mk(300, extra='''
  <g font-family="IBM Plex Sans Arabic, Tahoma" font-size="9" fill="#6B6256"></g>''')

dont = [
 ("لا تُدوّر الرمز", mk(90, style="transform:rotate(-20deg)")),
 ("لا تُغيّر لون النقطة", mk(90, sun="#1A5FB4")),
 ("لا تمطّ الرمز", mk(90, style="transform:scaleX(1.45)")),
 ("لا تضف ظلاً", mk(90, style="filter:drop-shadow(5px 6px 3px rgba(0,0,0,.45))")),
 ("لا تضعه على الكهرماني", f'<div style="background:{SUN};padding:6px;border-radius:4px">{mk(78)}</div>'),
 ("لا تقلب الحرف", mk(90, style="transform:scaleX(-1)")),
]
donts = "".join(f'<figure class="dn"><div class="dnb">{s}</div><figcaption><span class="x">✕</span>{t}</figcaption></figure>' for t, s in dont)

qr = []
s = 311
for y in range(21):
    for x in range(21):
        inF = (x < 8 and y < 8) or (x > 12 and y < 8) or (x < 8 and y > 12)
        s = (s * 9301 + 49297) % 233280
        if not inF and s / 233280 > 0.52:
            qr.append(f'<rect x="{x}" y="{y}" width="1" height="1"/>')
def finder(x, y):
    return f'<rect x="{x}" y="{y}" width="7" height="7"/><rect x="{x+1}" y="{y+1}" width="5" height="5" fill="#fff"/><rect x="{x+2}" y="{y+2}" width="3" height="3"/>'
qrsvg = f'<svg viewBox="0 0 21 21" width="96" height="96" fill="{INK}">{"".join(qr)}{finder(0,0)}{finder(14,0)}{finder(0,14)}</svg>'

html = f'''<!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8">
<title>دليل هوية ضواحي البادية</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Alexandria:wght@500;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600&family=IBM+Plex+Mono:wght@500&display=swap">
<style>
@page {{ size: A4 landscape; margin: 0; }}
* {{ box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }}
html, body {{ margin: 0; background: {SAND}; color: {INK}; font-family: "IBM Plex Sans Arabic", Tahoma, sans-serif; font-size: 11.5pt; line-height: 1.7; }}
.page {{ width: 297mm; height: 210mm; padding: 16mm 18mm 14mm; position: relative; overflow: hidden; page-break-after: always; background: {SAND}; }}
.page:last-child {{ page-break-after: auto; }}
.night {{ background: {NIGHT}; color: {CREAM}; }}
.folio {{ position: absolute; bottom: 8mm; left: 18mm; right: 18mm; display: flex; justify-content: space-between; font-size: 8.5pt; color: #6B6256; }}
.night .folio {{ color: #C9CFE6; }}
.folio .dot {{ display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: {SUN}; margin-inline-start: 6px; vertical-align: middle; }}
h1, h2, h3 {{ font-family: "Alexandria", "IBM Plex Sans Arabic", Tahoma, sans-serif; margin: 0; }}
h2 {{ font-weight: 800; font-size: 26pt; line-height: 1.2; }}
h2 small {{ display: block; font-size: 10pt; font-weight: 700; letter-spacing: .16em; color: #9A5B00; margin-bottom: 4mm; direction: ltr; text-align: right; }}
.night h2 small {{ color: {SUN}; }}
h3 {{ font-weight: 700; font-size: 13pt; margin-bottom: 2mm; }}
p {{ margin: 0 0 3mm; }}
.lead {{ font-size: 13pt; max-width: 150mm; }}
.muted {{ color: #6B6256; }}
code, .mono {{ font-family: "IBM Plex Mono", Consolas, monospace; font-size: 9.5pt; direction: ltr; unicode-bidi: isolate; }}
.grid2 {{ display: grid; grid-template-columns: 1fr 1fr; gap: 10mm; margin-top: 8mm; }}
.grid3 {{ display: grid; grid-template-columns: repeat(3, 1fr); gap: 7mm; margin-top: 8mm; }}
.plate {{ border-radius: 4mm; display: flex; align-items: center; justify-content: center; padding: 6mm; min-height: 46mm; }}
.plate.s {{ background: #fff; border: 1px solid #D2CBBA; }}
.plate.n {{ background: {NIGHT}; }}
.cap {{ font-size: 9pt; color: #6B6256; margin-top: 2mm; }}
.lock {{ display: flex; align-items: center; gap: calc(var(--w) * .035); }}
.lock .lw {{ display: flex; flex-direction: column; line-height: 1.5; white-space: nowrap; }}
.lock .ar {{ font-family: "Alexandria", Tahoma; font-weight: 800; font-size: calc(var(--w) * .105); }}
.lock .en {{ font-family: "Alexandria", Arial; font-weight: 800; font-size: calc(var(--w) * .042); letter-spacing: .26em; direction: ltr; text-align: right; margin-top: calc(var(--w) * .032); line-height: 1; }}
.stack {{ display: flex; flex-direction: column; align-items: center; line-height: 1.3; white-space: nowrap; }}
.stack .ar {{ font-family: "Alexandria", Tahoma; font-weight: 800; font-size: 20pt; margin-top: 1mm; line-height: 1.2; }}
.stack .en {{ font-family: "Alexandria", Arial; font-weight: 800; font-size: 9.5pt; letter-spacing: .28em; direction: ltr; margin-top: 4mm; line-height: 1; }}
/* cover */
.cover {{ display: grid; grid-template-columns: 1fr 120mm; gap: 0; padding: 0; }}
.cover .l {{ padding: 22mm 20mm; display: flex; flex-direction: column; justify-content: space-between; }}
.cover .r {{ background: {NIGHT}; display: flex; align-items: center; justify-content: center; }}
.cover h1 {{ font-weight: 800; font-size: 54pt; line-height: 1.02; }}
.cover .sub {{ font-family: "Alexandria"; font-weight: 700; font-size: 14pt; letter-spacing: .24em; direction: ltr; text-align: right; margin-top: 4mm; }}
.cover .meta {{ font-size: 10pt; color: #6B6256; }}
/* anatomy */
.anat {{ display: grid; grid-template-columns: 110mm 1fr; gap: 12mm; align-items: center; margin-top: 6mm; }}
.anat .fig {{ background: #fff; border: 1px solid #D2CBBA; border-radius: 4mm; display: flex; justify-content: center; padding: 6mm; }}
.parts {{ display: grid; gap: 5mm; }}
.part {{ display: grid; grid-template-columns: 12mm 1fr; gap: 4mm; align-items: start; }}
.part .n {{ width: 10mm; height: 10mm; border-radius: 50%; background: {INK}; color: {SAND}; font-family: "Alexandria"; font-weight: 800; display: grid; place-items: center; font-size: 11pt; }}
.part .n.sun {{ background: {SUN}; color: {INK}; }}
/* clear space */
.clear {{ position: relative; display: inline-block; padding: 14mm; border: 1px dashed #9A5B00; background: repeating-linear-gradient(45deg, rgba(236,153,21,.08) 0 4px, transparent 4px 9px); }}
.clear .inner {{ background: {SAND}; }}
.sizes {{ display: flex; align-items: flex-end; gap: 10mm; margin-top: 4mm; }}
.sizes div {{ text-align: center; font-size: 9pt; color: #6B6256; }}
/* donts */
.dgrid {{ display: grid; grid-template-columns: repeat(6, 1fr); gap: 5mm; margin-top: 8mm; }}
.dn {{ margin: 0; }}
.dnb {{ background: #fff; border: 1px solid #D2CBBA; border-radius: 3mm; height: 40mm; display: flex; align-items: center; justify-content: center; overflow: hidden; }}
.dn figcaption {{ font-size: 9pt; margin-top: 2mm; }}
.x {{ color: #B3261E; font-weight: 700; margin-inline-end: 4px; }}
/* colors */
.sws {{ display: grid; grid-template-columns: repeat(6, 1fr); gap: 5mm; margin-top: 8mm; }}
.sw {{ display: flex; flex-direction: column; gap: 1mm; }}
.chip {{ height: 34mm; border-radius: 3mm 3mm 0 0; border: 1px solid rgba(0,0,0,.08); }}
.chip.d {{ height: 10mm; border-radius: 0 0 3mm 3mm; margin-top: -1mm; }}
.sw b {{ font-family: "Alexandria"; font-size: 11pt; margin-top: 2mm; }}
.sw .hex {{ font-family: "IBM Plex Mono"; font-size: 8pt; direction: ltr; text-align: right; color: #6B6256; }}
.sw p {{ font-size: 8.8pt; line-height: 1.5; margin: 1mm 0 0; }}
.badges {{ display: flex; flex-wrap: wrap; gap: 3mm; margin-top: 3mm; }}
.badge {{ display: inline-flex; align-items: center; gap: 2mm; border: 1px solid currentColor; border-radius: 99px; padding: .5mm 3.5mm; font-size: 9.5pt; font-weight: 500; background: #fff; }}
.badge i {{ width: 2.2mm; height: 2.2mm; border-radius: 50%; background: currentColor; }}
/* type */
.type {{ display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8mm; margin-top: 8mm; }}
.spec {{ background: #fff; border: 1px solid #D2CBBA; border-radius: 4mm; padding: 6mm; min-width: 0; overflow: hidden; }}
.spec .big {{ font-size: 28pt; line-height: 1.35; }}
.spec .lbl {{ display: block; font-size: 8.5pt; color: #9A5B00; font-weight: 600; margin-bottom: 2mm; }}
.scale {{ margin-top: 7mm; display: grid; gap: 1.5mm; }}
.scale div {{ display: grid; column-gap: 8mm; grid-template-columns: 52mm 1fr; align-items: baseline; border-top: 1px solid #D2CBBA; padding-top: 1.5mm; }}
.scale code {{ color: #6B6256; white-space: nowrap; font-size: 8.5pt; }}
/* apps */
.btn {{ display: inline-block; font-weight: 600; border-radius: 1mm; padding: 2.5mm 6mm; font-size: 11pt; }}
.btn.p {{ background: {SUN}; color: {INK}; }}
.btn.s {{ border: 2px solid {INK}; }}
.tag {{ width: 100mm; background: #fff; border: 1px solid #D2CBBA; border-radius: 2mm; overflow: hidden; }}
.tag .band {{ height: 2.5mm; background: {SUN}; }}
.tag header {{ display: flex; align-items: center; gap: 3mm; padding: 3mm 4mm; border-bottom: 1px solid #D2CBBA; }}
.tag .brand {{ font-family: "Alexandria"; font-weight: 800; letter-spacing: .14em; font-size: 10pt; direction: ltr; }}
.tag .body {{ display: flex; gap: 4mm; padding: 4mm; align-items: center; }}
.tag dl {{ margin: 0; display: grid; grid-template-columns: auto 1fr; gap: .5mm 3mm; font-size: 8.5pt; }}
.tag dt {{ color: #6B6256; }} .tag dd {{ margin: 0; font-weight: 600; }}
.app {{ width: 30mm; height: 30mm; border-radius: 7mm; background: {SAND}; box-shadow: inset 0 0 0 1px #D2CBBA; display: grid; place-items: center; }}
.card {{ width: 85mm; height: 55mm; border-radius: 2mm; background: {NIGHT}; color: {CREAM}; padding: 6mm; display: flex; flex-direction: column; justify-content: space-between; }}
ul {{ margin: 0; padding-inline-start: 5mm; }} li {{ margin-bottom: 1.5mm; }}
table {{ border-collapse: collapse; width: 100%; font-size: 10pt; margin-top: 6mm; }}
td, th {{ border-bottom: 1px solid #D2CBBA; padding: 2mm 3mm; text-align: right; vertical-align: top; }}
th {{ font-family: "Alexandria"; font-weight: 700; }}
</style></head><body>

<section class="page cover">
  <div class="l">
    <div>{lock(420)}</div>
    <div>
      <h1>دليل الهوية<br>البصرية</h1>
      <div class="sub">BRAND GUIDELINES</div>
    </div>
    <div class="meta">DWAHI-ALBADIA LLC · صناعية العوهي، صحار، سلطنة عُمان · الإصدار 1.0 · نوفمبر 2026</div>
  </div>
  <div class="r">{mk(300, fg=CREAM)}</div>
</section>

<section class="page">
  <h2><small>THE IDEA</small>الضاد: حرفٌ يحفر</h2>
  <div class="anat">
    <div class="fig">{mk(330)}</div>
    <div>
      <p class="lead">الرمز هو حرف «ض»: أول حرف في اسم الشركة، والحرف الذي تُسمّى به العربية "لغة الضاد". رُسم بخط هندسي ثقيل واحد يجمع الحرف والآلة في شكل واحد.</p>
      <div class="parts">
        <div class="part"><span class="n">1</span><div><h3>الحلقة</h3><p>بيت الحفار وكابينته: جسم الآلة الثابت.</p></div></div>
        <div class="part"><span class="n">2</span><div><h3>الذيل</h3><p>ذراع البوكت وهو يغرف من الأرض، بنفس منحنى ذيل الضاد.</p></div></div>
        <div class="part"><span class="n">3</span><div><h3>الأسنان الثلاثة</h3><p>أسنان البوكت في طرف الذيل: التفصيل الذي يحوّل الحرف إلى آلة تحفر.</p></div></div>
        <div class="part"><span class="n sun">4</span><div><h3>النقطة</h3><p>شمس البادية الكهرمانية: اللون الوحيد في الرمز، وتوقيع الهوية في كل مكان.</p></div></div>
      </div>
    </div>
  </div>
  <div class="folio"><span>ضواحي البادية · دليل الهوية</span><span>الفكرة<span class="dot"></span></span></div>
</section>

<section class="page">
  <h2><small>LOGO VERSIONS</small>نسخ الشعار</h2>
  <div class="grid2">
    <div><div class="plate s" style="min-height:58mm">{lock(380)}</div><div class="cap">الشعار الأفقي <code>dwahi-lockup.svg</code>: الموقع، والفواتير، والإيصالات، والأوراق الرسمية.</div></div>
    <div><div class="plate n" style="min-height:58mm">{lock(380, fg=CREAM)}</div><div class="cap">على الكحلي <code>dwahi-lockup-night.svg</code>: التذييل والخلفيات الداكنة.</div></div>
  </div>
  <div class="grid3">
    <div><div class="plate s">{stack()}</div><div class="cap">الشعار العمودي <code>dwahi-lockup-stacked.svg</code>: اللافتة والمساحات المربعة.</div></div>
    <div><div class="plate s">{mk(130)}</div><div class="cap">الرمز وحده <code>dwahi-mark.svg</code>: الختم، وملصق QR، وهيكل المعدة.</div></div>
    <div><div class="plate s"><div class="app" style="width:42mm;height:42mm;border-radius:10mm">{mk(120)}</div></div><div class="cap">أيقونة التطبيق <code>dwahi-app-icon.svg</code>: تطبيق العملاء وتطبيق الفنيين.</div></div>
  </div>
  <div class="folio"><span>ضواحي البادية · دليل الهوية</span><span>نسخ الشعار<span class="dot"></span></span></div>
</section>

<section class="page">
  <h2><small>CLEAR SPACE &amp; SIZE</small>المساحة الخالية والحجم</h2>
  <div class="grid2" style="align-items:start">
    <div>
      <div class="clear"><div class="inner">{lock(300)}</div></div>
      <p style="margin-top:5mm">اترك حول الشعار مساحة خالية تساوي <b>قطر نقطة الشمس</b> من كل جهة على الأقل. لا يدخلها نص ولا صورة ولا حافة الورقة.</p>
    </div>
    <div>
      <h3>الحد الأدنى للحجم</h3>
      <div class="sizes">
        <div>{mk(76)}<br>الرمز: 20px على الشاشة · 8mm مطبوعاً</div>
        <div>{mk(38)}<br>20px</div>
        <div>{lock(200)}<br>الأفقي: 140px · 35mm</div>
      </div>
      <h3 style="margin-top:9mm">النسخة الأحادية</h3>
      <p>على المعدن أو الختم بلون واحد: الحرف بلون واحد، والنقطة بنفس اللون أو تُترك فراغاً. لا تُحذف النقطة.</p>
      <div class="sizes"><div>{mk(76, sun=INK)}<br>أحادي</div><div style="background:{NIGHT};padding:3mm;border-radius:2mm">{mk(70, fg=CREAM, sun=CREAM)}</div></div>
    </div>
  </div>
  <div class="folio"><span>ضواحي البادية · دليل الهوية</span><span>المساحة والحجم<span class="dot"></span></span></div>
</section>

<section class="page">
  <h2><small>DON'TS</small>ما لا يجوز</h2>
  <p class="lead muted" style="margin-top:4mm">الرمز حرف عربي، وأي تشويه فيه يكسر قراءته. هذه الأخطاء الأكثر شيوعاً:</p>
  <div class="dgrid">{donts}</div>
  <div class="grid2" style="margin-top:10mm">
    <div><h3>صحيح</h3><ul><li>استخدم الملفات الأصلية SVG كما هي.</li><li>النقطة كهرمانية دائماً في النسخة الملونة.</li><li>على الخلفيات الداكنة استخدم نسخ <code>-night</code>.</li></ul></div>
    <div><h3>خطأ</h3><ul><li>إعادة كتابة الاسم بخط آخر.</li><li>وضع الشعار فوق صورة مزدحمة.</li><li>إضافة تدرجات أو تأثيرات ثلاثية الأبعاد.</li></ul></div>
  </div>
  <div class="folio"><span>ضواحي البادية · دليل الهوية</span><span>ما لا يجوز<span class="dot"></span></span></div>
</section>

<section class="page">
  <h2><small>COLOUR</small>الألوان</h2>
  <p class="lead muted" style="margin-top:4mm">ثلاثة ألوان تصنع الهوية: الشمس والحبر وليل البادية. الباقي رمليات تخدمها. الشريط الصغير تحت كل لون قيمته في الوضع الليلي.</p>
  <div class="sws">{sw}</div>
  <div class="grid2" style="margin-top:7mm">
    <div><h3>ألوان الحالات</h3><p class="muted">مع كلمة دائماً، لا يُعتمد على اللون وحده.</p><div class="badges">{badges}</div></div>
    <div><h3>قواعد</h3><ul><li>النص فوق الكهرماني داكن (<code>#231F1A</code>)، تباين 7.1:1. لا أبيض على الكهرماني.</li><li>الكهرماني كنص هو <code>#9A5B00</code> لا <code>#EC9915</code>.</li><li>لا رمادي محايد: كل الحياديات مائلة للرمل.</li></ul></div>
  </div>
  <div class="folio"><span>ضواحي البادية · دليل الهوية</span><span>الألوان<span class="dot"></span></span></div>
</section>

<section class="page">
  <h2><small>TYPOGRAPHY</small>الخطوط</h2>
  <div class="type">
    <div class="spec"><div class="lbl">الشعار والعناوين · Alexandria 800</div><div class="big" style="font-family:Alexandria;font-weight:800">ضواحي البادية</div><div style="font-family:Alexandria;font-weight:800;letter-spacing:.2em;direction:ltr;text-align:right">DWAHI-ALBADIA</div><p class="muted" style="margin-top:3mm">خط واحد للغتين، حديث وهندسي. العناوين الإنجليزية بأحرف كبيرة.</p></div>
    <div class="spec"><div class="lbl">النصوص والواجهات · IBM Plex Sans Arabic</div><div class="big" style="font-size:22pt;font-weight:600">أحضر المعدة إلى الورشة</div><p style="margin-top:2mm">وسنسلمك إيصال الاستلام بصور حالتها، ونخبرك حين تصبح جاهزة.</p></div>
    <div class="spec"><div class="lbl">الأرقام والبيانات · IBM Plex Mono</div><div class="mono" style="font-size:14pt;display:block;text-align:left">RC-2026-000311</div><div class="mono" style="font-size:14pt;display:block;text-align:left">1R-0750</div><div class="mono" style="font-size:14pt;display:block;text-align:left">1,250.500 OMR</div><p class="muted" style="margin-top:2mm">أرقام القطع والمراجع والمبالغ بثلاث خانات (البيسة). أرقام غربية.</p></div>
  </div>
  <div class="scale">
    <div><code>display-ar 56/68 · 800</code><span style="font-family:Alexandria;font-weight:800;font-size:24pt">نُعيد الآليات إلى العمل</span></div>
    <div><code>title-ar 32/44 · 700</code><span style="font-family:Alexandria;font-weight:700;font-size:17pt">ورشة صناعية العوهي</span></div>
    <div><code>h3 20/30 · 600</code><span style="font-weight:600;font-size:13pt">طلب صيانة جديد</span></div>
    <div><code>body 16/28 · 400</code><span>كل النصوص الجارية بالعربية والإنجليزية.</span></div>
  </div>
  <div class="folio"><span>ضواحي البادية · دليل الهوية</span><span>الخطوط<span class="dot"></span></span></div>
</section>

<section class="page">
  <h2><small>APPLICATIONS</small>التطبيقات</h2>
  <div class="grid3" style="align-items:start">
    <div>
      <h3>ملصق الاستلام في الورشة</h3>
      <div class="tag"><div class="band"></div><header>{mk(40)}<div><div class="brand">DWAHI-ALBADIA</div><div class="muted" style="font-size:8pt">بطاقة استلام · ورشة العوهي</div></div></header>
      <div class="body">{qrsvg}<dl><dt>الرقم</dt><dd class="mono">RC-2026-000311</dd><dt>المكوّن</dt><dd>سلندر هيدروليك</dd><dt>العميل</dt><dd>مثال</dd><dt>الاستلام</dt><dd class="mono">2026-11-16</dd></dl></div></div>
      <p class="cap">90 × 60 مم على ورق لاصق مقاوم للزيت. رمز QR هنا نمط توضيحي.</p>
    </div>
    <div>
      <h3>بطاقة العمل</h3>
      <div class="card"><div>{mk(54, fg=CREAM)}</div><div><div style="font-family:Alexandria;font-weight:800;font-size:15pt">ضواحي البادية</div><div style="font-family:Alexandria;font-weight:800;font-size:7.5pt;letter-spacing:.26em;direction:ltr;text-align:right">DWAHI-ALBADIA LLC</div><div class="mono" style="font-size:8pt;opacity:.85;margin-top:2mm;display:block;text-align:right">+968 9553 4404 · Sohar</div></div></div>
      <h3 style="margin-top:7mm">الأزرار والشارات</h3>
      <div style="display:flex;gap:3mm;flex-wrap:wrap"><span class="btn p">اطلب صيانة</span><span class="btn s">اطلب عرض سعر</span></div>
      <div class="badges"><span class="badge" style="color:#1E7A3A"><i></i>جاهز للاستلام</span><span class="badge" style="color:#1A5FB4"><i></i>جارٍ العمل</span></div>
    </div>
    <div>
      <h3>أيقونة التطبيق</h3>
      <div style="display:flex;gap:5mm;align-items:center"><div class="app">{mk(84)}</div><div class="app" style="width:18mm;height:18mm;border-radius:4.5mm">{mk(50)}</div><div class="app" style="width:11mm;height:11mm;border-radius:3mm">{mk(30)}</div></div>
      <h3 style="margin-top:7mm">على الآلة</h3>
      <div style="background:#F2B21B;border-radius:3mm;padding:7mm;display:flex;align-items:center;justify-content:center;gap:4mm"><div style="background:{INK};border-radius:2mm;padding:3mm">{mk(60, fg=CREAM)}</div><span style="font-family:Alexandria;font-weight:800;font-size:10pt;letter-spacing:.16em;direction:ltr;white-space:nowrap">DWAHI-ALBADIA</span></div>
      <p class="cap">على هيكل أصفر يوضع الرمز في مربع حبري، لأن الحرف الداكن مباشرة على الأصفر يضعف.</p>
    </div>
  </div>
  <div class="folio"><span>ضواحي البادية · دليل الهوية</span><span>التطبيقات<span class="dot"></span></span></div>
</section>

<section class="page">
  <h2><small>VOICE · IMAGERY · FILES</small>الأسلوب والصور والملفات</h2>
  <div class="grid3" style="align-items:start">
    <div><h3>الأسلوب في الكتابة</h3><ul><li>عربي واضح بلهجة مهنية قريبة: "أحضر المعدة إلى الورشة".</li><li>نخاطب العميل بـ"أنت"، ونتكلم عن الورشة بـ"نحن".</li><li>أرقام حقيقية: ضغط الاختبار بالبار، الساعات، الموديل.</li><li>لا رموز تعبيرية في الواجهات الرسمية.</li></ul></div>
    <div><h3>الصور</h3><ul><li>صور حقيقية من الورشة: قبل وبعد، محركات مفتوحة، سلندرات على منصة الاختبار، آليات على اللوبد.</li><li>إضاءة النهار وألوان المعدات كما هي، بلا فلاتر.</li><li>4:3 للمعدات، و1:1 للقطع، و16:9 لأعمال الورشة.</li></ul></div>
    <div><h3>الزخرفة</h3><ul><li><b>نقطة الشمس</b>: علامة العنصر النشط ومؤشر "جديد".</li><li><b>منحنى البوكت</b>: فاصل بين قسمين كبيرين، مرة في الصفحة.</li><li>زوايا شبه مستقيمة: 3 مم للأزرار، 6 مم للبطاقات.</li></ul></div>
  </div>
  <table>
    <tr><th>الملف</th><th>الاستخدام</th></tr>
    <tr><td><code>dwahi-lockup.svg</code> · <code>dwahi-lockup-night.svg</code></td><td>الشعار الأفقي على الفاتح وعلى الكحلي</td></tr>
    <tr><td><code>dwahi-lockup-stacked.svg</code></td><td>الشعار العمودي</td></tr>
    <tr><td><code>dwahi-mark.svg</code> · <code>dwahi-mark-night.svg</code></td><td>الرمز وحده</td></tr>
    <tr><td><code>dwahi-app-icon.svg</code></td><td>أيقونة التطبيقات</td></tr>
  </table>
  <p class="muted" style="margin-top:5mm;font-size:9.5pt">الملفات في مجلد <code>company_content/brand</code>. الاسم داخلها مكتوب بخط Alexandria، فيحوّله المصمم إلى مسارات (Outlines) قبل الطباعة على اللافتات والمعادن. الشعار بانتظار اعتماد الشركة.</p>
  <div class="folio"><span>ضواحي البادية · دليل الهوية</span><span>الملفات<span class="dot"></span></span></div>
</section>

</body></html>'''
OUT.write_text(html, encoding="utf-8")
print("ok", OUT)
