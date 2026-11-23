"use client";

import { useActionState, useState } from "react";
import { Field, inputClass, Notice, SubmitButton } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import { createStaff, staffAction, updateStaffRoles, type AdminState } from "../../actions";

export type Staff = {
  id: string; full_name: string; email: string | null; phone: string | null; status: "active" | "suspended";
  suspended_reason: string | null; employee_no: string | null; branch: { code: string; name: string } | null;
  restrict_to_branch: boolean; skills: string[]; roles: string[]; two_factor_enabled: boolean; activated: boolean; last_login_at: string | null;
};
type T = AppDictionary["admin"]["users"] & { roleNames: AppDictionary["admin"]["roles"] };
type Branch = { code: string; name: string };

function RoleChecks({ roles, t, selected }: { roles: string[]; t: T; selected: string[] }) {
  return (
    <div className="flex flex-wrap gap-2">
      {roles.map((r) => (
        <label key={r} className="flex items-center gap-1.5 rounded-sm bg-sand-100 px-2.5 py-1.5 text-sm">
          <input type="checkbox" name="roles" value={r} defaultChecked={selected.includes(r)} className="size-4 accent-[var(--sun)]" />
          {t.roleNames[r as keyof T["roleNames"]] ?? r}
        </label>
      ))}
    </div>
  );
}

export function CreateStaffForm({ lang, t, roles, branches }: { lang: string; t: T; roles: string[]; branches: Branch[] }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(createStaff, {});
  const [open, setOpen] = useState(false);
  const err = (k: string) => state.fieldErrors?.[k]?.[0];

  if (!open) {
    return (
      <div className="space-y-3">
        {state.ok && <Notice tone="success">{t.created}</Notice>}
        <button onClick={() => setOpen(true)} className="rounded-sm bg-sun px-5 py-2.5 font-bold text-on-sun hover:bg-sun-deep">{t.add}</button>
      </div>
    );
  }
  return (
    <form action={async (f) => { await action(f); }} className="grid gap-4 rounded-md border border-sand-300 bg-sand-0 p-6 md:grid-cols-2">
      <input type="hidden" name="lang" value={lang} />
      <Field label={t.name} error={err("full_name")}><input name="full_name" required className={inputClass} /></Field>
      <Field label={t.email} error={err("email")}><input name="email" type="email" dir="ltr" required className={inputClass} /></Field>
      <Field label={t.phone} error={err("phone")}><input name="phone" type="tel" dir="ltr" className={inputClass} /></Field>
      <Field label={t.employeeNo} error={err("employee_no")}><input name="employee_no" dir="ltr" required className={inputClass} /></Field>
      <Field label={t.branch} error={err("branch_code")}>
        <select name="branch_code" className={inputClass}>{branches.map((b) => <option key={b.code} value={b.code}>{b.name}</option>)}</select>
      </Field>
      <label className="flex items-center gap-2 self-end pb-3 text-sm">
        <input type="checkbox" name="restrict_to_branch" className="size-4 accent-[var(--sun)]" /> {t.restrict}
      </label>
      <div className="md:col-span-2">
        <p className="mb-2 text-sm font-semibold">{t.roles}</p>
        <RoleChecks roles={roles} t={t} selected={[]} />
        {err("roles") && <p className="mt-1 text-sm text-danger">{err("roles")}</p>}
      </div>
      {state.error && !state.fieldErrors && <div className="md:col-span-2"><Notice tone="danger">{state.error.message}</Notice></div>}
      <div className="flex gap-3 md:col-span-2">
        <SubmitButton pending={pending}>{t.create}</SubmitButton>
        <button type="button" onClick={() => setOpen(false)} className="rounded-sm px-4 py-3 font-semibold text-ink-muted hover:bg-sand-200">✕</button>
      </div>
    </form>
  );
}

