<?php

use JBadarneh\JoFotara\JoFotaraService;

/**
 * Build a general sales (or other) invoice from line definitions:
 * [quantity, unitPrice, discount, tax] where tax is a rate, 'exempt' or 'zero'
 */
function moneyInvoice(array $lines, string $invoiceType = 'general_sales'): JoFotaraService
{
    $invoice = new JoFotaraService('test-client-id', 'test-client-secret');

    $invoice->basicInformation()
        ->setInvoiceId('INV-001')
        ->setUuid('123e4567-e89b-12d3-a456-426614174000')
        ->setIssueDate('16-02-2025')
        ->setInvoiceType($invoiceType)
        ->cash();
    $invoice->sellerInformation()->setName('Seller')->setTin('12345678');
    $invoice->customerInformation()->setId('33445544', 'TN')->setName('Buyer');
    $invoice->supplierIncomeSource('9932895');

    foreach ($lines as $index => [$quantity, $unitPrice, $discount, $tax]) {
        $item = $invoice->items()->addItem((string) ($index + 1))
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setDiscount($discount)
            ->setDescription('Item '.($index + 1));

        match ($tax) {
            'exempt' => $item->taxExempted(),
            'zero' => $item->zeroTax(),
            default => $item->tax($tax),
        };
    }

    return $invoice;
}

/**
 * Read the amounts back from the generated XML, as JoFotara would
 */
function serializedAmounts(string $xml): array
{
    $one = function (string $pattern) use ($xml): string {
        preg_match($pattern, $xml, $match);

        return $match[1] ?? '0.000000000';
    };
    $all = function (string $pattern) use ($xml): array {
        preg_match_all($pattern, $xml, $matches);

        return $matches[1];
    };

    [$header, $lines] = explode('<cac:InvoiceLine>', $xml, 2);

    return [
        'taxTotal' => (function () use ($header) {
            preg_match('#<cac:TaxTotal>\s*<cbc:TaxAmount currencyID="JO">([\d.]+)<#', $header, $match);

            return $match[1];
        })(),
        'taxExclusive' => $one('#<cbc:TaxExclusiveAmount currencyID="JO">([\d.]+)<#'),
        'taxInclusive' => $one('#<cbc:TaxInclusiveAmount currencyID="JO">([\d.]+)<#'),
        'allowanceTotal' => $one('#<cbc:AllowanceTotalAmount currencyID="JO">([\d.]+)<#'),
        'payable' => $one('#<cbc:PayableAmount currencyID="JO">([\d.]+)<#'),
        'lineExtensions' => $all('#<cbc:LineExtensionAmount currencyID="JO">([\d.]+)<#'),
        'lineTotals' => $all('#<cbc:RoundingAmount currencyID="JO">([\d.]+)<#'),
        'lineTaxes' => $all('#<cac:TaxTotal>\s*<cbc:TaxAmount currencyID="JO">([\d.]+)</cbc:TaxAmount>\s*<cbc:RoundingAmount#'),
        'lineDiscounts' => $all('#<cbc:AllowanceChargeReason>DISCOUNT</cbc:AllowanceChargeReason>\s*<cbc:Amount currencyID="JO">([\d.]+)<#'),
        'allAmounts' => $all('#currencyID="JO">([^<]*)<#'),
    ];
}

/**
 * The guide's rules, checked on the serialized values with its 0.001 tolerance
 */
function expectTotalsConsistentWithLines(string $xml, float $tolerance = 0.001): array
{
    $amounts = serializedAmounts($xml);
    $sum = fn (array $values) => array_sum(array_map('floatval', $values));

    // Every amount is a plain decimal with exactly nine decimals: no exponent,
    // no thousands separator, no sign
    foreach ($amounts['allAmounts'] as $amount) {
        expect($amount)->toMatch('/^\d+\.\d{9}$/');
    }

    expect((float) $amounts['taxInclusive'])->toEqualWithDelta($sum($amounts['lineTotals']), $tolerance)
        ->and((float) $amounts['payable'])->toEqualWithDelta($sum($amounts['lineTotals']), $tolerance)
        ->and((float) $amounts['taxTotal'])->toEqualWithDelta($sum($amounts['lineTaxes']), $tolerance)
        ->and((float) $amounts['allowanceTotal'])->toEqualWithDelta($sum($amounts['lineDiscounts']), $tolerance)
        ->and((float) $amounts['taxExclusive'])->toEqualWithDelta($sum($amounts['lineExtensions']) + $sum($amounts['lineDiscounts']), $tolerance)
        ->and($amounts['payable'])->toBe($amounts['taxInclusive']);

    return $amounts;
}

