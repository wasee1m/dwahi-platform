"""Bundle the project docs (00-08 Markdown) into one print HTML that renders Markdown and Mermaid in the
browser, then prints to PDF with Edge headless.  Usage: python make_docs_pdf.py <docs dir> <out html> <logo module>"""
import base64, json, pathlib, sys, importlib.util

DOCS = pathlib.Path(sys.argv[1]); OUT = pathlib.Path(sys.argv[2])
spec = importlib.util.spec_from_file_location("dad", sys.argv[3])
sys.argv = [sys.argv[0], str(OUT.parent / "_logo_tmp")]
dad = importlib.util.module_from_spec(spec); spec.loader.exec_module(dad)
INK, SUN, NIGHT, CREAM, SAND = "#231F1A", "#EC9915", "#2B386B", "#F6F1E7", "#F7F3EC"
def mk(size, fg=INK):
    return f'<svg viewBox="0 0 200 200" width="{size}" height="{size}" aria-hidden="true">{dad.mark(fg=fg)}</svg>'

docs = [
 ("00_README.md", "دليل الحزمة", "الفهرس، ونظام المعرّفات، والأسئلة المفتوحة، وسجل القرارات"),
 ("01_srs.md", "وثيقة المتطلبات (SRS)", "المرجع الأساسي: الأدوار، وقواعد العمل، والمتطلبات، والإشعارات، ونظام التصميم"),
 ("02_workflows_states.md", "الرحلات وآلات الحالات", "رحلات المستخدمين وجداول الانتقال والمهام المجدولة والحالات الاستثنائية"),
 ("03_data_model.md", "نموذج البيانات", "جداول PostgreSQL والقيود والفهارس والإعدادات"),
 ("04_architecture.md", "المعمارية", "القرارات المعمارية، وعقد الـ API، والأمان، والبنية التحتية، والاختبار"),
 ("05_user_stories.md", "قصص المستخدمين", "القصص وشروط القبول"),
 ("06_web_screens.md", "صفحات الويب", "الموقع العام، وبوابة العميل، ولوحة الإدارة"),
 ("07_mobile_screens.md", "شاشات التطبيقات", "تطبيق العملاء وتطبيق الفنيين"),
 ("08_traceability.md", "مصفوفة الربط", "المتطلبات ← القصص ← الشاشات (مولّدة آلياً)"),
 ("09_implementation_plan.md", "خطة التنفيذ", "الفريق، والمراحل، والسبرنتات، ومواعيد القرارات، والإطلاق"),
]
payload = [{"id": f"doc{i}", "file": f, "md": base64.b64encode((DOCS / f).read_text(encoding="utf-8").encode("utf-8")).decode()} for i, (f, _, _) in enumerate(docs)]
toc = "".join(f'<li><span class="num">{f[:2]}</span><div><b>{t}</b><span>{d}</span><code>{f}</code></div></li>' for f, t, d in docs)

