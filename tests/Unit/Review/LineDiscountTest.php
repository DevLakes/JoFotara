<?php

use JBadarneh\JoFotara\Sections\InvoiceLineItem;

// Review finding 1: full line discount

test('a discount equal to the line total is accepted', function (float $quantity, float $unitPrice, float $discount) {
    $item = (new InvoiceLineItem('1'))
        ->setQuantity($quantity)
        ->setUnitPrice($unitPrice)
        ->setDescription('Item')
        ->setDiscount($discount)
        ->tax(16);

    $item->validateSection();

    expect($item->getAmountAfterDiscount())->toBe(0.0)
        ->and($item->getTaxAmount())->toBe(0.0)
        ->and($item->toXml())
        ->toContain('<cbc:LineExtensionAmount currencyID="JO">0.000000000</cbc:LineExtensionAmount>')
        ->toContain('<cbc:RoundingAmount currencyID="JO">0.000000000</cbc:RoundingAmount>')
        ->not->toContain('>-');
})->with([
    '3 x 1.15' => [3, 1.15, 3.45],
    '3 x 1.1' => [3, 1.1, 3.3],
    '3 x 0.1' => [3, 0.1, 0.3],
    '7 x 640.86' => [7, 640.86, 4486.02],
    '100 x 0.57' => [100, 0.57, 57.0],
    '1.5 x 19.99' => [1.5, 19.99, 29.985],
    'small amounts' => [3, 0.001, 0.003],
    'smallest step' => [1, 0.000000001, 0.000000001],
    'large amounts' => [1000000, 999.999, 999999000.0],
    'whole numbers' => [2, 5.0, 10.0],
]);

test('a discount below the line total is accepted and one above it is refused', function (float $quantity, float $unitPrice) {
    $total = round($quantity * $unitPrice, 9);
    $item = (new InvoiceLineItem('1'))->setQuantity($quantity)->setUnitPrice($unitPrice)->setDescription('Item');

    // Zero and slightly below
    foreach ([0.0, round($total - 0.001, 9), round($total - 0.00000001, 9)] as $discount) {
        $item->setDiscount($discount)->validateSection();
        expect($item->getAmountAfterDiscount())->toBeGreaterThanOrEqual(0.0);
    }

    // Slightly above: more than rounding can explain
    foreach ([round($total + 0.00000001, 9), round($total + 0.001, 9), $total * 2 + 1] as $discount) {
        expect(fn () => $item->setDiscount($discount))
            ->toThrow(InvalidArgumentException::class, 'Discount cannot be greater than total amount');
    }
})->with([
    [3, 1.15],
    [7, 640.86],
    [2.5, 0.333],
    [1, 0.01],
    [12, 1234.567],
]);

test('validateSection applies the same discount rule as the setter', function () {
    // Discount set before quantity and price, so only validateSection can check it
    $full = (new InvoiceLineItem('1'))->setDiscount(3.45)->setQuantity(3)->setUnitPrice(1.15)->setDescription('Item');
    $full->validateSection();

    $over = (new InvoiceLineItem('1'))->setDiscount(3.46)->setQuantity(3)->setUnitPrice(1.15)->setDescription('Item');

    expect($full->getAmountAfterDiscount())->toBe(0.0)
        ->and(fn () => $over->validateSection())
        ->toThrow(InvalidArgumentException::class, 'Item discount cannot be greater than total amount');
});

test('random full discounts are never refused and never serialize a negative amount', function () {
    mt_srand(5);

    for ($i = 0; $i < 3000; $i++) {
        $quantity = mt_rand(1, 50000) / [1, 10, 1000][mt_rand(0, 2)];
        $unitPrice = mt_rand(1, 9999999) / [100, 1000][mt_rand(0, 1)];
        $total = round($quantity * $unitPrice, 9);

        $item = (new InvoiceLineItem('1'))->setQuantity($quantity)->setUnitPrice($unitPrice)->setDescription('Item')->setDiscount($total)->tax(16);
        $item->validateSection();

        expect($item->toXml())->not->toContain('>-');

        // A partial discount leaves exactly the remainder
        $partial = round($total / 2, 3);
        $item->setDiscount($partial);
        expect($item->getAmountAfterDiscount())->toEqualWithDelta($total - $partial, 1e-6);
    }
});

test('an invoice with a fully discounted line and a paid line is generated', function () {
    $invoice = reviewInvoice();
    $invoice->items()->addItem('1')->setQuantity(3)->setUnitPrice(1.15)->setDiscount(3.45)->setDescription('Free')->tax(16);
    $invoice->items()->addItem('2')->setQuantity(1)->setUnitPrice(10)->setDescription('Paid')->tax(16);
    $invoice->invoiceTotals();

    $xml = $invoice->generateXml();

    expect(xmlAmount($xml, 'TaxExclusiveAmount'))->toBe('13.450000000')
        ->and(xmlAmount($xml, 'AllowanceTotalAmount'))->toBe('3.450000000')
        ->and(xmlAmount($xml, 'PayableAmount'))->toBe('11.600000000')
        ->and($xml)->not->toContain('>-');
});
