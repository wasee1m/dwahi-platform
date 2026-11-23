<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Models\User;
use App\Modules\Billing\Http\InvoicePresenter;
use App\Modules\Billing\Models\Invoice;
use App\Support\OffsetCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** فواتير العميل (C-18، M-23، US-22): الصادرة فقط، وفواتير الشركة لكل أعضائها */
class InvoiceController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['unpaid', 'paid', 'void'])],
            'account' => ['nullable', 'string', 'max:36'],
            'cursor' => ['nullable', 'string', 'max:50'],
        ]);
        $offset = OffsetCursor::decode($f['cursor'] ?? null);
        $rows = Invoice::query()->visibleTo($user)->forAccount($f['account'] ?? null)->where('status', '!=', 'draft')
            ->when($f['status'] ?? null, fn ($q, $s) => $q->whereIn('status', match ($s) {
                'unpaid' => Invoice::UNPAID, 'paid' => ['paid'], default => ['void'],
            }))
            ->with(InvoicePresenter::WITH)->orderByDesc('issue_date')->orderByDesc('number')->offset($offset)->limit(21)->get();

        return response()->json([
            'data' => $rows->take(20)->map(fn (Invoice $i) => InvoicePresenter::row($i))->values(),
            'next_cursor' => $rows->count() > 20 ? OffsetCursor::encode($offset + 20) : null,
        ]);
    }

    public function pdf(Request $request, string $id): StreamedResponse
    {
        abort_unless(Str::isUuid($id), 404);
        $invoice = Invoice::query()->visibleTo($this->user($request))->where('status', '!=', 'draft')->with('pdf')->findOrFail($id);
        abort_unless($invoice->pdf, 404);

        return Storage::disk('local')->download($invoice->pdf->storage_key, "{$invoice->number}.pdf", ['Content-Type' => 'application/pdf']);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
