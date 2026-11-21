<?php

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Platform\Models\Branch;
use App\Modules\Platform\Support\Audit;
use App\Support\Governorates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** A-18 الفروع (FR-ADM-02): البيانات والموقع وساعات العمل وأرقام الأقسام والمحافظات المغطاة */
class BranchAdminController
{
    private const DAYS = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'];

    private const PHONES = ['phone', 'whatsapp_sales', 'whatsapp_service', 'whatsapp_parts'];

    public function index(): JsonResponse
    {
        return response()->json(['data' => Branch::query()->withCoordinates()->orderBy('code')->get()->map(fn (Branch $b) => $this->detail($b))->values()]);
    }

    public function save(Request $request, ?string $id = null): JsonResponse
    {
        if ($id) {
            abort_unless(Str::isUuid($id), 404);
        }
        $branch = $id ? Branch::query()->findOrFail($id) : new Branch;
        // الهاتف الأرضي مقبول للفرع، فالتطبيع هنا أخف من أرقام الحسابات
        $request->merge(collect(self::PHONES)->mapWithKeys(fn ($k) => [$k => self::phone($request->input($k))])->all());

        $time = ['required', 'date_format:H:i'];
        $data = $request->validate([
            'code' => [$id ? 'prohibited' : 'required', 'string', 'regex:/^[A-Z0-9]{2,20}$/', Rule::unique('branches', 'code')],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'governorate' => ['required', Rule::in(Governorates::ALL)],
            'wilayat' => ['required', 'string', 'max:50'],
            'address_ar' => ['nullable', 'string', 'max:1000'],
            'address_en' => ['nullable', 'string', 'max:1000'],
            // حدود السلطنة تقريباً: خطأ شائع هو تبديل خط العرض بخط الطول
            'latitude' => ['required', 'numeric', 'between:16,27'],
            'longitude' => ['required', 'numeric', 'between:51,60'],
            ...collect(self::PHONES)->mapWithKeys(fn ($k) => [$k => ['nullable', 'string', 'regex:/^\+\d{8,15}$/']])->all(),
            'email' => ['nullable', 'email', 'max:255'],
            'working_hours' => ['required', 'array:'.implode(',', self::DAYS)],
            ...collect(self::DAYS)->flatMap(fn ($d) => [
                "working_hours.{$d}" => ['nullable', 'array:open,close'],
                "working_hours.{$d}.open" => ["required_with:working_hours.{$d}", ...array_slice($time, 1)],
                "working_hours.{$d}.close" => ["required_with:working_hours.{$d}", ...array_slice($time, 1), "after:working_hours.{$d}.open"],
            ])->all(),
            'covered_governorates' => ['required', 'array', 'min:1'],
            'covered_governorates.*' => [Rule::in(Governorates::ALL), 'distinct'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $before = $branch->exists ? $branch->only(['name_ar', 'is_active', 'covered_governorates']) : null;
        $fields = collect($data)->except(['latitude', 'longitude'])->all();
        $fields['working_hours'] = collect(self::DAYS)->mapWithKeys(fn ($d) => [$d => $data['working_hours'][$d] ?? null])->all();
        $fields['location'] = Branch::point((float) $data['latitude'], (float) $data['longitude']);
        $fields['is_active'] = (bool) ($data['is_active'] ?? true);
        $branch->fill($fields)->save();
        Audit::record($id ? 'branch.updated' : 'branch.created', 'branch', $branch->id, $before, $branch->only(['name_ar', 'is_active', 'covered_governorates']));

        return response()->json(['data' => $this->detail(Branch::query()->withCoordinates()->findOrFail($branch->id))], $id ? 200 : 201);
    }

    private static function phone(mixed $input): ?string
    {
        $digits = (string) preg_replace('/[^\d+]/', '', (string) $input);
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }

        return strlen($digits) === 8 && ! str_starts_with($digits, '+') ? '+968'.$digits : $digits;
    }

    private function detail(Branch $b): array
    {
        return [
            ...$b->only(['id', 'code', 'name_ar', 'name_en', 'governorate', 'wilayat', 'address_ar', 'address_en', 'phone',
                'whatsapp_sales', 'whatsapp_service', 'whatsapp_parts', 'email', 'working_hours', 'covered_governorates', 'is_active']),
            'latitude' => $b->latitude,
            'longitude' => $b->longitude,
        ];
    }
}
