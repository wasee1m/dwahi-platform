<?php

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Parts\Models\Part;
use App\Modules\Parts\Support\Availability;
use App\Modules\Quoting\Support\QuoteMath;
use App\Support\ApiProblem;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * السلة (FR-ORD-01، C-10، M-11): واحدة لكل مستخدم عبر الأجهزة. السعر والتوفر يُفحصان عند كل فتح،
 * وتغير السعر يحتاج تأكيد العميل قبل إتمام الطلب (US-14، 02 §10).
 */
final class CartService
{
    public const MAX_LINES = 50;

    public const MAX_QTY = 999;

    public function cartId(User $user): string
    {
        $id = DB::table('carts')->where('user_id', $user->id)->value('id');
        if ($id) {
            return $id;
        }
        $id = (string) Str::uuid7();
        DB::table('carts')->insertOrIgnore(['id' => $id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        return (string) DB::table('carts')->where('user_id', $user->id)->value('id');
    }

    /** الكمية 0 تحذف البند. السعر المرئي يُحدَّث عند كل إضافة أو تعديل. */
    public function set(User $user, string $partId, string $quantity): void
    {
        $cart = $this->cartId($user);
        if (bccomp($quantity, '0', 2) <= 0) {
            DB::table('cart_items')->where('cart_id', $cart)->where('part_id', $partId)->delete();

            return;
        }
        $part = Part::query()->where('is_active', true)->whereKey($partId)->first();
        if (! $part) {
            throw new ApiProblem('NOT_FOUND', 404);
        }
        $exists = DB::table('cart_items')->where('cart_id', $cart)->where('part_id', $partId)->exists();
        if (! $exists && DB::table('cart_items')->where('cart_id', $cart)->count() >= self::MAX_LINES) {
            throw new ApiProblem('CART_FULL', 422, null, ['max' => self::MAX_LINES]);
        }
        DB::table('cart_items')->upsert(
            [['cart_id' => $cart, 'part_id' => $partId, 'quantity' => $quantity, 'seen_price' => $part->price, 'created_at' => now(), 'updated_at' => now()]],
            ['cart_id', 'part_id'], ['quantity', 'seen_price', 'updated_at'],
        );
        DB::table('carts')->where('id', $cart)->update(['updated_at' => now()]);
    }

    /** "وافقت على الأسعار الجديدة": السعر المرئي يصبح الحالي لكل البنود */
    public function acknowledge(User $user): void
    {
        $cart = $this->cartId($user);
        DB::table('cart_items')->join('parts', 'parts.id', '=', 'cart_items.part_id')->where('cart_items.cart_id', $cart)
            ->update(['cart_items.seen_price' => DB::raw('parts.price'), 'cart_items.updated_at' => now()]);
    }

    public function clear(User $user): void
    {
        DB::table('cart_items')->where('cart_id', $this->cartId($user))->delete();
    }

    /**
     * السلة كما تُعرض الآن: السعر الحالي، والتنبيه، والمجاميع (BR-01).
     *
     * @return array{items: list<array<string, mixed>>, totals: array<string, array{amount: string, currency: string}|null>, has_changes: bool, can_checkout: bool}
     */
    public function view(User $user): array
    {
        $rows = DB::table('cart_items')->where('cart_id', $this->cartId($user))->orderBy('created_at')->get();
        $parts = Part::query()->with(['brand', 'media'])->whereIn('id', $rows->pluck('part_id'))->get()->keyBy('id');
        $availability = Availability::forParts($rows->pluck('part_id')->all());
        $free = $this->freeStock($rows->pluck('part_id')->all());

        $items = [];
        $lines = [];
        $changes = false;
        foreach ($rows as $row) {
            $part = $parts[$row->part_id] ?? null;
            if (! $part) {
                continue;
            }
            $problem = match (true) {
                ! $part->is_active => 'inactive',
                bccomp((string) ($free[$part->id] ?? '0'), (string) $row->quantity, 2) < 0 => 'insufficient',
                default => null,
            };
            $priceChanged = bccomp((string) $row->seen_price, (string) $part->price, 3) !== 0;
            $changes = $changes || $priceChanged || $problem !== null;
            $line = QuoteMath::line((string) $row->quantity, (string) $part->price, '0', (string) $part->vat_rate);
            if (! $problem) {
                $lines[] = $line + ['quantity' => $row->quantity, 'unit_price' => $part->price, 'discount_amount' => '0'];
            }
            $items[] = [
                'part' => [
                    'id' => $part->id, 'part_number' => $part->part_number, 'name' => $part->localized('name'),
                    'brand' => $part->brand->name, 'unit' => $part->unit, 'is_returnable' => $part->is_returnable,
                    'availability' => $availability[$part->id] ?? 'on_request', 'cover_image_url' => $part->media->first()?->url(),
                ],
                'quantity' => (string) $row->quantity,
                'unit_price' => Money::of((string) $part->price),
                'previous_price' => $priceChanged ? Money::of((string) $row->seen_price) : null,
                'problem' => $problem,
                'line_subtotal' => Money::of($line['line_subtotal']),
            ];
        }
        $t = QuoteMath::totals($lines);

        return [
            'items' => $items,
            'totals' => ['subtotal' => Money::of($t['subtotal']), 'vat_total' => Money::of($t['vat_total']), 'total' => Money::of($t['total'])],
            'has_changes' => $changes,
            'can_checkout' => $items !== [] && ! $changes,
        ];
    }

    /**
     * @param  list<string>  $partIds
     * @return array<string, string> المتاح في الفروع النشطة
     */
    public function freeStock(array $partIds, ?string $branchId = null): array
    {
        if ($partIds === []) {
            return [];
        }

        return DB::table('stock_levels')
            ->join('stock_locations', 'stock_locations.id', '=', 'stock_levels.location_id')
            ->join('branches', 'branches.id', '=', 'stock_locations.branch_id')
            ->where('branches.is_active', true)
            ->when($branchId, fn ($q) => $q->where('branches.id', $branchId))
            ->whereIn('stock_levels.part_id', $partIds)
            ->groupBy('stock_levels.part_id')
            ->selectRaw('stock_levels.part_id AS part_id, SUM(qty_on_hand - qty_reserved) AS free')
            ->pluck('free', 'part_id')->map(fn ($v) => (string) $v)->all();
    }
}