test('guide example for a general sales invoice is reproduced exactly', function () {
    // Guide v1.5: 33 x 2.00 - 2.00 at 7% and 10 x 5.00 exempt
    $invoice = moneyInvoice([[33, 2.0, 2.0, 7], [10, 5.0, 0.0, 'exempt']]);
    $invoice->invoiceTotals();

    $amounts = expectTotalsConsistentWithLines($invoice->generateXml());

    expect($amounts['lineExtensions'])->toBe(['64.000000000', '50.000000000'])
        ->and($amounts['lineTaxes'])->toBe(['4.480000000', '0.000000000'])
        ->and($amounts['lineTotals'])->toBe(['68.480000000', '50.000000000'])
        ->and($amounts['taxTotal'])->toBe('4.480000000')
        ->and($amounts['taxExclusive'])->toBe('116.000000000')
        ->and($amounts['allowanceTotal'])->toBe('2.000000000')
        ->and($amounts['taxInclusive'])->toBe('118.480000000')
        ->and($amounts['payable'])->toBe('118.480000000');
});

test('zero amounts on a line are carried through', function () {
    // A free item, a fully discounted item and a normal one
    $invoice = moneyInvoice([[1, 0.0, 0.0, 16], [2, 5.0, 10.0, 16], [1, 10.0, 0.0, 16]]);
    $invoice->invoiceTotals();

    $amounts = expectTotalsConsistentWithLines($invoice->generateXml());

    expect($amounts['lineExtensions'])->toBe(['0.000000000', '0.000000000', '10.000000000'])
        ->and($amounts['lineTaxes'])->toBe(['0.000000000', '0.000000000', '1.600000000'])
        ->and($amounts['taxExclusive'])->toBe('20.000000000')
        ->and($amounts['allowanceTotal'])->toBe('10.000000000')
        ->and($amounts['payable'])->toBe('11.600000000');
});

test('an invoice whose total is zero is still refused', function () {
    $invoice = moneyInvoice([[1, 0.0, 0.0, 16]]);
    $invoice->invoiceTotals();

    expect(fn () => $invoice->generateXml())->toThrow(InvalidArgumentException::class, 'Tax inclusive amount is required');
});

test('very small amounts keep their nine decimals', function () {
    // Smallest representable price and quantity steps
    $invoice = moneyInvoice([[0.000000001, 1000000.0, 0.0, 16], [1, 0.000000001, 0.0, 'zero'], [3, 0.001, 0.0, 1]]);
    $invoice->invoiceTotals();

    $amounts = expectTotalsConsistentWithLines($invoice->generateXml(), 1e-9);

    expect($amounts['lineExtensions'])->toBe(['0.001000000', '0.000000001', '0.003000000'])
        ->and($amounts['lineTaxes'])->toBe(['0.000160000', '0.000000000', '0.000030000'])
        ->and($amounts['taxExclusive'])->toBe('0.004000001')
        ->and($amounts['taxTotal'])->toBe('0.000190000')
        ->and($amounts['payable'])->toBe('0.004190001');
});

test('large invoice totals stay consistent and are never written in exponent form', function () {
    $invoice = moneyInvoice([
        [1000000, 999.999, 0.0, 16],
        [123456.789, 4321.123, 1000000.555, 8],
        [1, 250000000.0, 0.0, 'exempt'],
    ]);
    $invoice->invoiceTotals();

    $amounts = expectTotalsConsistentWithLines($invoice->generateXml());

    expect($amounts['lineExtensions'][0])->toBe('999999000.000000000')
        ->and($amounts['lineTaxes'][0])->toBe('159999840.000000000')
        ->and($amounts['lineExtensions'][2])->toBe('250000000.000000000')
        ->and((float) $amounts['payable'])->toBeGreaterThan(1.9e9);
});

