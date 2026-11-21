<?php

namespace App\Modules\Platform\Services;

use App\Models\User;
use App\Modules\Platform\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * رفع الملفات (NFR-11): النوع من المحتوى الحقيقي لا من الامتداد، والصور يُعاد ترميزها فتُزال بياناتها
 * الوصفية (EXIF والموقع) ويُصغَّر الضلع الأطول إلى 2400px.
 *
 * مؤقت حتى قرار الاستضافة (Q-12): الرفع يمر عبر الـ API إلى التخزين المحلي. في الإنتاج يصبح الرفع
 * مباشراً إلى S3 بروابط موقعة (04 §8.2) بتبديل هذه الطبقة وحدها. فحص البرمجيات الخبيثة يُفعَّل مع مزوده.
 */
final class Uploads
{
    public const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

    public const DOC_TYPES = ['application/pdf' => 'pdf'];

    private const MAX_SIDE = 2400;

    public function store(UploadedFile $file, string $visibility, ?User $by): Attachment
    {
        $mime = (string) $file->getMimeType(); // finfo على المحتوى
        $ext = self::IMAGE_TYPES[$mime] ?? self::DOC_TYPES[$mime] ?? null;
        if (! $ext) {
            throw ValidationException::withMessages(['file' => __('validation.mimetypes', ['attribute' => 'file', 'values' => 'jpg, png, pdf'])]);
        }

        [$bytes, $width, $height] = isset(self::IMAGE_TYPES[$mime])
            ? $this->reencode($file->getRealPath(), $mime)
            : [(string) file_get_contents($file->getRealPath()), null, null];

        $key = sprintf('uploads/%s/%s.%s', now()->format('Y/m'), Str::uuid7(), $ext);
        Storage::disk($visibility === 'public' ? 'public' : 'local')->put($key, $bytes);

        return Attachment::query()->create([
            'storage_key' => $key,
            'original_name' => substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => $mime,
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'width' => $width,
            'height' => $height,
            'visibility' => $visibility,
            'scan_status' => config('services.antivirus.driver', 'none') === 'none' ? 'clean' : 'pending',
            'uploaded_by' => $by?->id,
            'created_at' => now(),
        ]);
    }

    /** ملف يولده النظام (PDF العروض والفواتير): لا يحتاج فحصاً، ويُحفظ خاصاً */
    public function storeGenerated(string $bytes, string $mime, string $name, ?User $by = null): Attachment
    {
        $ext = self::DOC_TYPES[$mime] ?? 'bin';
        $key = sprintf('generated/%s/%s.%s', now()->format('Y/m'), Str::uuid7(), $ext);
        Storage::disk('local')->put($key, $bytes);

        return Attachment::query()->create([
            'storage_key' => $key, 'original_name' => substr($name, 0, 255), 'mime_type' => $mime,
            'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'visibility' => 'private',
            'scan_status' => 'clean', 'uploaded_by' => $by?->id, 'created_at' => now(),
        ]);
    }

    /** @return array{0: string, 1: int, 2: int} */
    private function reencode(string $path, string $mime): array
    {
        $src = $mime === 'image/png' ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
        if (! $src) {
            throw ValidationException::withMessages(['file' => __('validation.image', ['attribute' => 'file'])]);
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, self::MAX_SIDE / max($w, $h));
        $nw = (int) round($w * $scale);
        $nh = (int) round($h * $scale);

        $dst = imagecreatetruecolor($nw, $nh);
        if ($mime === 'image/png') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        $mime === 'image/png' ? imagepng($dst, null, 6) : imagejpeg($dst, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        return [$bytes, $nw, $nh];
    }
}
