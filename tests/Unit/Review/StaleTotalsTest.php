<?php

use JBadarneh\JoFotara\JoFotaraService;

// Review finding 2: stale totals

test('totals follow a special tax set after they were first calculated', function () {
    $invoice = reviewInvoice('special_sales');
    $item = $invoice->items()->addItem('1')->setQuantity(10)->setUnitPrice(50)->setDiscount(5)->setDescription('Item')->tax(10);
    expect($invoice->invoiceTotals()->getPayableAmount())->toEqualWithDelta(544.5, 1e-9);

    $item->setSpecialTax(10);

    // 495 + 10 + 50.5
    expect($invoice->invoiceTotals()->getPayableAmount())->toEqualWithDelta(555.5, 1e-9);

    $xml = $invoice->generateXml();
    expect(xmlAmount($xml, 'PayableAmount'))->toBe('555.500000000')
        ->and(xmlAmount($xml, 'TaxInclusiveAmount'))->toBe('555.500000000')
        ->and(xmlAmount($xml, 'RoundingAmount'))->toBe('555.500000000');
});

test('totals follow a consumer price set after they were first calculated', function () {
    $invoice = reviewInvoice();
    $item = $invoice->items()->addItem('1')->setQuantity(10)->setUnitPrice(5)->setDiscount(10)->setDescription('Item')->tax(16);
    $invoice->invoiceTotals();

    $item->setConsumerPrice(6);

    $xml = $invoice->generateXml();
    expect(xmlAmount($xml, 'PayableAmount'))->toBe('49.600000000')
        ->and($invoice->invoiceTotals()->toArray()['taxTotalAmount'])->toEqualWithDelta(9.6, 1e-9);
});

test('totals follow line items added, changed, discounted or re-taxed afterwards', function () {
    $invoice = reviewInvoice();
    $first = $invoice->items()->addItem('1')->setQuantity(1)->setUnitPrice(100)->setDescription('A')->tax(16);
    $invoice->invoiceTotals();
    expect(xmlAmount($invoice->generateXml(), 'PayableAmount'))->toBe('116.000000000');

    // Add a line
    $invoice->items()->addItem('2')->setQuantity(2)->setUnitPrice(10)->setDescription('B')->tax(16);
    expect(xmlAmount($invoice->generateXml(), 'PayableAmount'))->toBe('139.200000000');

    // Change quantity and price
    $first->setQuantity(2)->setUnitPrice(50.5);
    expect(xmlAmount($invoice->generateXml(), 'TaxExclusiveAmount'))->toBe('121.000000000');

    // Change the discount
    $first->setDiscount(1);
    $xml = $invoice->generateXml();
    expect(xmlAmount($xml, 'AllowanceTotalAmount'))->toBe('1.000000000')
        ->and(xmlAmount($xml, 'PayableAmount'))->toBe('139.200000000');

    // Change the tax rate and category
    $first->tax(8);
    expect($invoice->invoiceTotals()->toArray()['taxTotalAmount'])->toEqualWithDelta(11.2, 1e-9);
    $first->taxExempted();
    $xml = $invoice->generateXml();
    expect($invoice->invoiceTotals()->toArray()['taxTotalAmount'])->toEqualWithDelta(3.2, 1e-9)
        ->and(xmlAmount($xml, 'PayableAmount'))->toBe('123.200000000');
});

test('totals requested before any item was added are calculated once items exist', function () {
    $invoice = reviewInvoice();
    $invoice->invoiceTotals();
    $invoice->items()->addItem('1')->setQuantity(1)->setUnitPrice(100)->setDescription('A')->tax(16);

    expect(xmlAmount($invoice->generateXml(), 'PayableAmount'))->toBe('116.000000000');
});

test('serialized totals always equal the totals object after inputs change', function () {
    $invoice = reviewInvoice('special_sales');
    $item = $invoice->items()->addItem('1')->setQuantity(3)->setUnitPrice(1.15)->setDescription('A')->tax(16);
    $invoice->invoiceTotals();
    $item->setSpecialTax(0.2)->setDiscount(0.45)->setConsumerPrice(1.5);

    $xml = $invoice->generateXml();
    $totals = $invoice->invoiceTotals()->toArray();

    expect(xmlAmount($xml, 'TaxExclusiveAmount'))->toBe(sprintf('%.9f', $totals['taxExclusiveAmount']))
        ->and(xmlAmount($xml, 'TaxInclusiveAmount'))->toBe(sprintf('%.9f', $totals['taxInclusiveAmount']))
        ->and(xmlAmount($xml, 'AllowanceTotalAmount'))->toBe(sprintf('%.9f', $totals['discountTotalAmount']))
        ->and(xmlAmount($xml, 'PayableAmount'))->toBe(sprintf('%.9f', $totals['payableAmount']))
        // 3.45 - 0.45 = 3.00; tax on 4.5 + 0.2 = 0.752; total 3 + 0.2 + 0.752
        ->and(xmlAmount($xml, 'PayableAmount'))->toBe('3.952000000');
});

test('totals set by the caller are kept and still checked against the items', function () {
    $invoice = reviewInvoice();
    $item = $invoice->items()->addItem('1')->setQuantity(1)->setUnitPrice(100)->setDescription('A')->tax(16);
    $invoice->invoiceTotals()->setPayableAmount(116.001)->setTaxInclusiveAmount(116.001);

    // Within the 0.001 tolerance: the caller's figures are sent as given
    expect(xmlAmount($invoice->generateXml(), 'PayableAmount'))->toBe('116.001000000');

    // The items change but the caller's totals do not follow: refused
    $item->setQuantity(2);
    expect(fn () => $invoice->generateXml())
        ->toThrow(InvalidArgumentException::class, 'Invoice totals do not match calculated values from line items');
});

test('stale totals are not sent when validations are disabled', function () {
    $invoice = new JoFotaraService('test-client-id', 'test-client-secret', false);
    $invoice->basicInformation()->setInvoiceId('INV-001')->setUuid('123e4567-e89b-12d3-a456-426614174000')->setIssueDate('16-02-2025')->setInvoiceType('special_sales')->cash();
    $invoice->sellerInformation()->setName('Seller')->setTin('12345678');
    $invoice->supplierIncomeSource('9932895');
    $item = $invoice->items()->addItem('1')->setQuantity(10)->setUnitPrice(50)->setDiscount(5)->setDescription('Item')->tax(10);
    $invoice->invoiceTotals();
    $item->setSpecialTax(10);

    expect(xmlAmount($invoice->generateXml(), 'PayableAmount'))->toBe('555.500000000');
});
