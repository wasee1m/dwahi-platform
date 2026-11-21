<?php

namespace App\Modules\Parts\Http\Controllers\Admin;

use App\Modules\Parts\Services\PartImport;
use App\Modules\Platform\Support\Audit;
use App\Support\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** استيراد القطع من Excel مع المعاينة (US-37) */
class PartImportController
{
    private const TTL = 1800;

    /** POST /admin/parts/import/preview */
    public function preview(Request $request, PartImport $import): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:xlsx,csv,txt']]);
        $result = $import->preview($request->file('file'));

        $token = Str::random(40);
        Cache::put("part-import:{$token}", ['user_id' => $request->user()->id, 'rows' => $result['rows']], self::TTL);

        return response()->json(['data' => [
            'token' => $result['summary']['total'] > 0 ? $token : null,
            'summary' => $result['summary'],
            // أول 200 صف للعرض، والأخطاء كلها
            'rows' => array_values(array_merge(
                array_filter($result['rows'], fn ($r) => $r['status'] === 'error'),
                array_slice(array_values(array_filter($result['rows'], fn ($r) => $r['status'] !== 'error')), 0, 200),
            )),
        ]]);
    }

    /** POST /admin/parts/import/commit */
    public function commit(Request $request, PartImport $import): JsonResponse
    {
        $token = (string) $request->validate(['token' => ['required', 'string', 'size:40']])['token'];
        $pending = Cache::get("part-import:{$token}");
        if (! $pending || $pending['user_id'] !== $request->user()->id) {
            throw new ApiProblem('IMPORT_EXPIRED', 410);
        }
        if (collect($pending['rows'])->contains('status', 'error')) {
            throw new ApiProblem('IMPORT_HAS_ERRORS', 422);
        }

        $result = $import->apply($pending['rows']);
        Cache::forget("part-import:{$token}");
        Audit::record('parts.imported', 'part', null, null, $result);

        return response()->json(['data' => $result]);
    }

    /** GET /admin/parts/import/template: CSV بترميز UTF-8 يفتحه Excel بالعربية */
    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, PartImport::COLUMNS);
            fputcsv($out, ['Caterpillar', '1R-0750', 'فلتر ديزل', 'Fuel filter', '6.500', '5', 'piece', 'filters', '', 'yes', 'ALT-1;ALT-2', '', '']);
            fclose($out);
        }, 'parts-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
