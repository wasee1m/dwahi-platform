<?php

namespace App\Modules\Parts\Services;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Parts\Models\Part;
use App\Modules\Parts\Support\PartNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * استيراد القطع من Excel أو CSV (FR-PRT-04، US-37، 01 §10.2):
 * 1) المعاينة: كل صف جديد أو محدّث أو خطأ (رقم مكرر في الملف، سعر غير صالح، ماركة غير معروفة...).
 * 2) التطبيق بعد التأكيد فقط، كاملاً أو لا شيء (معاملة واحدة).
 */
final class PartImport
{
    public const MAX_ROWS = 5000;

    /** أعمدة القالب. alt_numbers مفصولة بفاصلة منقوطة */
    public const COLUMNS = ['brand', 'part_number', 'name_ar', 'name_en', 'price', 'vat_rate', 'unit', 'category',
        'warranty_months', 'is_returnable', 'alt_numbers', 'description_ar', 'description_en'];

    private const UNITS = ['piece', 'set', 'liter', 'meter', 'kg'];

    /**
     * @return array{rows: list<array<string, mixed>>, summary: array{new: int, updated: int, errors: int, total: int}}
     */
    public function preview(UploadedFile $file): array
    {
        $raw = $this->read($file);
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($raw) ?? []);
        $missing = array_diff(['brand', 'part_number', 'name_ar', 'name_en', 'price'], $header);
        if ($missing) {
            return ['rows' => [['line' => 1, 'status' => 'error', 'errors' => ['missing columns: '.implode(', ', $missing)]]],
                'summary' => ['new' => 0, 'updated' => 0, 'errors' => 1, 'total' => 0]];
        }

        $brands = Brand::query()->get(['id', 'name', 'slug']);
        $brandBy = fn (string $v) => $brands->first(fn ($b) => strcasecmp($b->name, $v) === 0 || strcasecmp($b->slug, $v) === 0);
        $existing = Part::query()->get(['id', 'brand_id', 'part_number_normalized'])
            ->keyBy(fn ($p) => $p->brand_id.'|'.$p->part_number_normalized);

        $rows = [];
        $seen = [];
        foreach (array_slice($raw, 0, self::MAX_ROWS) as $i => $cells) {
            $r = [];
            foreach ($header as $col => $name) {
                $r[$name] = trim((string) ($cells[$col] ?? ''));
            }
            if (implode('', $r) === '') {
                continue; // صف فارغ
            }
            $errors = [];
            $brand = $r['brand'] !== '' ? $brandBy($r['brand']) : null;
            if (! $brand) {
                $errors[] = 'unknown brand';
            }
            $norm = PartNumber::normalize($r['part_number'] ?? '');
            if ($norm === '') {
                $errors[] = 'part_number required';
            }
            foreach (['name_ar', 'name_en'] as $f) {
                if (($r[$f] ?? '') === '') {
                    $errors[] = "{$f} required";
                }
            }
            $price = str_replace([',', ' '], '', $r['price'] ?? '');
            if (! preg_match('/^\d{1,9}(\.\d{1,3})?$/', $price)) {
                $errors[] = 'invalid price';
            }
            $vat = ($r['vat_rate'] ?? '') === '' ? '5.00' : $r['vat_rate'];
            if (! is_numeric($vat) || $vat < 0 || $vat > 100) {
                $errors[] = 'invalid vat_rate';
            }
            $unit = strtolower($r['unit'] ?? '') ?: 'piece';
            if (! in_array($unit, self::UNITS, true)) {
                $errors[] = 'invalid unit';
            }
            $warranty = ($r['warranty_months'] ?? '') === '' ? null : $r['warranty_months'];
            if ($warranty !== null && ! ctype_digit((string) $warranty)) {
                $errors[] = 'invalid warranty_months';
            }
            $key = ($brand?->id ?? '?').'|'.$norm;
            if ($norm !== '' && isset($seen[$key])) {
                $errors[] = 'duplicate in file (line '.$seen[$key].')';
            }
            $seen[$key] ??= $i + 2;

            $rows[] = [
                'line' => $i + 2,
                'status' => $errors ? 'error' : (isset($existing[$key]) ? 'update' : 'new'),
                'errors' => $errors,
                'part_id' => $existing[$key]->id ?? null,
                'values' => $errors ? $r : [
                    'brand_id' => $brand->id,
                    'part_number' => $r['part_number'],
                    'name_ar' => $r['name_ar'],
                    'name_en' => $r['name_en'],
                    'price' => number_format((float) $price, 3, '.', ''),
                    'vat_rate' => number_format((float) $vat, 2, '.', ''),
                    'unit' => $unit,
                    'category' => ($r['category'] ?? '') ?: null,
                    'warranty_months' => $warranty === null ? null : (int) $warranty,
                    'is_returnable' => ! in_array(strtolower($r['is_returnable'] ?? ''), ['0', 'no', 'false', 'لا'], true),
                    'description_ar' => ($r['description_ar'] ?? '') ?: null,
                    'description_en' => ($r['description_en'] ?? '') ?: null,
                    'alt_numbers' => array_values(array_filter(array_map('trim', explode(';', $r['alt_numbers'] ?? '')))),
                ],
            ];
        }

        $count = fn ($s) => count(array_filter($rows, fn ($r) => $r['status'] === $s));

        return ['rows' => $rows, 'summary' => ['new' => $count('new'), 'updated' => $count('update'), 'errors' => $count('error'), 'total' => count($rows)]];
    }

    /** يطبق صفوف معاينة بلا أخطاء، كاملة أو لا شيء */
    public function apply(array $rows): array
    {
        return DB::transaction(function () use ($rows) {
            $created = $updated = 0;
            foreach ($rows as $row) {
                $v = $row['values'];
                $fields = collect($v)->except('alt_numbers')->all();
                if ($row['part_id']) {
                    Part::query()->whereKey($row['part_id'])->update($fields);
                    $partId = $row['part_id'];
                    $updated++;
                } else {
                    $partId = Part::query()->create($fields + ['is_active' => true])->id;
                    $created++;
                }
                if ($v['alt_numbers'] !== []) {
                    DB::table('part_cross_references')->where('part_id', $partId)->delete();
                    $seen = [];
                    foreach ($v['alt_numbers'] as $n) {
                        $norm = PartNumber::normalize($n);
                        if ($norm !== '' && ! isset($seen[$norm])) {
                            $seen[$norm] = true;
                            DB::table('part_cross_references')->insert(['id' => (string) Str::uuid7(), 'part_id' => $partId, 'alt_number' => $n, 'alt_brand' => null]);
                        }
                    }
                }
            }

            return ['created' => $created, 'updated' => $updated];
        });
    }

    /** @return list<list<mixed>> */
    private function read(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $reader = $ext === 'csv' ? new CsvReader : new XlsxReader;
        $reader->open($file->getRealPath());
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = $row->toArray();
                if ($rows === [] && isset($cells[0]) && is_string($cells[0])) {
                    $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]); // BOM في CSV المحفوظ من Excel
                }
                $rows[] = $cells;
                if (count($rows) > self::MAX_ROWS + 1) {
                    break 2;
                }
            }
            break; // الورقة الأولى فقط
        }
        $reader->close();

        return $rows;
    }
}
