import re, sys, pathlib, collections

D = pathlib.Path(sys.argv[1])
write = len(sys.argv) > 2 and sys.argv[2] == "--write"
F = {p.name: p.read_text(encoding="utf-8") for p in sorted(D.glob("0*.md"))}
SRS, WF, DM, ARCH, US, WEB, MOB = (F[k] for k in [
    "01_srs.md", "02_workflows_states.md", "03_data_model.md", "04_architecture.md",
    "05_user_stories.md", "06_web_screens.md", "07_mobile_screens.md"])
README = F["00_README.md"]

def rows(text, pat):
    return [l for l in text.splitlines() if re.match(r"^\| " + pat + r" \|", l)]

def cells(line):
    return [c.strip() for c in line.strip().strip("|").split("|")]

# ---------- definitions ----------
FR = {}
for l in rows(SRS, r"FR-[A-Z]+-\d\d"):
    c = cells(l)
    FR[c[0]] = dict(title=c[1], prio=c[3], phase=c[4])
defined = collections.defaultdict(set)
defined["FR"] = set(FR)
defined["BR"] = {cells(l)[0] for l in rows(SRS, r"BR-\d\d")}
defined["NFR"] = {cells(l)[0] for l in rows(SRS, r"NFR-\d\d")}
defined["N"] = {cells(l)[0] for l in rows(SRS, r"N-\d\d")}
defined["Q"] = {cells(l)[0] for l in rows(README, r"Q-\d\d")}
defined["J"] = {cells(l)[0] for l in rows(WF, r"J-\d\d")}
defined["SM"] = set(re.findall(r"^#+ (SM-\d[ab]?)\b", WF, re.M))
defined["SM"] |= {s[:-1] for s in defined["SM"] if s[-1] in "ab"}
defined["ADR"] = set(re.findall(r"^### (ADR-\d\d):", ARCH, re.M))
STORIES = {}
for m in re.finditer(r"^### (US-\d\d): (.+)\n\*\*(\w+) · (P\d)\*\* \| \*\*FR:\*\* (.+?) \| \*\*الشاشات:\*\* (.+)$", US, re.M):
    STORIES[m[1]] = dict(title=m[2], prio=m[3], phase=m[4],
                         fr=re.findall(r"FR-[A-Z]+-\d\d", m[5]),
                         screens=re.findall(r"[WCAMT]-\d\d", m[6]))
defined["US"] = set(STORIES)
SCREENS = {}
for src in (WEB, MOB):
    for l in rows(src, r"[WCAMT]-\d\d"):
        c = cells(l)
        SCREENS[c[0]] = dict(title=c[1], fr=set(re.findall(r"FR-[A-Z]+-\d\d", l)),
                             us=set(re.findall(r"US-\d\d", l)), phase=c[-1])
defined["SCR"] = set(SCREENS)

problems = []

# ---------- 1. every reference resolves ----------
pats = {"FR": r"FR-[A-Z]+-\d\d", "BR": r"BR-\d\d", "NFR": r"NFR-\d\d", "N": r"(?<![A-Z-])N-\d\d",
        "Q": r"(?<![A-Z-])Q-\d\d", "J": r"J-\d\d", "SM": r"SM-\d[ab]?", "ADR": r"ADR-\d\d", "US": r"US-\d\d",
        "SCR": r"(?<![A-Z-])[WCAMT]-\d\d"}
for name, text in F.items():
    if name.startswith("08_"):
        continue
    for kind, p in pats.items():
        for ref in set(re.findall(p, text)):
            if ref not in defined[kind]:
                problems.append(f"{name}: undefined {kind} reference {ref}")

# ---------- 2. stories <-> screens agree ----------
for us, s in STORIES.items():
    if len(s["fr"]) == 0:
        problems.append(f"{us}: no FR")
    for scr in s["screens"]:
        if scr in SCREENS and us not in SCREENS[scr]["us"]:
            problems.append(f"{us} lists {scr}, but {scr} does not list {us}")
    primary = FR.get(s["fr"][0])
    if primary and (primary["prio"], primary["phase"]) != (s["prio"], s["phase"]):
        problems.append(f"{us}: {s['prio']}/{s['phase']} but primary {s['fr'][0]} is "
                        f"{primary['prio']}/{primary['phase']}")
for scr, s in SCREENS.items():
    for us in s["us"]:
        if us in STORIES and scr not in STORIES[us]["screens"]:
            problems.append(f"{scr} lists {us}, but {us} does not list {scr}")

# ---------- 3. FR coverage ----------
fr_us = collections.defaultdict(list)
for us, s in STORIES.items():
    for f in s["fr"]:
        fr_us[f].append(us)
fr_scr = collections.defaultdict(list)
for scr, s in SCREENS.items():
    for f in s["fr"]:
        fr_scr[f].append(scr)
TECHNICAL = {"FR-CMS-06": "يُتحقق بـ NFR-22", "FR-INT-01": "تكامل P3 (ADR-04)",
             "FR-INT-02": "تقني، عبر US-06", "FR-INT-03": "تقني، عبر C-04 و W-08",
             "FR-INT-04": "تكامل P2 (ADR-08)", "FR-INT-05": "تكامل P2 (Q-14)"}