export function StaffRow({ lang, t, staff, roles, branches, self }: { lang: string; t: T; staff: Staff; roles: string[]; branches: Branch[]; self: boolean }) {
  const [editing, setEditing] = useState(false);
  const [saveState, save, saving] = useActionState<AdminState, FormData>(updateStaffRoles, {});
  const [opState, op, operating] = useActionState<AdminState, FormData>(staffAction, {});
  const isTech = staff.roles.includes("technician");

  return (
    <>
      <tr className="align-top">
        <td className="px-4 py-3">
          <p className="font-semibold">{staff.full_name}</p>
          <p className="num text-xs text-ink-muted" dir="ltr">{staff.email}</p>
          {!staff.activated && <p className="text-xs font-semibold text-[var(--status-waiting)]">{t.notActivated}</p>}
        </td>
        <td className="px-4 py-3">{staff.roles.map((r) => t.roleNames[r as keyof T["roleNames"]] ?? r).join("، ")}</td>
        <td className="px-4 py-3">{staff.branch?.name}</td>
        <td className="px-4 py-3">
          <span style={{ color: staff.status === "active" ? "var(--status-success)" : "var(--status-hold)" }} className="font-semibold">
            {t.statuses[staff.status]}
          </span>
          {staff.suspended_reason && <p className="text-xs text-ink-muted">{staff.suspended_reason}</p>}
        </td>
        <td className="px-4 py-3">{staff.two_factor_enabled ? t.enabled : t.disabled}</td>
        <td className="px-4 py-3">
          <div className="flex flex-wrap justify-end gap-2">
            <button onClick={() => setEditing((e) => !e)} className="rounded-sm border border-ink px-3 py-1 font-semibold">{t.edit}</button>
            {!self && (
              <form action={op} className="flex flex-wrap gap-2">
                <input type="hidden" name="lang" value={lang} />
                <input type="hidden" name="id" value={staff.id} />
                {staff.status === "active" ? (
                  <>
                    <input name="reason" placeholder={t.suspendReason} required className="w-40 rounded-sm border border-sand-300 px-2 py-1" />
                    <button name="op" value="suspend" disabled={operating} className="rounded-sm bg-danger px-3 py-1 font-semibold text-white">{t.suspend}</button>
                  </>
                ) : (
                  <button name="op" value="activate" disabled={operating} className="rounded-sm bg-success px-3 py-1 font-semibold text-white">{t.activate}</button>
                )}
                {staff.two_factor_enabled && <button name="op" value="reset2fa" formNoValidate disabled={operating} className="rounded-sm border border-ink px-3 py-1 font-semibold">{t.reset2fa}</button>}
                {isTech && <button name="op" value="code" formNoValidate disabled={operating} className="rounded-sm border border-ink px-3 py-1 font-semibold">{t.activationCode}</button>}
              </form>
            )}
          </div>
          {opState.message && <p className="mt-2 text-end text-sm">{t.activationCodeIssued} <strong className="num text-lg" dir="ltr">{opState.message}</strong></p>}
          {opState.error && <p className="mt-2 text-end text-sm text-danger">{opState.error.message}</p>}
        </td>
      </tr>
      {editing && (
        <tr>
          <td colSpan={6} className="bg-sand-100 px-4 py-4">
            <form action={save} className="space-y-3">
              <input type="hidden" name="lang" value={lang} />
              <input type="hidden" name="id" value={staff.id} />
              <RoleChecks roles={roles} t={t} selected={staff.roles} />
              <div className="flex flex-wrap items-center gap-4">
                <select name="branch_code" defaultValue={staff.branch?.code} className="rounded-sm border border-sand-300 px-2 py-1.5">
                  {branches.map((b) => <option key={b.code} value={b.code}>{b.name}</option>)}
                </select>
                <label className="flex items-center gap-2 text-sm">
                  <input type="checkbox" name="restrict_to_branch" defaultChecked={staff.restrict_to_branch} className="size-4 accent-[var(--sun)]" /> {t.restrict}
                </label>
                {staff.roles.includes("technician") && (
                  <input name="skills" defaultValue={staff.skills.join("، ")} placeholder={t.skills} aria-label={t.skills}
                    className="min-w-60 rounded-sm border border-sand-300 px-2 py-1.5 text-sm" />
                )}
                <SubmitButton pending={saving}>{t.saveRoles}</SubmitButton>
                {saveState.ok && <span className="text-sm font-semibold text-success">✓</span>}
                {saveState.error && <span className="text-sm text-danger">{saveState.error.message}</span>}
              </div>
            </form>
          </td>
        </tr>
      )}
    </>
  );
}
