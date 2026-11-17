<?php

namespace App\Modules\Platform\Models;

use App\Support\Model;
use Illuminate\Support\Facades\Storage;

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
}
