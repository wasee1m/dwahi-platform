<?php

namespace App\Modules\Service\Pdf;

use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Support\SettingsRegistry;
use App\Modules\Service\Models\WorkshopIntake;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * إيصال الاستلام ثنائي اللغة (FR-SRV-08، N-32): الرقم بخط كبير يطابق ملصق QR المطبوع من A-22، والحالة والملحقات
 * والعداد، واسم المُحضِر وتوقيعه. يُكمّل تصميمه من ورقة الشركة الحالية (Q-20).
 */
final class IntakePdf
{
    public function render(WorkshopIntake $intake): string
    {
        $company = Setting::read('company.profile', SettingsRegistry::all()['company.profile']['default']);
        $signature = $intake->signature_id ? $intake->signature()->first() : null;
        $signaturePath = $signature ? Storage::disk('local')->path($signature->storage_key) : null;

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
        $mpdf->SetTitle($intake->number);
        $mpdf->WriteHTML(view('pdf.intake', [
            'intake' => $intake, 'sr' => $intake->serviceRequest, 'company' => $company,
            'signature' => $signaturePath && is_file($signaturePath) ? $signaturePath : null,
        ])->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
