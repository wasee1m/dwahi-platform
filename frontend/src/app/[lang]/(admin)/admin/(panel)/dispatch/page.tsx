import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { SERVICE_COLOR, type ServiceRow, type WorkOrderRow } from "@/lib/service-types";
import { listSep } from "@/lib/format";

// A-10 لوحة التوزيع (FR-WO-02، US-33): صف لكل فني بمهامه كمربعات زمنية، وغير المسندة مع حالة الاستلام،
// وفلترة بالمهارة. الإسناد من صفحة أمر العمل (السحب والإفلات لاحقاً).
type Props = PageProps<"/[lang]/admin/dispatch">;
type Board = {
  from: string; days: number;
  technicians: { id: string; name: string; skills: string[];
    tasks: { id: string; number: string; type: string; status: string; role: string; service_request: string; start: string | null; end: string | null }[] }[];
  unassigned: (WorkOrderRow & { service_request: ServiceRow })[];
};
const DAY_START = 7;
const DAY_END = 19;

export default async function DispatchPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <h1 className="text-3xl">{t.admin.service.dispatch}</h1>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <BoardView lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function BoardView({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("service.triage")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;
  const sp = await searchParams;
  const from = typeof sp.from === "string" && /^\d{4}-\d{2}-\d{2}$/.test(sp.from) ? sp.from : "";
  const days = sp.view === "week" ? 7 : 1;
  const skill = typeof sp.skill === "string" ? sp.skill : "";
  const qs = new URLSearchParams({ days: String(days), ...(from ? { from } : {}), ...(skill ? { skill } : {}) });
  const res = await authed<Board>("admin", lang, `/admin/dispatch?${qs}`);
  if (!res.ok) return <Notice tone="danger">{res.error.message}</Notice>;
  const b = res.data;
  const a = t.admin.service;
  const dayStart = Date.parse(`${b.from}T00:00:00+04:00`);
  const hourWidth = 100 / (DAY_END - DAY_START);
  const fmtDay = new Intl.DateTimeFormat(intlLocale(lang), { weekday: "short", day: "numeric", month: "short", timeZone: "Asia/Muscat" });
  const fmtTime = new Intl.DateTimeFormat(intlLocale(lang), { timeStyle: "short", timeZone: "Asia/Muscat" });
  const shift = (n: number) => new Date(dayStart + n * 86_400_000 + 4 * 3_600_000).toISOString().slice(0, 10);
  const link = (o: Record<string, string>) => `?${new URLSearchParams({ from: b.from, view: days === 7 ? "week" : "day", ...(skill ? { skill } : {}), ...o })}`;
  const allSkills = [...new Set(b.technicians.flatMap((x) => x.skills))];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center gap-2">
        <Link href={link({ from: shift(-days) })} className="rounded-sm border border-ink px-3 py-1.5 text-sm">‹</Link>
        <span className="font-semibold">{fmtDay.format(new Date(dayStart))}{days === 7 && ` – ${fmtDay.format(new Date(dayStart + 6 * 86_400_000))}`}</span>
        <Link href={link({ from: shift(days) })} className="rounded-sm border border-ink px-3 py-1.5 text-sm">›</Link>
        <Link href={link({ view: days === 7 ? "day" : "week" })} className="rounded-sm bg-sand-200 px-3 py-1.5 text-sm font-semibold">{days === 7 ? a.dayView : a.weekView}</Link>
        <form method="get" className="flex items-center gap-2">
          <input type="hidden" name="from" value={b.from} />
          <input type="hidden" name="view" value={days === 7 ? "week" : "day"} />
          <input name="skill" defaultValue={skill} list="skills" placeholder={a.skillFilter} aria-label={a.skillFilter} className="rounded-sm border border-sand-300 bg-sand-0 px-3 py-1.5 text-sm" />
          <datalist id="skills">{allSkills.map((s) => <option key={s} value={s} />)}</datalist>
          <button className="rounded-sm border border-ink px-3 py-1.5 text-sm">{t.admin.orders.find}</button>
        </form>
      </div>

      <div className="overflow-x-auto rounded-md border border-sand-300 bg-sand-0">
        <div className="min-w-[860px]">
          {Array.from({ length: days }).map((_, d) => {
            const start = dayStart + d * 86_400_000;
            return (
              <div key={d} className="border-b border-sand-300 last:border-0">
                {days > 1 && <p className="bg-sand-100 px-3 py-1 text-sm font-semibold">{fmtDay.format(new Date(start))}</p>}
                <div className="flex border-b border-sand-200 text-xs text-ink-muted" dir="ltr">
                  <div className="w-40 shrink-0" />
                  <div className="relative h-5 flex-1">
                    {Array.from({ length: DAY_END - DAY_START }).map((_, h) => (
                      <span key={h} className="num absolute" style={{ left: `${h * hourWidth}%` }}>{DAY_START + h}</span>
                    ))}
                  </div>
                </div>
                {b.technicians.map((tech) => (
                  <div key={tech.id} className="flex items-stretch border-b border-sand-100 last:border-0" dir="ltr">
                    <div className="w-40 shrink-0 px-3 py-2 text-sm" dir={lang === "ar" ? "rtl" : "ltr"}>
                      <p className="font-semibold">{tech.name}</p>
                      {tech.skills.length > 0 && <p className="truncate text-xs text-ink-muted">{tech.skills.join(listSep(lang))}</p>}
                    </div>
                    <div className="relative h-14 flex-1 bg-sand-100">
                      {tech.tasks.filter((x) => x.start && Date.parse(x.start) < start + 86_400_000 && Date.parse(x.end ?? x.start) > start).map((x) => {
                        const s0 = Math.max(Date.parse(x.start!), start + DAY_START * 3_600_000);
                        const e0 = Math.min(Date.parse(x.end ?? x.start!), start + DAY_END * 3_600_000);
                        const left = ((s0 - start) / 3_600_000 - DAY_START) * hourWidth;
                        const width = Math.max(4, ((e0 - s0) / 3_600_000) * hourWidth);
                        return (
                          <Link key={x.id} href={`/${lang}/admin/work-orders/${x.id}`} title={`${x.number} · ${x.service_request}`}
                            className="absolute top-1 bottom-1 overflow-hidden rounded-sm px-1.5 text-xs text-white"
                            style={{ left: `${left}%`, width: `${width}%`, background: SERVICE_COLOR[x.status] ?? "var(--status-progress)", opacity: x.role === "lead" ? 1 : 0.7 }}>
                            <span className="num font-bold">{x.number.slice(-6)}</span> {fmtTime.format(new Date(x.start!))}
                          </Link>
                        );
                      })}
                    </div>
                  </div>
                ))}
                {b.technicians.length === 0 && <p className="p-4 text-sm text-ink-muted">{a.noTechnicians}</p>}
              </div>
            );
          })}
        </div>
      </div>

      <section>
        <h2 className="text-xl">{a.unassigned}</h2>
        {b.unassigned.length === 0 ? <p className="mt-2 text-ink-muted">{t.admin.common.none}</p> : (
          <ul className="mt-2 divide-y divide-sand-300 rounded-md border border-sand-300 bg-sand-0 text-sm">
            {b.unassigned.map((w) => (
              <li key={w.id} className="flex flex-wrap items-center justify-between gap-2 p-3">
                <span><Link href={`/${lang}/admin/work-orders/${w.id}`} className="num font-semibold underline" dir="ltr">{w.number}</Link>
                  {" · "}{t.service.woTypes[w.type]}{" · "}{w.service_request.equipment?.name ?? t.service.components[w.service_request.component_type as keyof typeof t.service.components]}
                  {" · "}{w.service_request.customer?.organization ?? w.service_request.customer?.name}</span>
                <span className={w.service_request.received ? "text-success font-semibold" : "text-ink-muted"}>{w.service_request.received ? a.receivedYes : a.notReceived}</span>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
