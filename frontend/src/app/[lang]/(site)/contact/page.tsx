import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Container, PageHeader, Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getDictionary, type Dictionary } from "@/i18n/get-dictionary";
import { listBranches } from "@/lib/api";
import { safe } from "@/lib/safe";
import { alternates } from "@/lib/seo";
import { OwnerCard } from "@/components/OwnerCard";
import { ContactForm } from "./ContactForm";

// W-09 تواصل معنا (FR-CMS-05، US-05). ?topic= و ?ref= تأتي من صفحات المعدات والقطع.
type Props = PageProps<"/[lang]/contact">;
const TOPICS = ["equipment", "parts", "service", "other"];

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { lang } = await params;
  if (!hasLocale(lang)) return {};
  const dict = await getDictionary(lang);
  return { title: dict.contact.title, alternates: alternates(lang, "/contact") };
}

export default async function ContactPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const dict = await getDictionary(lang);
  return (
    <>
      <PageHeader title={dict.contact.title}>{dict.contact.intro}</PageHeader>
      <Container className="max-w-3xl py-10">
        <Suspense fallback={<Skeleton className="mb-8 h-48" />}>
          <div className="mb-8"><OwnerCard lang={lang} variant="contact" /></div>
        </Suspense>
        <Suspense fallback={<Skeleton className="h-96" />}>
          <Form lang={lang} dict={dict} searchParams={searchParams} />
        </Suspense>
      </Container>
    </>
  );
}

async function Form({ lang, dict, searchParams }: { lang: Locale; dict: Dictionary; searchParams: Props["searchParams"] }) {
  const sp = await searchParams;
  const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v);
  const topic = TOPICS.includes(one(sp.topic) ?? "") ? one(sp.topic) : undefined;
  const ref = one(sp.ref)?.slice(0, 200);
  const branches = await safe(listBranches(lang), []);

  return (
    <ContactForm
      lang={lang}
      labels={{ ...dict.contact, branch: dict.contact.branch }}
      branches={branches.map((b) => ({ code: b.code, name: b.name }))}
      defaults={{ topic, message: ref ? `${dict.equipment.whatsappMessage} ${ref}\n` : undefined }}
    />
  );
}
