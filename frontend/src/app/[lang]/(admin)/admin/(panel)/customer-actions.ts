"use server";

import { revalidatePath } from "next/cache";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import { authed } from "@/lib/auth/client";
import type { AdminState } from "../actions";
import { SEARCH_COOKIE } from "./customers/shared";
import { bool, fail, id, lang, str } from "./form-data";

// A-12 العملاء والشركات (FR-ADM-05، US-45، SM-7). الـ API هو الحَكَم في الصلاحيات.

/** نص البحث جوال أو اسم، فلا يوضع في الرابط: كوكي HttpOnly قصيرة العمر */
export async function searchCustomers(form: FormData) {
  const l = lang(form);
  const jar = await cookies();
  const q = str(form, "q").slice(0, 100);
  if (q) jar.set(SEARCH_COOKIE, q, { httpOnly: true, sameSite: "lax", secure: process.env.NODE_ENV === "production", path: "/", maxAge: 900 });
  else jar.delete(SEARCH_COOKIE);
  redirect(`/${l}/admin/customers${str(form, "view") === "companies" ? "?view=companies" : ""}`);
}

async function send(form: FormData, path: string, method: string, body?: unknown): Promise<AdminState> {
  const l = lang(form);
  const r = await authed("admin", l, path, { method, body });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/admin/customers`, "layout");
  return { ok: true };
}

export async function addNote(_prev: AdminState, form: FormData): Promise<AdminState> {
  const base = str(form, "subject") === "company" ? "organizations" : "customers";
  return send(form, `/admin/${base}/${id(form)}/notes`, "POST", { body: str(form, "body") });
}

export async function suspendCustomer(_prev: AdminState, form: FormData): Promise<AdminState> {
  return send(form, `/admin/customers/${id(form)}/suspend`, "POST", { reason: str(form, "reason") });
}

export async function activateCustomer(_prev: AdminState, form: FormData): Promise<AdminState> {
  return send(form, `/admin/customers/${id(form)}/activate`, "POST");
}

export async function inviteToCompany(_prev: AdminState, form: FormData): Promise<AdminState> {
  return send(form, `/admin/organizations/${id(form)}/invitations`, "POST", {
    phone: str(form, "phone"), role: str(form, "role"), can_approve_quotes: bool(form, "can_approve_quotes"),
  });
}

export async function revokeCompanyInvitation(_prev: AdminState, form: FormData): Promise<AdminState> {
  return send(form, `/admin/organizations/${id(form)}/invitations/${encodeURIComponent(str(form, "invitation"))}`, "DELETE");
}

export async function updateCompanyMember(_prev: AdminState, form: FormData): Promise<AdminState> {
  return send(form, `/admin/organizations/${id(form)}/members/${encodeURIComponent(str(form, "member"))}`, "PATCH", {
    role: str(form, "role"), can_approve_quotes: bool(form, "can_approve_quotes"),
  });
}

export async function removeCompanyMember(_prev: AdminState, form: FormData): Promise<AdminState> {
  return send(form, `/admin/organizations/${id(form)}/members/${encodeURIComponent(str(form, "member"))}`, "DELETE");
}