html = f'''<!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8">
<title>وثائق مشروع ضواحي البادية</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Alexandria:wght@700;800&family=IBM+Plex+Sans+Arabic:wght@400;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<style>
@page {{ size: A4; margin: 15mm 14mm 16mm; }}
* {{ box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }}
html, body {{ margin: 0; background: #fff; color: {INK}; font-family: "IBM Plex Sans Arabic", Tahoma, sans-serif; font-size: 9.6pt; line-height: 1.65; }}
.cover {{ height: 266mm; display: flex; flex-direction: column; justify-content: space-between; page-break-after: always; }}
.cover .band {{ background: {NIGHT}; color: {CREAM}; border-radius: 4mm; padding: 14mm; display: flex; align-items: center; gap: 10mm; }}
.cover .band .name {{ font-family: Alexandria, Tahoma; font-weight: 800; font-size: 26pt; line-height: 1.6; }}
.cover .band .en {{ font-family: Alexandria, Arial; font-weight: 800; letter-spacing: .26em; font-size: 10pt; direction: ltr; text-align: right; margin-top: 2mm; }}
.cover h1 {{ font-family: Alexandria, Tahoma; font-weight: 800; font-size: 34pt; line-height: 1.25; margin: 0; }}
.cover .lead {{ font-size: 12pt; color: #6B6256; max-width: 140mm; margin-top: 4mm; }}
.cover .meta {{ border-top: 3px solid {SUN}; padding-top: 4mm; font-size: 9.5pt; color: #6B6256; display: flex; justify-content: space-between; }}
.toc {{ page-break-after: always; }}
.toc h2 {{ font-family: Alexandria; font-weight: 800; font-size: 20pt; margin: 0 0 6mm; }}
.toc ol {{ list-style: none; margin: 0; padding: 0; display: grid; gap: 3.5mm; }}
.toc li {{ display: grid; grid-template-columns: 14mm 1fr; gap: 4mm; align-items: start; border-bottom: 1px solid #E9E2D3; padding-bottom: 3mm; }}
.toc .num {{ font-family: Alexandria; font-weight: 800; font-size: 16pt; color: {SUN}; direction: ltr; }}
.toc b {{ display: block; font-family: Alexandria; font-size: 12pt; }}
.toc span {{ color: #6B6256; }}
.toc code {{ display: block; margin-top: 1mm; }}
.doc {{ page-break-before: always; }}
.doc-head {{ display: flex; align-items: center; justify-content: space-between; border-bottom: 3px solid {SUN}; padding-bottom: 2mm; margin-bottom: 4mm; font-size: 8.5pt; color: #6B6256; }}
.doc-head code {{ font-size: 8.5pt; }}
h1, h2, h3, h4 {{ font-family: Alexandria, Tahoma, sans-serif; line-height: 1.35; break-after: avoid; }}
.doc h1 {{ font-size: 18pt; font-weight: 800; margin: 2mm 0 2mm; }}
.doc h2 {{ font-size: 13.5pt; font-weight: 800; margin: 7mm 0 2mm; padding-top: 1mm; }}
.doc h3 {{ font-size: 11pt; font-weight: 700; margin: 5mm 0 1.5mm; }}
.doc h4 {{ font-size: 10pt; font-weight: 700; margin: 4mm 0 1mm; }}
p {{ margin: 0 0 2.2mm; }}
a {{ color: #9A5B00; text-decoration: none; }}
blockquote {{ margin: 2mm 0 3mm; padding: 2mm 4mm; background: {SAND}; border-inline-start: 3px solid {SUN}; border-radius: 1mm; color: #4A433A; }}
blockquote p:last-child {{ margin: 0; }}
hr {{ border: 0; border-top: 1px solid #E9E2D3; margin: 5mm 0; }}
ul, ol {{ margin: 0 0 2.5mm; padding-inline-start: 6mm; }}
li {{ margin-bottom: .6mm; }}
code {{ font-family: "IBM Plex Mono", Consolas, monospace; font-size: 8.4pt; background: {SAND}; padding: 0 1mm; border-radius: .8mm; direction: ltr; unicode-bidi: isolate; }}
pre {{ direction: ltr; text-align: left; background: #FBF9F5; border: 1px solid #E9E2D3; border-radius: 1.5mm; padding: 3mm; font-size: 7.6pt; line-height: 1.45; white-space: pre-wrap; word-break: break-word; margin: 2mm 0 3mm; }}
pre code {{ background: none; padding: 0; font-size: inherit; }}
table {{ width: 100%; border-collapse: collapse; margin: 2mm 0 4mm; font-size: 8pt; line-height: 1.5; break-inside: auto; }}
thead {{ display: table-header-group; }}
tr {{ break-inside: avoid; }}
th {{ background: {NIGHT}; color: {CREAM}; font-weight: 600; text-align: right; padding: 1.4mm 1.8mm; }}
td {{ border-bottom: 1px solid #E9E2D3; padding: 1.2mm 1.8mm; vertical-align: top; text-align: right; overflow-wrap: anywhere; }}
tr:nth-child(even) td {{ background: #FCFAF6; }}
td:first-child {{ white-space: nowrap; overflow-wrap: normal; }}
.mermaid {{ direction: ltr; text-align: center; margin: 3mm 0 4mm; break-inside: avoid; }}
.mermaid svg {{ max-width: 100% !important; height: auto; max-height: 200mm; }}
input[type=checkbox] {{ margin-inline-end: 1.5mm; }}
</style></head><body>

<section class="cover">
  <div class="band">{mk(110, fg=CREAM)}<div><div class="name">ضواحي البادية</div><div class="en">DWAHI-ALBADIA LLC</div></div></div>
  <div>
    <h1>وثائق مشروع المنصة الرقمية</h1>
    <p class="lead">موقع إلكتروني وتطبيق للعملاء وتطبيق لفنيي الورشة: بيع المعدات وقطع الغيار، والصيانة والإصلاح في ورشة صناعية العوهي بصحار.</p>
  </div>
  <div class="meta"><span>الإصدار 1.0 · مسودة للمراجعة</span><span>نوفمبر 2026</span><span>9 وثائق</span></div>
</section>

<section class="toc"><h2>المحتويات</h2><ol>{toc}</ol></section>

<main id="docs"></main>

<script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/mermaid@10.9.1/dist/mermaid.min.js"></script>
<script>
const DOCS = {json.dumps(payload)};
const dec = b => new TextDecoder().decode(Uint8Array.from(atob(b), c => c.charCodeAt(0)));
const root = document.getElementById('docs');
marked.setOptions({{ gfm: true }});
for (const d of DOCS) {{
  const sec = document.createElement('section'); sec.className = 'doc';
  sec.innerHTML = `<div class="doc-head"><span>ضواحي البادية · وثائق المشروع</span><code>${{d.file}}</code></div>` + marked.parse(dec(d.md));
  sec.querySelectorAll('pre > code.language-mermaid').forEach(c => {{
    const div = document.createElement('div'); div.className = 'mermaid'; div.textContent = c.textContent; c.parentElement.replaceWith(div);
  }});
  sec.querySelectorAll('pre').forEach(p => p.setAttribute('dir', 'ltr'));
  root.appendChild(sec);
}}
mermaid.initialize({{ startOnLoad: false, theme: 'base', securityLevel: 'loose',
  themeVariables: {{ fontFamily: 'IBM Plex Sans Arabic, Tahoma, sans-serif', fontSize: '13px', primaryColor: '#F7F3EC',
    primaryBorderColor: '#231F1A', primaryTextColor: '#231F1A', lineColor: '#6B6256', secondaryColor: '#FDF1DC', tertiaryColor: '#FFFFFF' }} }});
mermaid.run({{ querySelector: '.mermaid' }}).then(() => {{ document.title = 'READY'; }}).catch(e => {{ document.title = 'MERMAID-ERROR ' + e; }});
</script>
</body></html>'''
OUT.write_text(html, encoding="utf-8")
print("ok", OUT, len(html))
