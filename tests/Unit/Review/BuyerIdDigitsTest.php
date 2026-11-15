<?php

use JBadarneh\JoFotara\JoFotaraService;

// Review finding 4: buyer ID is digits only (guide v1.5, buyer section)

test('the buyer ID must be digits for every ID type', function (string $type, string $id, bool $valid) {
    $invoice = reviewInvoice();
    $invoice->customerInformation()->setId($id, $type);
    $invoice->items()->addItem('1')->setQuantity(1)->setUnitPrice(10)->setDescription('Item')->tax(16);
    $invoice->invoiceTotals();

    if ($valid) {
        expect($invoice->generateXml())->toContain('>'.$id.'</cbc:ID>');
    } else {
        expect(fn () => $invoice->generateXml())
            ->toThrow(InvalidArgumentException::class, 'Customer ID must contain digits only');
    }
})->with([
    ['NIN', '9991234567', true],
    ['PN', '123456789', true],
    ['TN', '33445544', true],
    ['TIN', '33445544', true],
    ['PN', 'P123456', false],
    ['PN', 'AB-123456', false],
    ['NIN', '999 123 4567', false],
    ['TN', '3344-5544', false],
    ['TIN', 'JO33445544', false],
]);

test('an alphanumeric buyer ID can still be sent with validations disabled', function () {
    $invoice = new JoFotaraService('test-client-id', 'test-client-secret', false);
    $invoice->basicInformation()->setInvoiceId('INV-001')->setUuid('123e4567-e89b-12d3-a456-426614174000')->setIssueDate('16-02-2025')->setInvoiceType('income')->cash();
    $invoice->sellerInformation()->setName('Seller')->setTin('12345678');
    $invoice->customerInformation()->setId('P123456', 'PN');
    $invoice->supplierIncomeSource('9932895');
    $invoice->items()->addItem('1')->setQuantity(1)->setUnitPrice(10)->setDescription('Item')->taxExempted();
    $invoice->invoiceTotals();

    expect($invoice->generateXml())->toContain('<cbc:ID schemeID="PN">P123456</cbc:ID>');
});
