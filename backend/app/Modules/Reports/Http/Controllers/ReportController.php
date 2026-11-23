<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Models\User;
use App\Modules\Platform\Models\Branch;
use App\Modules\Platform\Support\Audit;
use App\Modules\Reports\Services\ReportService;
use App\Support\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A-01 لوحة المؤشرات و A-16 التقارير (FR-RPT-01، FR-RPT-03، US-41) */
class ReportController
{
    public function __construct(private readonly ReportService $reports) {}

    /** @return array{from: CarbonImmutable, to: CarbonImmutable, branch: string|null} */
    private function scope(Request $request): array
    {
        $f = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'uuid'],
        ]);

        return ReportService::scope($this->user($request), $f['from'] ?? null, $f['to'] ?? null, $f['branch_id'] ?? null);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (! $user->hasAbility('reports.view') && ! $user->hasAbility('reports.own')) {
            throw new ApiProblem('FORBIDDEN', 403);
        }

        return response()->json(['data' => $this->reports->dashboard($user, $this->scope($request)) + [
            // فلتر الفرع لمن يرى كل الفروع
            /** @var list<array{id: string, name: string|null}> */
            'branches' => $user->hasAbility('reports.view')
                ? Branch::query()->where('is_active', true)->orderBy('code')->get()->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->localized('name')])->values()->all()
                : [],
        ]]);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $this->allowed($request, $key);

        $report = $this->reports->report($key, $this->scope($request));
        // القيم الرمزية (الحالة والنوع...) بلغة الطلب كما في الملف المصدَّر
        $report['rows'] = array_map(fn (array $r) => collect($r)->map(fn ($v, $c) => is_bool($v) ? $v : $this->cell((string) $c, $v))->all(), $report['rows']);

        return response()->json(['data' => $report + ['headers' => $this->headers($key)]]);
    }

    /** FR-RPT-03: Excel أو CSV بالفلاتر الحالية، والعملية في سجل التدقيق */
    public function export(Request $request, string $key): StreamedResponse
    {
        $this->allowed($request, $key);
        $format = $request->validate(['format' => ['nullable', Rule::in(['csv', 'xlsx'])]])['format'] ?? 'xlsx';
        $scope = $this->scope($request);
        $report = $this->reports->report($key, $scope);
        $headers = $this->headers($key);
        $rows = array_map(fn (array $r) => array_map(fn ($c) => $this->cell($c, $r[$c] ?? null), $report['columns']), $report['rows']);
        Audit::record('report.exported', 'report', null, null, [
            'report' => $key, 'format' => $format, 'from' => $scope['from']->toDateString(), 'to' => $scope['to']->toDateString(),
            'branch_id' => $scope['branch'], 'rows' => count($rows),
        ], $this->user($request));
        $name = "{$key}-{$scope['from']->timezone('Asia/Muscat')->toDateString()}-{$scope['to']->timezone('Asia/Muscat')->toDateString()}.{$format}";

        return response()->streamDownload(function () use ($format, $headers, $rows) {
            if ($format === 'csv') {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF"); // ليفتح Excel العربية صحيحة
                fputcsv($out, array_values($headers), ',', '"', '');
                foreach ($rows as $r) {
                    fputcsv($out, $r, ',', '"', '');
                }
                fclose($out);

                return;
            }
            $path = tempnam(sys_get_temp_dir(), 'rpt');
            $w = new XlsxWriter;
            $w->openToFile($path);
            $w->addRow(Row::fromValues(array_values($headers)));
            foreach ($rows as $r) {
                $w->addRow(Row::fromValues($r));
            }
            $w->close();
            readfile($path);
            @unlink($path);
        }, $name, ['Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** عناوين الأعمدة بلغة الطلب */
    private function headers(string $key): array
    {
        return collect(ReportService::COLUMNS[$key])->mapWithKeys(fn ($c) => [$c => __("reports.columns.{$c}")])->all();
    }

    /** قيم الرموز المعروفة بلغة الطلب في الملف المصدَّر */
    private function cell(string $column, mixed $value): mixed
    {
        if (is_bool($value)) {
            return __($value ? 'reports.yes' : 'reports.no');
        }
        if (is_string($value) && in_array($column, ['status', 'type', 'source', 'urgency', 'line'], true)) {
            $t = "reports.values.{$column}.{$value}";

            return __($t) === $t ? $value : __($t);
        }

        return $value;
    }

    private function allowed(Request $request, string $key): void
    {
        abort_unless(in_array($key, ReportService::REPORTS, true), 404);
        if (! ReportService::canReport($this->user($request), $key)) {
            throw new ApiProblem('FORBIDDEN', 403);
        }
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
