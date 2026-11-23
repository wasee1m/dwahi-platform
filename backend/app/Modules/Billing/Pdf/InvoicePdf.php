<?php

namespace App\Modules\Billing\Pdf;

use App\Modules\Billing\Models\CreditNote;
use App\Modules\Billing\Models\Invoice;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * الفاتورة الضريبية والإشعار الدائن ثنائيا اللغة (FR-PAY-03، US-22): الرقمان الضريبيان للبائع والمشتري،
 * وتفصيل الضريبة لكل سطر، وتاريخ انتهاء الضمان (FR-CAT-07). يُحفظ المستند الصادر ولا يُعاد توليده.
 */
final class InvoicePdf
{
    public function invoice(Invoice $invoice): string
    {
        $invoice->loadMissing(['items', 'salesOrder', 'quotation']);

        return $this->render(view('pdf.invoice', ['inv' => $invoice, 'note' => null])->render(), (string) $invoice->number);
    }

    public function creditNote(CreditNote $note): string
    {
        $note->loadMissing('invoice.items');

        return $this->render(view('pdf.invoice', ['inv' => $note->invoice, 'note' => $note])->render(), $note->number);
    }

    private function render(string $html, string $title): string
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
        $mpdf->SetFooter('{PAGENO} / {nbpg}');
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
