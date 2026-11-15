<?php

use JBadarneh\JoFotara\Sections\BasicInvoiceInformation;
use JBadarneh\JoFotara\Sections\CustomerInformation;
use JBadarneh\JoFotara\Sections\InvoiceLineItem;

// Review finding 7: toArray() shapes

test('basic information toArray keeps its original shape', function () {
    $info = (new BasicInvoiceInformation)
        ->setInvoiceId('INV-001')
        ->setUuid('123e4567-e89b-12d3-a456-426614174000')
        ->setIssueDate('16-02-2025')
        ->setInvoiceType('general_sales')
        ->cash();

    expect($info->toArray())->toBe([
        'invoiceId' => 'INV-001',
        'uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'issueDate' => '16-02-2025',
        'invoiceType' => 'general_sales',
        'paymentMethod' => '012',
        'note' => null,
        'currency' => 'JOD',
        'invoiceCounter' => 1,
        'isCreditInvoice' => false,
    ]);

    // The new field appears only when the new feature is used
    expect($info->setTransactionType('export')->toArray())->toMatchArray(['paymentMethod' => '112', 'transactionType' => 'export'])
        ->and(array_keys($info->toArray()))->toBe(['invoiceId', 'uuid', 'issueDate', 'invoiceType', 'paymentMethod', 'note', 'currency', 'invoiceCounter', 'isCreditInvoice', 'transactionType']);
});

test('line item toArray keeps its original shape', function () {
    $item = (new InvoiceLineItem('1'))->setQuantity(2)->setUnitPrice(100)->setDescription('Widget')->setDiscount(20)->tax(16);

    expect($item->toArray())->toBe([
        'id' => '1',
        'quantity' => 2.0,
        'unitPrice' => 100.0,
        'discount' => 20.0,
        'description' => 'Widget',
        'taxCategory' => 'S',
        'taxPercent' => 16.0,
        'unitCode' => 'PCE',
    ]);

    // The new fields appear only when the new features are used
    expect($item->setSpecialTax(5)->toArray())->toMatchArray(['specialTax' => 5.0])->not->toHaveKey('consumerPrice')
        ->and($item->setConsumerPrice(120)->toArray())->toMatchArray(['specialTax' => 5.0, 'consumerPrice' => 120.0]);
});

test('the buyer ID type is kept as supplied and mapped only when serialized', function (string $suppliedType, string $scheme) {
    $customer = (new CustomerInformation)->setId('33445544', $suppliedType)->setName('Buyer');

    expect($customer->toArray())->toBe([
        'id' => '33445544',
        'idType' => $suppliedType,
        'postalCode' => null,
        'cityCode' => null,
        'name' => 'Buyer',
        'phone' => null,
        'tin' => null,
    ])
        ->and($customer->getIdType())->toBe($suppliedType)
        ->and($customer->getIdScheme())->toBe($scheme)
        ->and($customer->toXml())->toContain('<cbc:ID schemeID="'.$scheme.'">33445544</cbc:ID>');
})->with([
    ['TIN', 'TN'],
    ['TN', 'TN'],
    ['NIN', 'NIN'],
    ['PN', 'PN'],
]);

test('an unknown buyer ID type is refused with the original message', function () {
    expect(fn () => (new CustomerInformation)->setId('1', 'XXX'))
        ->toThrow(InvalidArgumentException::class, 'ID type must be one of: NIN, PN, TIN');
});

test('a development zone invoice accepts the buyer tax number as TIN or TN', function (string $type) {
    $invoice = reviewInvoice();
    $invoice->basicInformation()->setTransactionType('development_zone');
    $invoice->customerInformation()->setId('33445544', $type);
    $invoice->items()->addItem('1')->setQuantity(1)->setUnitPrice(10)->setDescription('Item')->zeroTax();
    $invoice->invoiceTotals();

    expect($invoice->generateXml())->toContain('<cbc:ID schemeID="TN">33445544</cbc:ID>');
})->with(['TIN', 'TN']);
