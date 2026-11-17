// أنواع استجابات الـ API العامة (/api/v1). المرجع النهائي عقد OpenAPI (NFR-19).
export type Money = { amount: string; currency: string };

export type Named = { name: string; slug: string };

export type EquipmentKind = "machine" | "component" | "scrap";
export type EquipmentCondition = "new" | "used" | "rebuilt" | "for_parts";

export type EquipmentCard = {
  id: string;
  slug: string;
  kind: EquipmentKind;
  title: string;
  condition: EquipmentCondition;
  status: "published" | "reserved" | "sold";
  year: number | null;
  hour_meter: number | null;
  price: Money | null;
  brand: Named | null;
  category: Named | null;
  model_name: string | null;
  component_model: string | null;
  warranty_months: number | null;
  branch: { name: string } | null;
  cover_image_url: string | null;
  is_featured: boolean;
};

export type EquipmentDetail = EquipmentCard & {
  description: string | null;
  serial_number: string | null;
  source_machine: string | null;
  missing_parts: string | null;
  specs: { label: string; value: string; unit: string | null }[];
  media: { url: string; width: number | null; height: number | null }[];
  branch: { name: string; whatsapp_sales: string | null; phone: string | null } | null;
  similar: EquipmentCard[];
};

export type Page<T> = { data: T[]; next_cursor: string | null; total?: number };

export type Facets = {
  kinds: { value: EquipmentKind; count: number }[];
  conditions: { value: EquipmentCondition; count: number }[];
  categories: (Named & { count: number })[];
  brands: (Named & { count: number })[];
};

export type Availability = "available" | "limited" | "on_request";

export type PartCard = {
  id: string;
  brand: Named;
  part_number: string;
  part_number_normalized: string;
  name: string;
  price: Money;
  availability: Availability;
  warranty_months: number | null;
  matched_alternative: string | null;
  cover_image_url: string | null;
};

export type PartDetail = PartCard & {
  description: string | null;
  unit: string;
  vat_rate: string;
  is_returnable: boolean;
  alternatives: { number: string; brand: string | null }[];
  compatible_models: { brand: string; model_name: string; slug: string }[];
  media: { url: string }[];
};

export type Branch = {
  code: string;
  name: string;
  address: string | null;
  governorate: string;
  wilayat: string;
  phone: string | null;
  whatsapp_sales: string | null;
  whatsapp_service: string | null;
  whatsapp_parts: string | null;
  email: string | null;
  latitude: number;
  longitude: number;
  working_hours: Record<string, { open: string; close: string } | null>;
};

export type ContentPage = {
  slug: string;
  title: string;
  body: string;
  seo_title: string | null;
  seo_description: string | null;
  updated_at: string;
};

export type Faq = { id: string; section: string; question: string; answer: string };

export type Banner = { id: string; image_url: string; link: string | null };

export type Brand = Named & { logo_url: string | null; is_represented: boolean };