test('many lines with every approved tax rate add up', function () {
    $lines = [];
    $expectedTax = 0;      // in millionths of a dinar, integer arithmetic
    $expectedNet = 0;
    foreach ([1, 2, 3, 4, 5, 7, 8, 10, 16] as $index => $rate) {
        for ($repeat = 1; $repeat <= 5; $repeat++) {
            $quantity = $repeat;
            $priceInFils = 1111 * ($index + 1);              // e.g. 1.111, 2.222 ...
            $lines[] = [$quantity, $priceInFils / 1000, 0.0, $rate];
            $expectedNet += $quantity * $priceInFils * 1000;
            $expectedTax += $quantity * $priceInFils * $rate * 10;
        }
    }
    $lines[] = [7, 3.333, 0.0, 'exempt'];
    $lines[] = [9, 0.105, 0.0, 'zero'];
    $expectedNet += 7 * 3333 * 1000 + 9 * 105 * 1000;

    $invoice = moneyInvoice($lines);
    $invoice->invoiceTotals();

    $amounts = expectTotalsConsistentWithLines($invoice->generateXml(), 1e-9);

    expect($amounts['lineTotals'])->toHaveCount(47)
        ->and($amounts['taxExclusive'])->toBe(sprintf('%d.%06d000', intdiv($expectedNet, 1000000), $expectedNet % 1000000))
        ->and($amounts['taxTotal'])->toBe(sprintf('%d.%06d000', intdiv($expectedTax, 1000000), $expectedTax % 1000000))
        ->and($amounts['payable'])->toBe(sprintf('%d.%06d000', intdiv($expectedNet + $expectedTax, 1000000), ($expectedNet + $expectedTax) % 1000000));
});

test('classic binary floating point traps serialize to the exact decimal', function (array $lines, string $taxExclusive, string $taxTotal, string $payable) {
    $invoice = moneyInvoice($lines);
    $invoice->invoiceTotals();

    $amounts = expectTotalsConsistentWithLines($invoice->generateXml(), 1e-9);

    expect($amounts['taxExclusive'])->toBe($taxExclusive)
        ->and($amounts['taxTotal'])->toBe($taxTotal)
        ->and($amounts['payable'])->toBe($payable);
})->with([
    '0.1 + 0.2' => [[[1, 0.1, 0.0, 'zero'], [1, 0.2, 0.0, 'zero']], '0.300000000', '0.000000000', '0.300000000'],
    '3 x 1.1' => [[[3, 1.1, 0.0, 'zero']], '3.300000000', '0.000000000', '3.300000000'],
    'ten times 0.1' => [array_fill(0, 10, [1, 0.1, 0.0, 'zero']), '1.000000000', '0.000000000', '1.000000000'],
    '0.57 x 100' => [[[100, 0.57, 0.0, 'zero']], '57.000000000', '0.000000000', '57.000000000'],
    '1.15 x 3 at 16%' => [[[3, 1.15, 0.0, 16]], '3.450000000', '0.552000000', '4.002000000'],
    '4.35 x 100 at 7%' => [[[100, 4.35, 0.0, 7]], '435.000000000', '30.450000000', '465.450000000'],
    '8.2 - 0.2 discount' => [[[1, 8.2, 0.2, 16]], '8.200000000', '1.280000000', '9.280000000'],
    '1.005 at 10%' => [[[1, 1.005, 0.0, 10]], '1.005000000', '0.100500000', '1.105500000'],
    'thirds' => [[[3, 0.333333333, 0.0, 'zero']], '0.999999999', '0.000000000', '0.999999999'],
]);

