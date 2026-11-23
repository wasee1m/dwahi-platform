<?php

namespace App\Modules\Reports\Services;

use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * لوحة المؤشرات والتقارير (FR-RPT-01، FR-RPT-03، US-41، A-01، A-16). الفترة بتوقيت مسقط، والفرع اختياري،
 * وما يراه المستخدم حسب دوره: reports.own يرى فرعه فقط.
 */
final class ReportService
{
    public const REPORTS = ['quotes', 'service', 'parts', 'stockouts', 'revenue'];

    /** أعمدة كل تقرير بترتيبها في الجدول والملف المصدَّر */
    public const COLUMNS = [
        'quotes' => ['number', 'type', 'status', 'created_at', 'first_sent_at', 'response_hours', 'lost_reason'],
        'service' => ['number', 'status', 'source', 'urgency', 'created_at', 'sla_due_at', 'sla_breached', 'age_days'],
        'parts' => ['part_number', 'name', 'quantity', 'revenue'],
        'stockouts' => ['part_number', 'name', 'available'],
        'revenue' => ['line', 'branch', 'invoices', 'total', 'collected'],
    ];

    /** الأقسام وصلاحية كل منها */
    private const SECTIONS = [
        'quotes' => ['quotation.send'],
        'service' => ['service.triage', 'workorder.verify'],
        'parts' => ['orders.deliver', 'parts.manage'],
        'revenue' => ['invoices.manage', 'reports.view'],
        'ratings' => ['service.triage', 'reports.view'],
    ];

    private const REPORT_SECTION = ['quotes' => 'quotes', 'service' => 'service', 'parts' => 'parts', 'stockouts' => 'parts', 'revenue' => 'revenue'];

    public static function canSee(User $user, string $section): bool
    {
        if (! $user->hasAbility('reports.view') && ! $user->hasAbility('reports.own')) {
            return false;
        }
        foreach (self::SECTIONS[$section] ?? [] as $ability) {
            if ($user->hasAbility($ability)) {
                return true;
            }
        }

        return false;
    }

    public static function canReport(User $user, string $key): bool
    {
        return isset(self::REPORT_SECTION[$key]) && self::canSee($user, self::REPORT_SECTION[$key]);
    }

    /**
     * الفترة والفرع بعد تطبيق الدور: من لا يملك reports.view يُقصر على فرعه.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, branch: string|null}
     */
    public static function scope(User $user, ?string $from, ?string $to, ?string $branch): array
    {
        $tz = 'Asia/Muscat';
        $start = $from ? CarbonImmutable::parse($from, $tz)->startOfDay() : CarbonImmutable::now($tz)->startOfMonth();
        $end = $to ? CarbonImmutable::parse($to, $tz)->endOfDay() : CarbonImmutable::now($tz)->endOfDay();
        if (! $user->hasAbility('reports.view')) {
            $branch = $user->staffProfile?->branch_id;
        }

        return ['from' => $start->utc(), 'to' => $end->utc(), 'branch' => $branch ?: null];
    }

    private static function branch(Builder $q, ?string $branch, string $column = 'branch_id'): Builder
    {
        return $branch ? $q->where($column, $branch) : $q;
    }

    private static function between(Builder $q, string $column, array $s): Builder
    {
        return $q->whereBetween($column, [$s['from'], $s['to']]);
    }

    /** وسيط مجموعة أرقام */
    private static function median(Collection $values): ?float
    {
        $v = $values->filter(fn ($x) => $x !== null)->sort()->values();
        $n = $v->count();
        if ($n === 0) {
            return null;
        }

        return $n % 2 ? (float) $v[intdiv($n, 2)] : ((float) $v[$n / 2 - 1] + (float) $v[$n / 2]) / 2;
    }

    /** ساعات الرد لكل طلب عرض: من إنشائه إلى أول عرض مرسل */
    private static function responseRows(array $s): Collection
    {
        $first = DB::table('quotations')->whereNotNull('sent_at')->groupBy('rfq_id')->selectRaw('rfq_id, MIN(sent_at) AS first_sent');

        return self::branch(DB::table('rfqs as r'), $s['branch'], 'r.branch_id')
            ->leftJoinSub($first, 'q', 'q.rfq_id', '=', 'r.id')
            ->where('r.is_additional', false)->whereBetween('r.created_at', [$s['from'], $s['to']])
            ->orderBy('r.created_at')
            ->get(['r.id', 'r.number', 'r.type', 'r.status', 'r.lost_reason', 'r.created_at', 'r.branch_id', 'q.first_sent'])
            ->map(function ($r) {
                $r->response_hours = $r->first_sent ? round((strtotime($r->first_sent) - strtotime($r->created_at)) / 3600, 1) : null;

                return $r;
            });
    }

