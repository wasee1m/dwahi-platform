import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Container, PageHeader, Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getDictionary, type Dictionary } from "@/i18n/get-dictionary";
import { listBranches } from "@/lib/api";
import { formatPhone, telLink, whatsappLink } from "@/lib/format";
import { alternates } from "@/lib/seo";

// W-08 الفروع (FR-CMS-01، US-05). الخريطة التفاعلية تُضاف مع مزود الخرائط (FR-INT-03)، والآن رابط الاتجاهات.
const DAYS = ["sun", "mon", "tue", "wed", "thu", "fri", "sat"] as const;

export async function generateMetadata({ params }: PageProps<"/[lang]/branches">): Promise<Metadata> {
  const { lang } = await params;
  if (!hasLocale(lang)) return {};
  const dict = await getDictionary(lang);
  return { title: dict.branches.title, alternates: alternates(lang, "/branches") };
}

export default async function BranchesPage({ params }: PageProps<"/[lang]/branches">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const dict = await getDictionary(lang);
  return (
    <>
      <PageHeader title={dict.branches.title} />
      <Container className="py-10">
        <Suspense fallback={<Skeleton className="h-72" />}>
          <Branches lang={lang} dict={dict} />
        </Suspense>
      </Container>
    </>
  );
}

async function Branches({ lang, dict }: { lang: Locale; dict: Dictionary }) {
  const branches = await listBranches(lang);
  const t = dict.branches;
  return (
    <div className="grid gap-6 md:grid-cols-2">
      {branches.map((b) => (
        <article key={b.code} className="rounded-md border border-sand-300 bg-sand-0 p-6">
          <h2 className="text-xl">{b.name}</h2>
          {b.address && <p className="mt-2 text-ink-muted">{b.address}</p>}

          <h3 className="mt-5 text-sm">{t.hours}</h3>
          <table className="mt-2 w-full text-sm">
            <tbody>
              {DAYS.map((d) => {
                const h = b.working_hours[d];
                return (
                  <tr key={d} className="border-b border-sand-200 last:border-0">
                    <th scope="row" className="py-1.5 text-start font-normal">{t.days[d]}</th>
                    <td className="py-1.5 text-end">{h ? <span className="num">{h.open} – {h.close}</span> : <span className="text-ink-muted">{t.closed}</span>}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>

          <ul className="mt-5 space-y-2 text-sm">
            {b.phone && <li>{t.phone}: <a href={telLink(b.phone)} className="num font-semibold text-sun-text">{formatPhone(b.phone)}</a></li>}
            {b.whatsapp_sales && <li><a href={whatsappLink(b.whatsapp_sales)} rel="noopener" target="_blank" className="font-semibold text-sun-text hover:underline">{t.whatsappSales}</a></li>}
            {b.whatsapp_service && <li><a href={whatsappLink(b.whatsapp_service)} rel="noopener" target="_blank" className="font-semibold text-sun-text hover:underline">{t.whatsappService}</a></li>}
            {b.whatsapp_parts && <li><a href={whatsappLink(b.whatsapp_parts)} rel="noopener" target="_blank" className="font-semibold text-sun-text hover:underline">{t.whatsappParts}</a></li>}
            {b.email && <li><a href={`mailto:${b.email}`} className="hover:underline">{b.email}</a></li>}
          </ul>
          <a
            href={`https://www.google.com/maps/dir/?api=1&destination=${b.latitude},${b.longitude}`}
            rel="noopener" target="_blank"
            className="mt-5 inline-flex rounded-sm bg-sun px-5 py-2.5 font-bold text-on-sun hover:bg-sun-deep"
          >
            {t.directions}
          </a>
        </article>
      ))}
    </div>
  );
}
