<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Models\User;
use App\Modules\Platform\Models\Attachment;
use App\Modules\Platform\Services\Uploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController
{
    /** GET /files/{id} برابط موقّع (middleware signed): الملفات الخاصة لمن أُعطي الرابط */
    public function show(string $id): StreamedResponse
    {
        abort_unless(Str::isUuid($id), 404);
        $a = Attachment::query()->where('scan_status', 'clean')->findOrFail($id);
        $disk = Storage::disk($a->visibility === 'public' ? 'public' : 'local');
        abort_unless($disk->exists($a->storage_key), 404);

        return $disk->response($a->storage_key, $a->original_name, [
            'Content-Type' => $a->mime_type,
            'Cache-Control' => 'private, max-age=900',
            'X-Content-Type-Options' => 'nosniff',
        ], $a->mime_type === 'application/json' ? 'attachment' : 'inline');
    }

    /** POST /me/uploads: صور القطع والسلندر وأمر الشراء ومرفقات الرسائل. خاصة دائماً (NFR-11). */
    public function upload(Request $request, Uploads $uploads): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:102400']]); // الحد لكل نوع في Uploads
        /** @var User $user */
        $user = $request->user();
        $a = $uploads->store($request->file('file'), 'private', $user);

        return response()->json(['data' => [...$a->summary(), 'width' => $a->width, 'height' => $a->height]], 201);
    }
}
