import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { listBranches } from "@/lib/api";
import { CreateStaffForm, StaffRow, type Staff } from "./StaffForms";

// A-17 الموظفون والأدوار (FR-ADM-01، US-39): للمدير فقط. الـ API يرفض غيره (403) مهما ظهر في الواجهة.
const ROLES =["admin", "sales", "service_coordinator", "technician", "parts_staff", "accountant", "content_editor"] as const;

export default async function UsersPage({ params }: PageProps<"/[lang]/admin/users">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <h1 className="text-3xl">{t.admin.users.title}</h1>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <Users lang={lang} t={t} />
      </Suspense>
    </div>
  );
}

async function Users({ lang, t }: { lang: Locale; t: AppDictionary }) {
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("staff.manage")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;

  const [staff, branches] = await Promise.all([authed<Staff[]>("admin", lang, "/admin/staff"), listBranches(lang)]);
  const branchOptions = branches.map((b) => ({ code: b.code, name: b.name }));
  const labels = { ...t.admin.users, roleNames: t.admin.roles };

  return (
    <>
      <CreateStaffForm lang={lang} t={labels} roles={[...ROLES]} branches={branchOptions} />
      <div className="overflow-x-auto rounded-md border border-sand-300 bg-sand-0">
        <table className="w-full min-w-[900px] text-sm">
          <thead className="bg-sand-200">
            <tr>
              {[labels.name, labels.roles, labels.branch, labels.status, labels.twoFactor, ""].map((h, i) => (
                <th key={i} scope="col" className="px-4 py-3 text-start">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-sand-300">
            {(staff.ok ? staff.data : []).map((s) => (
              <StaffRow key={s.id} lang={lang} t={labels} staff={s} roles={[...ROLES]} branches={branchOptions} self={s.id === me.id} />
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