test('sub-nine-decimal results round half away from zero at the ninth decimal', function () {
    // 0.5 x 0.000000001 = 0.0000000005 exactly at the rounding boundary
    $invoice = moneyInvoice([[1, 1.0, 0.0, 'zero'], [0.5, 0.000000003, 0.0, 'zero']]);
    $invoice->invoiceTotals();

    $amounts = serializedAmounts($invoice->generateXml());

    // 1 + 0.0000000015 -> 1.000000002
    expect($amounts['taxExclusive'])->toBe('1.000000002')
        ->and($amounts['payable'])->toBe('1.000000002');
});

test('calculated totals are exactly what is serialized', function () {
    $invoice = moneyInvoice([[33, 2.0, 2.0, 7], [1.5, 19.999, 0.123456789, 16], [1000000, 999.999, 0.0, 8]]);
    $totals = $invoice->invoiceTotals()->toArray();

    $amounts = serializedAmounts($invoice->generateXml());

    expect(sprintf('%.9f', $totals['taxExclusiveAmount']))->toBe($amounts['taxExclusive'])
        ->and(sprintf('%.9f', $totals['taxInclusiveAmount']))->toBe($amounts['taxInclusive'])
        ->and(sprintf('%.9f', $totals['discountTotalAmount']))->toBe($amounts['allowanceTotal'])
        ->and(sprintf('%.9f', $totals['taxTotalAmount']))->toBe($amounts['taxTotal'])
        ->and(sprintf('%.9f', $totals['payableAmount']))->toBe($amounts['payable']);
});

test('caller-supplied totals are accepted up to and including a 0.001 difference', function (float $offset, bool $accepted) {
    // 3 x 1.111 at 16%: net 3.333, tax 0.53328, total 3.86628.
    // A positive offset raises the invoice total, a negative one lowers the
    // total before discount (InvoiceTotals does not allow a total below
    // net + tax).
    $invoice = moneyInvoice([[3, 1.111, 0.0, 16]]);
    $invoice->invoiceTotals()
        ->setTaxExclusiveAmount(round(3.333 + min($offset, 0.0), 9))
        ->setTaxInclusiveAmount(round(3.86628 + max($offset, 0.0), 9))
        ->setTaxTotalAmount(0.53328)
        ->setPayableAmount(round(3.86628 + max($offset, 0.0), 9));

    if ($accepted) {
        expect($invoice->generateXml())
            ->toContain(sprintf('<cbc:PayableAmount currencyID="JO">%.9f</cbc:PayableAmount>', 3.86628 + max($offset, 0.0)));
    } else {
        expect(fn () => $invoice->generateXml())
            ->toThrow(InvalidArgumentException::class, 'Invoice totals do not match calculated values from line items');
    }
})->with([
    'exact' => [0.0, true],
    'just inside above' => [0.000999999, true],
    'just inside below' => [-0.000999999, true],
    'exactly 0.001 above' => [0.001, true],
    'exactly 0.001 below' => [-0.001, true],
    'just outside above' => [0.001000001, false],
    'just outside below' => [-0.001000001, false],
    'far off' => [0.01, false],
]);

test('a difference of exactly 0.001 is accepted for many amounts', function () {
    // Without rounding the difference, about two thirds of these fail on
    // binary floating point noise
    foreach ([0.007, 1.1, 3.866, 19.99, 64.125, 1234.567, 99999.999, 1234567.891] as $price) {
        foreach ([0.001, -0.001] as $offset) {
            $invoice = moneyInvoice([[1, $price, 0.0, 'zero']]);
            $invoice->invoiceTotals()
                ->setTaxExclusiveAmount(round($price + min($offset, 0.0), 9))
                ->setTaxInclusiveAmount(round($price + max($offset, 0.0), 9))
                ->setPayableAmount(round($price + max($offset, 0.0), 9));

            expect($invoice->generateXml())->toBeString();
        }
    }
});

