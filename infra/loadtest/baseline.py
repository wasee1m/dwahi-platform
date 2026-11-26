"""Baseline response times for the main read endpoints, one request at a time (NFR-02).

Not a load test: the local dev server handles one request at a time. It shows whether each endpoint
is fast on its own before the k6 run on staging.

    python infra/loadtest/baseline.py [BASE_URL] [ROUNDS]
"""
import statistics
import sys
import time
import urllib.parse
import urllib.request

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://localhost:8000/api/v1"
ROUNDS = int(sys.argv[2]) if len(sys.argv) > 2 else 40
PATHS = [
    "/equipment?per_page=12",
    "/equipment/facets",
    "/parts/search?q=" + urllib.parse.quote("فلتر"),
    "/parts/search?q=1R-0750",
    "/public/branches",
    "/public/faqs",
    "/health",
]


def p95(values):
    s = sorted(values)
    return s[max(0, int(round(0.95 * len(s))) - 1)]


print(f"{'endpoint':42} {'median':>8} {'p95':>8}  (ms, {ROUNDS} rounds)")
worst = 0.0
for path in PATHS:
    times = []
    for _ in range(ROUNDS):
        req = urllib.request.Request(BASE + path, headers={"Accept": "application/json", "Accept-Language": "ar"})
        t = time.perf_counter()
        with urllib.request.urlopen(req) as r:
            r.read()
        times.append((time.perf_counter() - t) * 1000)
    worst = max(worst, p95(times))
    print(f"{path[:42]:42} {statistics.median(times):8.0f} {p95(times):8.0f}")
print("NFR-02 read p95 <= 400ms:", "PASS" if worst <= 400 else "FAIL", f"(worst {worst:.0f} ms)")
