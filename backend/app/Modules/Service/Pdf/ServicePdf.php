<?php

namespace App\Modules\Service\Pdf;

use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Platform\Models\Attachment;
use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Support\SettingsRegistry;
use App\Modules\Service\Models\ServiceRequest;
use App\Modules\Service\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * تقرير الخدمة (FR-SRV-05) وإيصال التسليم (FR-SRV-09) ثنائيا اللغة، على نمط إيصال الاستلام. يُكمّل التصميم من ورقة الشركة (Q-20).
 */
final class ServicePdf
{
    /** تقرير أمر العمل: الأعطال والأعمال والتوصيات، والقطع، والعداد، والاختبارات، وصور قبل وبعد */
    public function report(WorkOrder $wo): string
    {
        $report = DB::table('service_reports')->where('work_order_id', $wo->id)->first();
        $parts = DB::table('work_order_parts as l')->leftJoin('parts', 'parts.id', '=', 'l.part_id')->where('l.work_order_id', $wo->id)->whereNull('l.removed_at')
            ->orderBy('l.recorded_at')->get(['l.description', 'l.quantity', 'l.source', 'parts.part_number', 'parts.name_ar', 'parts.name_en']);
        $tests = DB::table('work_order_tests as t')->leftJoin('test_templates as tt', 'tt.id', '=', 't.template_id')->where('t.work_order_id', $wo->id)
            ->orderBy('t.tested_at')->get(['t.values', 't.result', 't.notes', 't.tested_at', 'tt.name_ar', 'tt.name_en', 'tt.fields']);
        $photos = DB::table('work_order_photos')->where('work_order_id', $wo->id)->whereIn('phase', ['before', 'after'])->orderBy('taken_at')->get();
        $files = Attachment::query()->whereIn('id', $photos->pluck('attachment_id'))->get()->keyBy('id');
        $path = function (string $phase) use ($photos, $files) {
            return $photos->where('phase', $phase)->take(4)->map(fn ($p) => $files[$p->attachment_id] ?? null)->filter()
                ->map(fn (Attachment $a) => Storage::disk('local')->path($a->storage_key))->filter(fn ($f) => is_file($f))->values()->all();
        };

        return $this->render('pdf.service_report', "{$wo->number}", [
            'wo' => $wo, 'sr' => $wo->serviceRequest, 'report' => $report, 'parts' => $parts, 'before' => $path('before'), 'after' => $path('after'),
            'tests' => $tests->map(function ($t) {
                $t->values = json_decode((string) $t->values, true) ?: [];
                $t->fields = json_decode((string) $t->fields, true) ?: [];

                return $t;
            }),
        ]);
    }

    public function handover(ServiceRequest $sr, object $handover, ?Invoice $invoice, ?Payment $payment, User $staff): string
    {
        $signature = Attachment::query()->find($handover->signature_id);
        $signaturePath = $signature ? Storage::disk('local')->path($signature->storage_key) : null;

        return $this->render('pdf.handover', "{$sr->number}", [
            'sr' => $sr, 'handover' => $handover, 'invoice' => $invoice, 'payment' => $payment, 'staff' => $staff,
            'signature' => $signaturePath && is_file($signaturePath) ? $signaturePath : null,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function render(string $view, string $title, array $data): string
    {
        $dir = storage_path('app/mpdf');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $mpdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $dir,
            'default_font' => 'dejavusans', 'autoScriptToLang' => true, 'autoLangToFont' => true,
            'margin_top' => 14, 'margin_bottom' => 16, 'margin_left' => 12, 'margin_right' => 12,
        ]);
        $mpdf->SetDirectionality('rtl');
        $mpdf->SetTitle($title);
        $mpdf->WriteHTML(view($view, $data + ['company' => Setting::read('company.profile', SettingsRegistry::all()['company.profile']['default'])])->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
