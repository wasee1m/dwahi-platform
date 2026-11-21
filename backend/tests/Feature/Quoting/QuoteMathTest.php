<?php

use App\Modules\Quoting\Support\QuoteMath;

// BR-01: ثلاث خانات، والضريبة لكل سطر مقرّبة لأقرب بيسة نصف للأعلى

it('rounds VAT half up per line to the nearest baisa', function (string $qty, string $price, string $discount, string $rate, array $expected) {
    expect(QuoteMath::line($qty, $price, $discount, $rate))->toBe($expected);
})->with([
    'simple' => ['2', '10.000', '0', '5.00', ['line_subtotal' => '20.000', 'line_vat' => '1.000', 'line_total' => '21.000']],
    'half baisa rounds up' => ['1', '0.010', '0', '5.00', ['line_subtotal' => '0.010', 'line_vat' => '0.001', 'line_total' => '0.011']], // 0.0005
    'below half rounds down' => ['1', '0.009', '0', '5.00', ['line_subtotal' => '0.009', 'line_vat' => '0.000', 'line_total' => '0.009']], // 0.00045
    'fractional qty' => ['2.5', '3.333', '0', '5.00', ['line_subtotal' => '8.333', 'line_vat' => '0.417', 'line_total' => '8.750']],     // 8.3325 → 8.333
    'with discount' => ['50', '18.500', '46.250', '5.00', ['line_subtotal' => '878.750', 'line_vat' => '43.938', 'line_total' => '922.688']],
    'export zero rate' => ['1', '45000.000', '0', '0.00', ['line_subtotal' => '45000.000', 'line_vat' => '0.000', 'line_total' => '45000.000']],
]);

it('sums totals with the discount shown separately', function () {
    $a = ['quantity' => '1', 'unit_price' => '100.000', 'discount_amount' => '5.000'] + QuoteMath::line('1', '100.000', '5.000', '5.00');
    $b = ['quantity' => '2', 'unit_price' => '10.000', 'discount_amount' => '0.000'] + QuoteMath::line('2', '10.000', '0', '5.00');

    expect(QuoteMath::totals([$a, $b]))->toBe(['subtotal' => '120.000', 'discount_total' => '5.000', 'vat_total' => '5.750', 'total' => '120.750']);
});

it('refuses a discount larger than the line', function () {
    QuoteMath::line('1', '10.000', '10.001', '5.00');
})->throws(InvalidArgumentException::class);
