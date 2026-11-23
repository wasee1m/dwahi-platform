import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Conversation, type ThreadMessage } from "@/components/Conversation";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { formatMoney, formatPhone } from "@/lib/format";
import { ActionButton } from "@/components/ActionButton";
import { orderFromQuote } from "../../order-actions";
import { staffMessage, staffUpload } from "../../rfq-actions";
import { RfqButtons } from "../RfqForms";
import { specLines } from "@/lib/rfq";
import { STATUS_COLOR, type AdminRfqRow } from "../shared";

// A-05 تفاصيل الطلب: العميل، والبنود ومرفقاتها، والعروض بنسخها، والإسناد والخسارة، والمحادثة (US-32)
type Props = PageProps<"/[lang]/admin/rfqs/[id]">;
type Attachment = { id: string; name: string | null; mime_type: string; url: string | null };
type Detail = AdminRfqRow & {
  sales_order: { id: string; number: string; status: string } | null;
  notes: string | null; delivery_country: string; delivery_address_text: string | null; cancel_reason: string | null;
  items: { id: string; title: string; quantity: string; specs: Record<string, string | number> | null; attachments: Attachment[];
    listing: { slug: string; status: string } | null; part: { part_number: string; brand: string } | null }[];
  attachments: Attachment[];
  quotations: { id: string; number: string | null; version: number; status: string; total: { amount: string; currency: string }; valid_until: string | null; response_note: string | null; lpo_number: string | null }[];
  unread_messages: number; can_reopen: boolean;
  equipment: { id: string; name: string; serial_number: string | null } | null;
};

