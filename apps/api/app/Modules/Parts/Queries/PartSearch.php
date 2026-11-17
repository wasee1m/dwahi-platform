<?php

namespace App\Modules\Parts\Queries;

use App\Modules\Parts\Models\Part;
use App\Modules\Parts\Support\PartNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * البحث برقم القطعة أو اسمها (FR-PRT-01، US-03، ADR-09). الترتيب:
 * 0 مطابقة تامة للرقم، 1 بادئة الرقم، 2 رقم بديل، 3 جزء من الرقم أو الاسم (FULLTEXT ngram).
 */
final class PartSearch
{
    private const LIMIT = 30;

    /** @return Collection<int, Part> كل قطعة ومعها matched_alternative إن طابقت رقماً بديلاً */
    public function run(string $q): Collection
    {
        $q = trim($q);
        $norm = PartNumber::normalize($q);
        $ranks = []; // part_id => [rank, matched_alternative]

        if (strlen($norm) >= 2) {
            $direct = DB::table('parts')->where('is_active', true)
                ->where('part_number_normalized', 'like', $norm.'%')
                ->limit(self::LIMIT)->pluck('part_number_normalized', 'id');
            foreach ($direct as $id => $num) {
                $ranks[$id] = [$num === $norm ? 0 : 1, null];
            }

            $alts = DB::table('part_cross_references')
                ->where('alt_number_normalized', 'like', $norm.'%')
                ->limit(self::LIMIT)->get(['part_id', 'alt_number']);
            foreach ($alts as $alt) {
                $ranks[$alt->part_id] ??= [2, $alt->alt_number];
            }
        }

        $phrase = trim((string) preg_replace('/[+\-<>()~*"@]+/u', ' ', $q));
        if (mb_strlen($phrase) >= 2) {
            $ft = DB::table('parts')->where('is_active', true)
                ->whereRaw('MATCH(part_number_normalized, name_ar, name_en) AGAINST (? IN BOOLEAN MODE)', ['"'.$phrase.'"'])
                ->limit(self::LIMIT)->pluck('id');
            foreach ($ft as $id) {
                $ranks[$id] ??= [3, null];
            }
        }

        if ($ranks === []) {
            return collect();
        }

        $parts = Part::query()->whereIn('id', array_keys($ranks))->where('is_active', true)
            ->with(['brand', 'media'])->get()->keyBy('id');

        return collect($ranks)
            ->sortBy(fn ($r, $id) => sprintf('%d-%s', $r[0], $parts[$id]->part_number_normalized ?? ''))
            ->filter(fn ($r, $id) => isset($parts[$id]))
            ->take(self::LIMIT)
            ->map(function ($r, $id) use ($parts) {
                $part = $parts[$id];
                $part->setAttribute('matched_alternative', $r[1]);

                return $part;
            })->values();
    }
}