    /** @return array<string, mixed> */
    public function dashboard(User $user, array $s): array
    {
        $out = ['period' => ['from' => $s['from']->toIso8601ZuluString(), 'to' => $s['to']->toIso8601ZuluString()], 'branch_id' => $s['branch'], 'sections' => []];
        $today = [CarbonImmutable::now('Asia/Muscat')->startOfDay()->utc(), CarbonImmutable::now('Asia/Muscat')->endOfDay()->utc()];

        if (self::canSee($user, 'quotes')) {
            $rows = self::responseRows($s);
            $decided = $rows->whereIn('status', ['won', 'lost']);
            $out['sections']['quotes'] = [
                'rfqs' => $rows->count(),
                'median_response_hours' => self::median($rows->pluck('response_hours')),
                'acceptance_rate' => $decided->count() ? round($decided->where('status', 'won')->count() / $decided->count() * 100, 1) : null,
                'awaiting_quote' => self::branch(DB::table('rfqs'), $s['branch'])->whereIn('status', ['submitted', 'in_review'])->count(),
            ];
        }
        if (self::canSee($user, 'service')) {
            $created = self::between(self::branch(DB::table('service_requests'), $s['branch']), 'created_at', $s);
            $total = (clone $created)->count();
            $closedWo = self::between(self::branch(DB::table('work_orders as w'), $s['branch'], 'w.branch_id'), 'w.closed_at', $s)
                ->where('w.status', 'closed')->where('w.type', '!=', 'inspection');
            $documented = (clone $closedWo)->whereExists(fn ($q) => $q->from('service_reports as r')->whereColumn('r.work_order_id', 'w.id'))
                ->whereExists(fn ($q) => $q->from('work_order_tests as t')->whereColumn('t.work_order_id', 'w.id')->where('t.result', 'pass'))
                ->whereExists(fn ($q) => $q->from('service_handovers as h')->whereColumn('h.service_request_id', 'w.service_request_id'))->count();
            $closedCount = (clone $closedWo)->count();
            $out['sections']['service'] = [
                'open' => self::branch(DB::table('service_requests'), $s['branch'])->whereNotIn('status', ['closed', 'cancelled', 'declined'])->count(),
                'sla_breached' => self::branch(DB::table('service_requests'), $s['branch'])->where('status', 'submitted')->where('sla_due_at', '<', now())->count(),
                'created' => $total,
                'platform_share' => $total ? round((clone $created)->where('source', 'customer')->count() / $total * 100, 1) : null,
                'work_orders_today' => self::branch(DB::table('work_orders'), $s['branch'])->whereBetween('scheduled_start', $today)->whereNotIn('status', ['cancelled'])->count(),
                'in_progress' => self::branch(DB::table('work_orders'), $s['branch'])->where('status', 'in_progress')->count(),
                'on_hold' => self::branch(DB::table('work_orders'), $s['branch'])->where('status', 'on_hold')->count(),
                'ready_for_pickup' => self::branch(DB::table('service_requests'), $s['branch'])->where('status', 'completed')->count(),
                'documented_rate' => $closedCount ? round($documented / $closedCount * 100, 1) : null,
            ];
        }
        if (self::canSee($user, 'parts')) {
            $delivered = self::between(self::branch(DB::table('sales_orders'), $s['branch']), 'delivered_at', $s)->where('status', 'delivered');
            $out['sections']['parts'] = [
                'orders_delivered' => (clone $delivered)->count(),
                'sales' => Money::of(number_format((float) (clone $delivered)->sum('total'), 3, '.', '')),
                'open_orders' => self::branch(DB::table('sales_orders'), $s['branch'])->whereIn('status', ['confirmed', 'processing', 'ready_for_pickup', 'out_for_delivery'])->count(),
                'stockouts' => $this->stockouts($s)->count(),
            ];
        }
        if (self::canSee($user, 'revenue')) {
            $issued = self::between(self::branch(DB::table('invoices'), $s['branch']), 'issued_at', $s)->whereNotIn('status', ['draft', 'void']);
            $collected = self::between(self::branch(DB::table('payments'), $s['branch']), 'received_at', $s)->whereIn('status', ['succeeded', 'partially_refunded']);
            $out['sections']['revenue'] = [
                'invoiced' => Money::of(number_format((float) (clone $issued)->sum('total'), 3, '.', '')),
                'collected' => Money::of(number_format((float) $collected->sum(DB::raw('amount - refunded_amount')), 3, '.', '')),
                'by_line' => collect($this->revenueRows($s))->groupBy('line')->map(fn ($g, $line) => ['line' => $line, 'total' => Money::of(number_format((float) $g->sum('total_raw'), 3, '.', ''))])->values()->all(),
            ];
        }
        if (self::canSee($user, 'ratings')) {
            $r = self::branch(DB::table('reviews as v')->join('service_requests as s', 's.id', '=', 'v.service_request_id'), $s['branch'], 's.branch_id')
                ->whereBetween('v.created_at', [$s['from'], $s['to']]);
            $out['sections']['ratings'] = ['average' => ($avg = (clone $r)->avg('v.rating')) === null ? null : round((float) $avg, 2), 'count' => (clone $r)->count()];
        }
        $out['series'] = $this->series($user, $s);
        $out['attention'] = $this->attention($user, $s);

        return $out;
    }