export default function AdminRfqPage({ params, searchParams }: Props) {
  return (
    <div className="max-w-5xl space-y-6">
      <Suspense fallback={<Skeleton className="h-[640px]" />}>
        <View params={params} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function View({ params, searchParams }: { params: Props["params"]; searchParams: Props["searchParams"] }) {
  const [{ lang, id }, sp] = await Promise.all([params, searchParams]);
  if (!hasLocale(lang) || !/^[0-9a-f-]{36}$/i.test(id)) notFound();
  const t = await getAppDictionary(lang);
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("quotation.send")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;

  const [res, thread, people] = await Promise.all([
    authed<Detail>("admin", lang, `/admin/rfqs/${id}`),
    authed<ThreadMessage[]>("admin", lang, `/admin/rfqs/${id}/messages`),
    authed<{ id: string; full_name: string }[]>("admin", lang, "/admin/rfqs/assignees"),
  ]);
  if (!res.ok) notFound();
  const r = res.data;
  const a = t.admin.rfqs;
  const qs = t.quotes.quoteStatuses;
  const when = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Muscat" });
  const draft = r.quotations.find((q) => q.status === "draft");
  const hasSent = r.quotations.some((q) => q.status !== "draft");
  const open = r.status === "submitted" || r.status === "in_review";

  return (
    <>
      <Link href={`/${lang}/admin/rfqs`} className="text-sm font-semibold text-ink-muted">← {a.title}</Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="num text-3xl" dir="ltr">{r.number}</h1>
          <p className="mt-1 text-ink-muted">{a.types[r.type]} · {a.sources[r.source as keyof typeof a.sources] ?? r.source} · {when.format(new Date(r.created_at))} ·{" "}
            <span className="font-semibold" style={{ color: STATUS_COLOR[r.status] }}>{a.statuses[r.status]}</span></p>
        </div>
        <div className="rounded-md border border-sand-300 bg-sand-0 p-3 text-sm">
          <p className="font-semibold"><Link href={`/${lang}/admin/customers/${r.customer.id}`} className="underline">{r.customer.name}</Link>{r.customer.organization && <span className="text-ink-muted"> · {r.customer.organization}</span>}</p>
          {r.customer.phone && <a href={`tel:${r.customer.phone}`} className="num block" dir="ltr">{formatPhone(r.customer.phone)}</a>}
          <p className="text-ink-muted">{a.assigned}: {r.assigned_to?.name ?? "—"}</p>
        </div>
      </div>
      {sp.sent && <Notice tone="success">{a.sentNotice}</Notice>}
      {r.status === "won" && <Notice tone="success">{a.won}</Notice>}
      {r.status === "won" && r.type === "parts" && (r.sales_order ? (
        <p className="text-sm">{t.admin.orders.linkedOrder}:{" "}
          <Link href={`/${lang}/admin/orders/${r.sales_order.id}`} className="num font-semibold underline" dir="ltr">{r.sales_order.number}</Link></p>
      ) : me.permissions.includes("orders.deliver") ? (
        <div className="rounded-md border border-sand-300 bg-sand-0 p-4 text-sm">
          <p>{t.admin.orders.noOrderYet}</p>
          <div className="mt-2"><ActionButton action={orderFromQuote} fields={{ lang, id: r.id }} label={t.admin.orders.createOrder} look="primary" /></div>
        </div>
      ) : null)}
      {r.status === "lost" && r.lost_reason && <Notice tone="danger">{a.lose}: {r.lost_reason}</Notice>}

      <RfqButtons lang={lang} t={a} rfq={r} assignees={people.ok ? people.data : []}
        canPrepare={open} prepareLabel={draft ? a.continueDraft : hasSent ? a.newVersion : a.prepare}
        canLose={open} canReopen={r.can_reopen} />

      <section className="rounded-md border border-sand-300 bg-sand-0 p-5">
        <h2 className="mb-3 text-xl">{a.request}</h2>
        <ol className="space-y-2">
          {r.items.map((i, n) => (
            <li key={i.id} className="border-b border-sand-200 pb-2 last:border-0">
              <div className="flex flex-wrap justify-between gap-2">
                <p><span className="num me-2 text-ink-muted">{n + 1}.</span><span className="font-semibold">{i.title}</span>
                  {i.listing && <span className="ms-2 text-xs text-ink-muted">({t.admin.equipment.statuses[i.listing.status as keyof typeof t.admin.equipment.statuses] ?? i.listing.status})</span>}</p>
                <span className="num font-semibold">× {Number(i.quantity)}</span>
              </div>
              {i.specs && <p className="text-xs text-ink-muted">{a.specs}: {specLines(i.specs, t.quotes.form)}</p>}
              {i.attachments.map((f) => f.url && <a key={f.id} href={f.url} target="_blank" rel="noreferrer" className="me-3 text-sm underline">📷 {f.name}</a>)}
            </li>
          ))}
        </ol>
        {r.equipment && (
          <p className="mt-3 text-sm"><span className="font-semibold">{t.quotes.form.equipment}:</span> {r.equipment.name}
            {r.equipment.serial_number && <> · <span className="num" dir="ltr">{r.equipment.serial_number}</span></>}</p>
        )}
        {r.notes && <p className="mt-3 whitespace-pre-line text-sm"><span className="font-semibold">{a.notes}:</span> {r.notes}</p>}
        <p className="mt-2 text-sm text-ink-muted">{a.delivery}: {r.delivery_address_text ?? "—"}{r.delivery_country !== "OM" && <> · {a.export} <span className="num">{r.delivery_country}</span></>}</p>
      </section>

      {r.quotations.length > 0 && (
        <section className="rounded-md border border-sand-300 bg-sand-0 p-5">
          <h2 className="mb-3 text-xl">{a.quotations}</h2>
          <ul className="divide-y divide-sand-300 text-sm">
            {r.quotations.map((q) => (
              <li key={q.id} className="flex flex-wrap items-center justify-between gap-3 py-2">
                <span><span className="num font-semibold" dir="ltr">{q.number ?? `v${q.version}`}</span> · {qs[q.status as keyof typeof qs] ?? q.status}
                  {q.valid_until && <span className="text-ink-muted"> · {t.quotes.validUntil} <span className="num">{q.valid_until}</span></span>}
                  {q.response_note && <span className="block text-ink-muted">“{q.response_note}”</span>}
                  {q.lpo_number && <span className="block text-ink-muted">LPO <span className="num" dir="ltr">{q.lpo_number}</span></span>}</span>
                <span className="flex items-center gap-3">
                  <span className="num font-semibold">{formatMoney(q.total, lang)}</span>
                  <Link href={`/${lang}/admin/quotations/${q.id}`} className="rounded-sm border border-ink px-3 py-1 font-semibold">{a.edit}</Link>
                </span>
              </li>
            ))}
          </ul>
        </section>
      )}

      <section id="messages" className="rounded-md border border-sand-300 bg-sand-0 p-5">
        <h2 className="mb-3 text-xl">{a.messages}</h2>
        <Conversation lang={lang} staffView initial={thread.ok ? thread.data : []} upload={staffUpload} send={staffMessage.bind(null, lang, r.id)}
          labels={{ placeholder: a.messagePlaceholder, send: a.send, attach: a.attach, empty: a.noMessages, team: "" }} />
      </section>
    </>
  );
}
