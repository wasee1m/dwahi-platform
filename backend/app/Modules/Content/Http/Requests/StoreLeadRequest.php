<?php

namespace App\Modules\Content\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** نموذج الاستفسار (W-09، FR-CMS-05). الجوال يُطبَّع إلى E.164، والرقم المحلي العماني يأخذ +968. */
class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $digits = preg_replace('/[^\d+]/', '', (string) $this->input('phone'));
        if (preg_match('/^[79]\d{7}$/', $digits)) {
            $digits = '+968'.$digits;          // رقم جوال عماني محلي
        } elseif (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }
        $this->merge(['phone' => $digits]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'topic' => ['required', Rule::in(['equipment', 'parts', 'service', 'other'])],
            'message' => ['required', 'string', 'max:2000'],
            'branch_code' => ['nullable', 'string', 'exists:branches,code'],
            'source_page' => ['nullable', 'string', 'max:500'],
            'website' => ['nullable', 'string'], // حقل مخفي للروبوتات (NFR-09)
        ];
    }
}