    /** رسم زمني يومي: طلبات العروض والإيرادات */
    private function series(User $user, array $s): array
    {
        $days = [];
        for ($d = $s['from']->timezone('Asia/Muscat')->startOfDay(); $d->lte($s['to']->timezone('Asia/Muscat')) && count($days) < 92; $d = $d->addDay()) {
            $days[$d->toDateString()] = ['date' => $d->toDateString(), 'rfqs' => 0, 'revenue' => '0.000'];
        }
        $day = "DATE(CONVERT_TZ(%s, '+00:00', '+04:00'))";
        if (self::canSee($user, 'quotes')) {
            foreach (self::between(self::branch(DB::table('rfqs'), $s['branch']), 'created_at', $s)->where('is_additional', false)
                ->selectRaw(sprintf($day, 'created_at').' AS d, COUNT(*) AS n')->groupBy('d')->get() as $r) {
                if (isset($days[$r->d])) {
                    $days[$r->d]['rfqs'] = (int) $r->n;
                }
            }
        }
        if (self::canSee($user, 'revenue')) {
            foreach (self::between(self::branch(DB::table('invoices'), $s['branch']), 'issued_at', $s)->whereNotIn('status', ['draft', 'void'])
                ->selectRaw(sprintf($day, 'issued_at').' AS d, SUM(total) AS t')->groupBy('d')->get() as $r) {
                if (isset($days[$r->d])) {
                    $days[$r->d]['revenue'] = number_format((float) $r->t, 3, '.', '');
                }
            }
        }

        return array_values($days);
    }

    /** "تحتاج انتباهاً": SLA متجاوزة، وأوامر عمل متوقفة، وعروض تنتهي اليوم */
    private function attention(User $user, array $s): array
    {
        $out = [];
        if (self::canSee($user, 'service')) {
            $out['sla_breached'] = self::branch(DB::table('service_requests'), $s['branch'])->where('status', 'submitted')->where('sla_due_at', '<', now())
                ->orderBy('sla_due_at')->limit(10)->get(['id', 'number', 'urgency', 'sla_due_at'])
                ->map(fn ($r) => ['id' => $r->id, 'number' => $r->number, 'urgency' => $r->urgency, 'since' => self::iso($r->sla_due_at)])->values()->all();
            $out['on_hold'] = self::branch(DB::table('work_orders'), $s['branch'])->where('status', 'on_hold')->orderBy('updated_at')->limit(10)
                ->get(['id', 'number', 'on_hold_reason', 'updated_at'])
                ->map(fn ($r) => ['id' => $r->id, 'number' => $r->number, 'reason' => $r->on_hold_reason, 'since' => self::iso($r->updated_at)])->values()->all();
        }
        if (self::canSee($user, 'quotes')) {
            $out['expiring_today'] = self::branch(DB::table('quotations as q')->join('rfqs as r', 'r.id', '=', 'q.rfq_id'), $s['branch'], 'r.branch_id')
                ->where('q.status', 'sent')->whereDate('q.valid_until', CarbonImmutable::now('Asia/Muscat')->toDateString())
                ->limit(10)->get(['r.id', 'q.number', 'q.total'])
                ->map(fn ($r) => ['id' => $r->id, 'number' => $r->number, 'total' => Money::of((string) $r->total)])->values()->all();
        }

        return $out;
    }

