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

    /** فيديو العطل والتسجيل الصوتي (FR-SRV-01): للملفات الخاصة فقط، وحتى 100MB (NFR-11) */
    public const MEDIA_TYPES = [
        'video/mp4' => 'mp4', 'application/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm', 'video/3gpp' => '3gp',
        'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/aac' => 'aac', 'audio/ogg' => 'ogg',
        'audio/webm' => 'webm', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/3gpp' => '3gp',
    ];

    /** الصور والمستندات حتى 10MB، والفيديو والصوت حتى 100MB (NFR-11) */
    private const MAX_BYTES = 10 * 1024 * 1024;

    private const MAX_MEDIA_BYTES = 100 * 1024 * 1024;

    /** أنواع يولدها النظام ولا يقبلها الرفع */
    private const GENERATED_TYPES = ['application/json' => 'json'];

    private const MAX_SIDE = 2400;

    public function store(UploadedFile $file, string $visibility, ?User $by): Attachment
    {
        $mime = (string) $file->getMimeType(); // finfo على المحتوى
        $media = $visibility === 'private' && isset(self::MEDIA_TYPES[$mime]);
        $ext = self::IMAGE_TYPES[$mime] ?? self::DOC_TYPES[$mime] ?? ($media ? self::MEDIA_TYPES[$mime] : null);
        if (! $ext) {
            throw ValidationException::withMessages(['file' => __('validation.mimetypes', ['attribute' => 'file', 'values' => $visibility === 'private' ? 'jpg, png, pdf, mp4, mov, m4a, mp3, ogg, webm' : 'jpg, png, pdf'])]);
        }
        if ((int) $file->getSize() > ($media ? self::MAX_MEDIA_BYTES : self::MAX_BYTES)) {
            throw ValidationException::withMessages(['file' => __('validation.max.file', ['attribute' => 'file', 'max' => $media ? 102400 : 10240])]);
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
        $ext = self::DOC_TYPES[$mime] ?? self::GENERATED_TYPES[$mime] ?? 'bin';
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
