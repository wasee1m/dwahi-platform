import type { paths } from "@/lib/api-schema";
import type { PartCard } from "@/lib/types";

// أنواع الحساب من العقد المولّد، فلا تُكتب مرتين (NFR-19)
type Get<P extends keyof paths> = paths[P] extends {
  get: { responses: { 200: { content: { "application/json": { data: infer D } } } } };
} ? D : never;

export type Company = Get<"/v1/me/organizations">[number];
export type MyInvitation = Get<"/v1/me/invitations">[number];
export type Address = Get<"/v1/me/addresses">[number];
export type FleetItem = Get<"/v1/me/fleet">[number];
export type FleetEvent = Get<"/v1/me/fleet/{id}/history">[number];
export type CatalogModel = Get<"/v1/public/models">[number];
// بطاقة القطعة بنوعها المضبوط المستعمل في بقية الكتالوج
export type CompatibleParts = Omit<Get<"/v1/parts/compatible">, "groups"> & { groups: { category: string | null; parts: PartCard[] }[] };
export type Preferences = Get<"/v1/me/notification-preferences">;
export type DataExport = Get<"/v1/me/data-export">;
export type Deletion = Get<"/v1/me/deletion">;
export type CustomerRow = Get<"/v1/admin/customers">[number];
export type CustomerProfile = Get<"/v1/admin/customers/{id}">;
export type CompanyRow = Get<"/v1/admin/organizations">[number];
export type CompanyProfile = Get<"/v1/admin/organizations/{id}">;