    private static function iso(?string $ts): ?string
    {
        return $ts ? str_replace(' ', 'T', substr($ts, 0, 19)).'Z' : null;
    }

    /** القطع النشطة بلا متاح في أي موقع (ما نفد) */
    private function stockouts(array $s): Collection
    {
        $free = DB::table('stock_levels as l')->join('stock_locations as loc', 'loc.id', '=', 'l.location_id')
            ->when($s['branch'], fn ($q, $b) => $q->where('loc.branch_id', $b))
            ->groupBy('l.part_id')->selectRaw('l.part_id, SUM(l.qty_on_hand - l.qty_reserved) AS free');

        return DB::table('parts as p')->leftJoinSub($free, 'f', 'f.part_id', '=', 'p.id')->where('p.is_active', true)
            ->whereRaw('COALESCE(f.free, 0) <= 0')->orderBy('p.part_number')->limit(500)
            ->get(['p.part_number', 'p.name_ar', 'p.name_en', DB::raw('COALESCE(f.free, 0) AS free')]);
    }

    /** @return list<array<string, mixed>> */
    private function revenueRows(array $s): array
    {
        $line = "CASE WHEN i.sales_order_id IS NOT NULL THEN 'parts' WHEN i.service_request_id IS NOT NULL THEN 'service' WHEN i.quotation_id IS NOT NULL THEN 'equipment' ELSE 'other' END";
        $paid = DB::table('payments')->whereIn('status', ['succeeded', 'partially_refunded'])->groupBy('invoice_id')->selectRaw('invoice_id, SUM(amount - refunded_amount) AS paid');

        return self::branch(DB::table('invoices as i'), $s['branch'], 'i.branch_id')
            ->leftJoin('branches as b', 'b.id', '=', 'i.branch_id')->leftJoinSub($paid, 'p', 'p.invoice_id', '=', 'i.id')
            ->whereBetween('i.issued_at', [$s['from'], $s['to']])->whereNotIn('i.status', ['draft', 'void'])
            ->groupByRaw("{$line}, i.branch_id, b.name_ar, b.name_en")
            ->selectRaw("{$line} AS line, b.name_ar, b.name_en, COUNT(*) AS invoices, SUM(i.total) AS total, COALESCE(SUM(p.paid), 0) AS paid")
            ->get()->map(fn ($r) => [
                'line' => $r->line, 'branch' => app()->getLocale() === 'en' ? ($r->name_en ?: $r->name_ar) : $r->name_ar,
                'invoices' => (int) $r->invoices, 'total_raw' => (float) $r->total,
                'total' => number_format((float) $r->total, 3, '.', ''), 'collected' => number_format((float) $r->paid, 3, '.', ''),
            ])->values()->all();
    }