for f, meta in FR.items():
    if not fr_scr[f] and not f.startswith("FR-INT") and f not in TECHNICAL:
        problems.append(f"{f}: no screen")
    if not fr_us[f] and meta["phase"] == "P1" and f not in TECHNICAL:
        problems.append(f"{f}: P1 requirement without a user story")

# ---------- 4. status values in data model have a colour ----------
colour_rows = SRS.split("### 9.2", 1)[1].split("\n---\n", 1)[0]
coloured = set(re.findall(r"[a-z_]+", " ".join(cells(l)[2] for l in colour_rows.splitlines()
                                              if l.startswith("| ") and "`#" in l)))
internal_tables = {"notification_deliveries", "attachments", "payment_webhook_events"}
for chunk in DM.split("CREATE TABLE ")[1:]:
    table = re.match(r"\w+", chunk)[0]
    if table in internal_tables:
        continue
    for col, vals in re.findall(r"\b(status|verification_status)\s+(?:TEXT|VARCHAR\(\d+\))[^\n]*?\n?[^\n]*?CHECK \(\w+ IN\s*\(([^)]*)\)", chunk):
        for v in re.findall(r"'(\w+)'", vals):
            if v not in coloured:
                problems.append(f"03 {table}.{col}: status '{v}' has no colour in 01 §9.2")

# ---------- 5. state names in 02 tables exist in 03 ----------
dm_states = set(re.findall(r"'([a-z_]+)'", DM))
for st in set(re.findall(r"\| (?:[\w ,]+) \| ([a-z_]+) \|", WF)):
    if st not in dm_states and st not in {"(حذف)"}:
        problems.append(f"02 transition target '{st}' not present in 03 CHECK values")

print(f"FR={len(FR)} US={len(STORIES)} screens={len(SCREENS)} "
      f"BR={len(defined['BR'])} NFR={len(defined['NFR'])} N={len(defined['N'])}")
print("\n".join(sorted(set(problems))) or "NO PROBLEMS")

# ---------- write 08 ----------
if write:
    mods = collections.OrderedDict()
    for f in FR:
        mods.setdefault(f.split("-")[1], []).append(f)
    out = ["# 🔗 مصفوفة الربط (Traceability)",
           "## منصة ضواحي البادية للمعدات الثقيلة", "",
           "**الإصدار:** 1.0 (مسودة)", "**التاريخ:** 2026-11-15",
           "**ملاحظة:** هذا الملف **مولَّد** من الوثائق 01 و 05 و 06 و 07، فلا يُعدَّل يدوياً. "
           "عند تعديل متطلب أو قصة أو شاشة يُعاد توليده بسكربت الربط، والسكربت نفسه يفحص الاتساق (المعرفات غير المعرّفة، الربط في الاتجاهين، الأولويات، ألوان الحالات).", "",
           "---", "",
           "## 1. المتطلبات ← القصص ← الشاشات", ""]
    for mod, frs in mods.items():
        out += [f"### {mod}", "", "| المتطلب | العنوان | الأولوية | المرحلة | القصص | الشاشات |",
                "|---------|---------|----------|---------|-------|---------|"]
        for f in frs:
            m = FR[f]
            usl = "، ".join(fr_us[f]) or ("–" if f not in TECHNICAL else f"– ({TECHNICAL[f]})")
            if not fr_us[f] and f not in TECHNICAL and m["phase"] != "P1":
                usl = f"– (تُكتب في تخطيط {m['phase']})"
            scl = "، ".join(sorted(fr_scr[f])) or "–"
            out.append(f"| {f} | {m['title']} | {m['prio']} | {m['phase']} | {usl} | {scl} |")
        out.append("")
    out += ["---", "", "## 2. الشاشات بدون متطلب مباشر", "",
            "شاشات إطار أو تنقل لا تحقق متطلباً بعينها:", ""]
    for scr, s in SCREENS.items():
        if not s["fr"]:
            out.append(f"- **{scr}** {s['title']}")
    total_p1 = sum(1 for m in FR.values() if m["phase"] == "P1")
    covered = sum(1 for f, m in FR.items() if m["phase"] == "P1" and (fr_us[f] or f in TECHNICAL))
    out += ["", "---", "", "## 3. التغطية", "",
            "| المؤشر | القيمة |", "|--------|--------|",
            f"| المتطلبات الوظيفية | {len(FR)} (" + "، ".join(
                f"{p}: {sum(1 for m in FR.values() if m['phase'] == p)}"
                for p in sorted({m['phase'] for m in FR.values()})) + ") |",
            f"| متطلبات P1 مغطاة بقصة أو مصنفة تقنية | {covered} من {total_p1} |",
            f"| قصص المستخدمين | {len(STORIES)} |",
            f"| الشاشات (ويب + تطبيقات) | {len(SCREENS)} |",
            f"| مشاكل الاتساق عند التوليد | {len(set(problems))} |", ""]
    (D / "08_traceability.md").write_text("\n".join(out), encoding="utf-8")
    print("wrote 08_traceability.md")
