<?php

namespace App\Modules\Quoting\Pdf;

use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Support\SettingsRegistry;
use App\Modules\Quoting\Models\Quotation;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * PDF عرض السعر ثنائي اللغة (FR-RFQ-02). mPDF بدل Browsershot (قرار 2026-11-22): يدعم تشكيل العربية
 * واتجاه RTL دون Chromium في الحاوية. المستند المرسل يُحفظ ولا يُعاد توليده (04 §8.2).
 */
final class QuotationPdf
{
    public function render(Quotation $q, bool $preview = false): string
    {
        $q->loadMissing(['items', 'rfq.customer', 'rfq.organization', 'sender']);
        $company = Setting::read('company.profile', SettingsRegistry::all()['company.profile']['default']);

        $html = view('pdf.quotation', ['q' => $q, 'rfq' => $q->rfq, 'company' => $company, 'preview' => $preview])->render();

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
        $mpdf->SetTitle($q->number ?? 'draft');
        $mpdf->SetFooter('{PAGENO} / {nbpg}');
        if ($preview) {
            $mpdf->SetWatermarkText('DRAFT');
            $mpdf->showWatermarkText = true;
        }
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