    /**
     * تقرير جاهز (A-16): أعمدة بمفاتيح ثابتة، وصفوف قيمها نصوص أو أرقام، وملخص.
     *
     * @return array{key: string, columns: list<string>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function report(string $key, array $s): array
    {
        $en = app()->getLocale() === 'en';
        $iso = fn ($ts) => $ts ? CarbonImmutable::parse($ts, 'UTC')->timezone('Asia/Muscat')->format('Y-m-d H:i') : null;

        return match ($key) {
            'quotes' => (function () use ($s, $iso) {
                $rows = self::responseRows($s);
                $decided = $rows->whereIn('status', ['won', 'lost']);

                return ['key' => 'quotes', 'columns' => self::COLUMNS['quotes'],
                    'rows' => $rows->map(fn ($r) => ['number' => $r->number, 'type' => $r->type, 'status' => $r->status, 'created_at' => $iso($r->created_at),
                        'first_sent_at' => $iso($r->first_sent), 'response_hours' => $r->response_hours, 'lost_reason' => $r->lost_reason])->values()->all(),
                    'summary' => [
                        'count' => $rows->count(), 'median_response_hours' => self::median($rows->pluck('response_hours')),
                        'acceptance_rate' => $decided->count() ? round($decided->where('status', 'won')->count() / $decided->count() * 100, 1) : null,
                        'lost_reasons' => $rows->where('status', 'lost')->groupBy(fn ($r) => $r->lost_reason ?: '—')->map->count()->sortDesc()->all(),
                    ]];
            })(),
            'service' => (function () use ($s, $iso) {
                $rows = self::branch(DB::table('service_requests'), $s['branch'])
                    ->where(fn ($q) => $q->whereBetween('created_at', [$s['from'], $s['to']])->orWhereNotIn('status', ['closed', 'cancelled', 'declined']))
                    ->orderBy('created_at')->limit(5000)->get(['number', 'status', 'source', 'urgency', 'created_at', 'sla_due_at', 'triaged_at']);
                $now = now();
                $data = $rows->map(fn ($r) => [
                    'number' => $r->number, 'status' => $r->status, 'source' => $r->source, 'urgency' => $r->urgency, 'created_at' => $iso($r->created_at),
                    'sla_due_at' => $iso($r->sla_due_at),
                    'sla_breached' => $r->triaged_at ? strtotime($r->triaged_at) > strtotime($r->sla_due_at) : ($r->status === 'submitted' && strtotime($r->sla_due_at) < $now->timestamp),
                    'age_days' => (int) floor(($now->timestamp - strtotime($r->created_at)) / 86400),
                ]);
                $open = $data->reject(fn ($r) => in_array($r['status'], ['closed', 'cancelled', 'declined'], true));

                return ['key' => 'service', 'columns' => self::COLUMNS['service'],
                    'rows' => $data->values()->all(), 'summary' => [
                        'count' => $data->count(), 'sla_breached' => $data->where('sla_breached', true)->count(),
                        'open_by_age' => ['0-2' => $open->where('age_days', '<=', 2)->count(), '3-7' => $open->whereBetween('age_days', [3, 7])->count(),
                            '8-30' => $open->whereBetween('age_days', [8, 30])->count(), '31+' => $open->where('age_days', '>', 30)->count()],
                    ]];
            })(),
            'parts' => (function () use ($s, $en) {
                $rows = self::branch(DB::table('sales_order_items as i')->join('sales_orders as o', 'o.id', '=', 'i.order_id'), $s['branch'], 'o.branch_id')
                    ->where('o.status', 'delivered')->whereBetween('o.delivered_at', [$s['from'], $s['to']])
                    ->groupBy('i.part_id', 'i.part_number_snapshot', 'i.name_ar_snapshot', 'i.name_en_snapshot')
                    ->selectRaw('i.part_number_snapshot AS part_number, i.name_ar_snapshot AS name_ar, i.name_en_snapshot AS name_en, SUM(i.quantity) AS quantity, SUM(i.line_total) AS revenue')
                    ->orderByDesc('quantity')->limit(200)->get();

                return ['key' => 'parts', 'columns' => self::COLUMNS['parts'],
                    'rows' => $rows->map(fn ($r) => ['part_number' => $r->part_number, 'name' => $en ? ($r->name_en ?: $r->name_ar) : $r->name_ar,
                        'quantity' => (float) $r->quantity, 'revenue' => number_format((float) $r->revenue, 3, '.', '')])->values()->all(),
                    'summary' => ['count' => $rows->count(), 'revenue' => number_format((float) $rows->sum('revenue'), 3, '.', '')]];
            })(),
            'stockouts' => (function () use ($s, $en) {
                $rows = $this->stockouts($s);

                return ['key' => 'stockouts', 'columns' => self::COLUMNS['stockouts'],
                    'rows' => $rows->map(fn ($r) => ['part_number' => $r->part_number, 'name' => $en ? ($r->name_en ?: $r->name_ar) : $r->name_ar,
                        'available' => (float) $r->free])->values()->all(),
                    'summary' => ['count' => $rows->count()]];
            })(),
            'revenue' => (function () use ($s) {
                $rows = collect($this->revenueRows($s));

                return ['key' => 'revenue', 'columns' => self::COLUMNS['revenue'],
                    'rows' => $rows->map(fn ($r) => collect($r)->except('total_raw')->all())->values()->all(),
                    'summary' => ['total' => number_format((float) $rows->sum('total_raw'), 3, '.', ''),
                        'collected' => number_format((float) $rows->sum(fn ($r) => (float) $r['collected']), 3, '.', '')]];
            })(),
        };
    }
}
