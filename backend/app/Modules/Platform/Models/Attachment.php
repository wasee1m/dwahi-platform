<?php

namespace App\Modules\Platform\Models;

use App\Support\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class Attachment extends Model
{
    public const UPDATED_AT = null;

    /** الملفات العامة (صور الكتالوج) برابط مباشر، والخاصة بروابط موقعة لاحقاً (04 §8.2) */
    public function url(): ?string
    {
        if ($this->visibility !== 'public' || $this->scan_status !== 'clean') {
            return null;
        }

        return Storage::disk('public')->url($this->storage_key);
    }

    /**
     * رابط للعرض أو التنزيل: العام مباشر، والخاص موقّع صالح 15 دقيقة (04 §8.2). يُعطى فقط لمن يرى السجل
     * المرتبط به (الطلب، الرسالة)، فالتحقق من الصلاحية في الواجهة التي تُرجع الرابط.
     * مؤقت حتى Q-12: في الإنتاج روابط S3 الموقعة بنفس المدة.
     */
    public function accessUrl(): ?string
    {
        if ($this->scan_status !== 'clean') {
            return null;
        }

        return $this->visibility === 'public'
            ? $this->url()
            : URL::temporarySignedRoute('files.show', now()->addMinutes(15), ['id' => $this->id]);
    }

    /** @return array{id: string, name: string|null, mime_type: string, url: string|null} */
    public function summary(): array
    {
        return ['id' => $this->id, 'name' => $this->original_name, 'mime_type' => $this->mime_type, 'url' => $this->accessUrl()];
    }
}