test('automatic totals never reject a valid invoice because of floating point noise', function () {
    // Before the checks compared at nine decimals, about 29% of these simple
    // invoices were refused, e.g. 7 x 640.86 at 5%
    mt_srand(3);
    $rates = [1, 2, 3, 4, 5, 7, 8, 10, 16, 'exempt', 'zero'];

    for ($i = 0; $i < 2000; $i++) {
        $lines = [];
        for ($line = mt_rand(1, 4); $line > 0; $line--) {
            $quantity = mt_rand(1, 20000) / [1, 10, 1000][mt_rand(0, 2)];
            $price = mt_rand(1, 9999999) / [100, 1000][mt_rand(0, 1)];
            $discount = mt_rand(0, 2) === 0 ? round($quantity * $price * mt_rand(1, 500) / 1000, 3) : 0.0;
            $lines[] = [$quantity, $price, $discount, $rates[mt_rand(0, 10)]];
        }

        $invoice = moneyInvoice($lines);
        $invoice->invoiceTotals();

        // Far tighter than the 0.001 the guide allows; floats cannot hold nine
        // decimals on totals in the billions
        expectTotalsConsistentWithLines($invoice->generateXml(), 1e-4);
    }
});

test('named floating point regressions are accepted', function (float $quantity, float $price, int $rate, string $payable) {
    $invoice = moneyInvoice([[$quantity, $price, 0.0, $rate]]);
    $invoice->invoiceTotals();

    expect(serializedAmounts($invoice->generateXml())['payable'])->toBe($payable);
})->with([
    [7, 640.86, 5, '4710.321000000'],
    [3, 1.15, 16, '4.002000000'],
    [3, 0.1, 16, '0.348000000'],
    // More than nine decimals in the products: each total is rounded on its own
    [0.670532, 913.775426, 4, '637.224290504'],
]);

test('totals that are really inconsistent are still refused', function () {
    $invoice = moneyInvoice([[1, 100.0, 0.0, 16]]);
    $totals = $invoice->invoiceTotals();

    expect(fn () => $totals->setTaxInclusiveAmount(99.99999999))
        ->toThrow(InvalidArgumentException::class, 'Tax inclusive amount cannot be less than tax exclusive amount')
        ->and(fn () => $totals->setDiscountTotalAmount(100.00000001))
        ->toThrow(InvalidArgumentException::class, 'Discount total amount cannot be greater than tax exclusive amount')
        ->and(fn () => $totals->setTaxTotalAmount(16.00000001))
        ->toThrow(InvalidArgumentException::class, 'Tax total amount would make tax inclusive amount invalid')
        ->and(fn () => $totals->setPayableAmount(115.99999999))
        ->toThrow(InvalidArgumentException::class, 'Payable amount cannot be less than tax inclusive amount minus discounts');
});

test('special sales totals include special tax and stay consistent', function () {
    $invoice = new JoFotaraService('test-client-id', 'test-client-secret');
    $invoice->basicInformation()
        ->setInvoiceId('INV-001')
        ->setUuid('123e4567-e89b-12d3-a456-426614174000')
        ->setIssueDate('16-02-2025')
        ->setInvoiceType('special_sales')
        ->cash();
    $invoice->sellerInformation()->setName('Seller')->setTin('12345678');
    $invoice->supplierIncomeSource('9932895');
    $invoice->items()->addItem('1')->setQuantity(10)->setUnitPrice(50)->setDiscount(5)->setDescription('A')->setSpecialTax(10)->tax(10);
    $invoice->items()->addItem('2')->setQuantity(3)->setUnitPrice(0.1)->setDescription('B')->setSpecialTax(0.2)->tax(16);
    $invoice->items()->addItem('3')->setQuantity(1)->setUnitPrice(7.777)->setDescription('C')->taxExempted();
    $invoice->invoiceTotals();

    $amounts = expectTotalsConsistentWithLines($invoice->generateXml(), 1e-9);

    // 495 + 10 + 50.5, 0.3 + 0.2 + 0.08, 7.777
    expect($amounts['lineTotals'])->toBe(['555.500000000', '0.580000000', '7.777000000'])
        ->and($amounts['taxTotal'])->toBe('50.580000000')
        ->and($amounts['taxExclusive'])->toBe('508.077000000')
        ->and($amounts['payable'])->toBe('563.857000000');
});
