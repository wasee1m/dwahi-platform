<?php

namespace App\Modules\Content\Http\Controllers;

use App\Modules\Content\Http\Requests\StoreLeadRequest;
use App\Modules\Content\Models\Lead;
use App\Modules\Platform\Models\Branch;
use Illuminate\Http\JsonResponse;

class LeadController
{
    /** POST /public/leads (FR-CMS-05، US-05) */
    public function store(StoreLeadRequest $request): JsonResponse
    {
        $data = $request->validated();

        // الروبوت يملأ الحقل المخفي: نرد بنجاح ولا نحفظ، فلا يعرف أنه كُشف
        if (! empty($data['website'])) {
            return response()->json(['data' => ['status' => 'received']], 201);
        }

        Lead::query()->create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'company' => $data['company'] ?? null,
            'topic' => $data['topic'],
            'message' => $data['message'],
            'source_page' => $data['source_page'] ?? null,
            'branch_id' => isset($data['branch_code']) ? Branch::query()->where('code', $data['branch_code'])->value('id') : null,
            'status' => 'new',
        ]);

        return response()->json(['data' => ['status' => 'received']], 201);
    }
}
