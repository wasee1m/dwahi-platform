<?php

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Platform\Services\Uploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** POST /admin/uploads: صور الكتالوج والبنرات وملفات المواصفات (NFR-11: صورة ≤ 10MB) */
class UploadController
{
    public function store(Request $request, Uploads $uploads): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'visibility' => ['sometimes', Rule::in(['public', 'private'])],
        ]);
        $attachment = $uploads->store($request->file('file'), $data['visibility'] ?? 'public', $request->user());

        return response()->json(['data' => [
            'id' => $attachment->id,
            'url' => $attachment->url(),
            'mime_type' => $attachment->mime_type,
            'width' => $attachment->width,
            'height' => $attachment->height,
        ]], 201);
    }
}
