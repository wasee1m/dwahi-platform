// اختبار الحمل قبل الإطلاق (09 §10): ضعف أرقام NFR-03، ومعيار NFR-02 (المئين 95 ≤ 400ms للقراءة و ≤ 800ms للكتابة).
// يُشغَّل على staging لا على بيئة التطوير (php artisan serve عملية واحدة):
//   k6 run -e BASE=https://staging.example.com/api/v1 -e CUSTOMER_TOKEN=... -e TECH_TOKEN=... infra/loadtest/k6.js
// الرموز اختيارية: دونها تُختبر الصفحات العامة فقط.
import http from "k6/http";
import { check, sleep } from "k6";

const BASE = __ENV.BASE || "http://localhost:8000/api/v1";
const CUSTOMER = __ENV.CUSTOMER_TOKEN;
const TECH = __ENV.TECH_TOKEN;

export const options = {
  scenarios: {
    // 600 زائر وعميل متزامن (ضعف 300)، صعوداً على 5 دقائق ثم ثبات 10 دقائق
    browse: { executor: "ramping-vus", exec: "browse", stages: [{ duration: "5m", target: 600 }, { duration: "10m", target: 600 }, { duration: "2m", target: 0 }] },
    // 100 فني (ضعف 50) يجلبون مهامهم ويرسلون أحداثاً
    technicians: TECH ? { executor: "constant-vus", exec: "technician", vus: 100, duration: "15m" } : undefined,
  },
  thresholds: {
    "http_req_duration{kind:read}": ["p(95)<400"],
    "http_req_duration{kind:write}": ["p(95)<800"],
    http_req_failed: ["rate<0.01"],
  },
};
Object.keys(options.scenarios).forEach((k) => options.scenarios[k] === undefined && delete options.scenarios[k]);

const headers = (token) => ({ Accept: "application/json", "Accept-Language": "ar", ...(token ? { Authorization: `Bearer ${token}` } : {}) });
const read = (path, token) => {
  const r = http.get(`${BASE}${path}`, { headers: headers(token), tags: { kind: "read" } });
  check(r, { "200": (x) => x.status === 200 });
  return r;
};

const searches = ["فلتر", "1R-0750", "seal", "مضخة", "CAT"];

export function browse() {
  read("/equipment?per_page=12");
  read(`/parts/search?q=${encodeURIComponent(searches[Math.floor(Math.random() * searches.length)])}`);
  read("/public/branches");
  if (CUSTOMER) {
    read("/me", CUSTOMER);
    read("/me/rfqs", CUSTOMER);
    read("/me/orders", CUSTOMER);
    read("/me/service-requests", CUSTOMER);
  }
  sleep(1 + Math.random() * 2);
}

export function technician() {
  const tasks = read("/tech/tasks", TECH);
  const id = tasks.json("data.today.0.id");
  if (id) {
    read(`/tech/tasks/${id}`, TECH);
    // ملاحظة واحدة لكل دورة: الكتابة عبر صندوق الأحداث (04 §6.2)
    const r = http.post(`${BASE}/tech/sync/events`, JSON.stringify({
      events: [{ client_event_id: crypto.randomUUID(), work_order_id: id, type: "note_added", occurred_at: new Date().toISOString(), payload: { body: "k6" } }],
    }), { headers: { ...headers(TECH), "Content-Type": "application/json" }, tags: { kind: "write" } });
    check(r, { "sync 200": (x) => x.status === 200 });
  }
  sleep(5 + Math.random() * 10);
}
