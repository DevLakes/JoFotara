<?php

use JBadarneh\JoFotara\JoFotaraService;
use JBadarneh\JoFotara\Response\JoFotaraResponse;
use JBadarneh\JoFotara\Sections\BasicInvoiceInformation;
use JBadarneh\JoFotara\Sections\InvoiceLineItem;
use JBadarneh\JoFotara\Tests\Helpers\XmlSchemaValidator;

uses(XmlSchemaValidator::class);

// Covers the rules introduced or clarified by the JoFotara integration guide v1.5

function v15Invoice(string $invoiceType = 'general_sales', string $transactionType = 'local'): JoFotaraService
{
    $invoice = new JoFotaraService('test-client-id', 'test-client-secret');

    $invoice->basicInformation()
        ->setInvoiceId('INV-001')
        ->setUuid('123e4567-e89b-12d3-a456-426614174000')
        ->setIssueDate('16-02-2025')
        ->setInvoiceType($invoiceType)
        ->setTransactionType($transactionType)
        ->cash();

    $invoice->sellerInformation()->setName('Seller')->setTin('12345678');
    $invoice->customerInformation()->setId('33445544', 'TN')->setName('Buyer');
    $invoice->supplierIncomeSource('9932895');

    return $invoice;
}

test('builds the invoice type code from transaction, payment and tax type', function (string $invoiceType, string $transactionType, string $payment, string $code) {
    $info = (new BasicInvoiceInformation)
        ->setInvoiceType($invoiceType)
        ->setTransactionType($transactionType)
        ->$payment();

    expect($info->getPaymentMethod())->toBe($code);
})->with([
    ['income', 'local', 'cash', '011'],
    ['income', 'export', 'receivable', '121'],
    ['income', 'development_zone', 'cash', '211'],
    ['general_sales', 'local', 'receivable', '022'],
    ['general_sales', 'transit', 'receivable', '322'],
    ['general_sales', 'foreign_trade', 'receivable', '422'],
    ['general_sales', 'free_zone_transfer', 'cash', '512'],
    ['special_sales', 'export', 'cash', '113'],
    ['special_sales', 'transit', 'receivable', '323'],
    ['special_sales', 'free_zone_transfer', 'cash', '513'],
]);

test('transaction type can be set after the payment method', function () {
    $info = (new BasicInvoiceInformation)->setInvoiceType('general_sales')->cash()->setTransactionType('export');

    expect($info->getPaymentMethod())->toBe('112');
});

test('setPaymentMethod accepts the extended codes and derives the transaction type', function () {
    $info = (new BasicInvoiceInformation)->setInvoiceType('special_sales')->setPaymentMethod('423');

    expect($info->getTransactionType())->toBe('foreign_trade')
        ->and($info->isReceivable())->toBeTrue()
        ->and($info->getPaymentMethod())->toBe('423')
        ->and(fn () => $info->setPaymentMethod('422'))->toThrow(InvalidArgumentException::class, "not valid for invoice type 'special_sales'")
        ->and(fn () => $info->setPaymentMethod('623'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $info->setTransactionType('moon'))->toThrow(InvalidArgumentException::class, 'Transaction type must be one of');
});

test('currency can be changed to a supported currency only', function () {
    $invoice = v15Invoice();
    $invoice->basicInformation()->setCurrency('usd');
    $invoice->items()->addItem('1')->setQuantity(1)->setUnitPrice(10)->setDescription('Item')->tax(16);
    $invoice->invoiceTotals();

    expect($invoice->generateXml())
        ->toContain('<cbc:DocumentCurrencyCode>USD</cbc:DocumentCurrencyCode>')
        ->toContain('<cbc:TaxCurrencyCode>USD</cbc:TaxCurrencyCode>')
        ->and(fn () => $invoice->basicInformation()->setCurrency('XYZ'))
        ->toThrow(InvalidArgumentException::class, 'Currency must be one of');
});

test('invoice XML carries the reporting profile', function () {
    $invoice = v15Invoice();
    $invoice->items()->addItem('1')->setQuantity(1)->setUnitPrice(10)->setDescription('Item')->tax(16);
    $invoice->invoiceTotals();

    expect($invoice->generateXml())->toContain('<cbc:ProfileID>reporting:1.0</cbc:ProfileID>');
});

test('TIN is accepted as an alias of the TN buyer scheme', function () {
    $invoice = v15Invoice();
    $invoice->customerInformation()->setId('33445544', 'TIN');
    $invoice->items()->addItem('1')->setQuantity(1)->setUnitPrice(10)->setDescription('Item')->tax(16);
    $invoice->invoiceTotals();

    expect($invoice->generateXml())->toContain('<cbc:ID schemeID="TN">33445544</cbc:ID>');
});

test('buyer fields are validated against the guide limits', function (callable $setup, string $message) {
    $invoice = v15Invoice();
    $setup($invoice->customerInformation());
    $invoice->items()->addItem('1')->setQuantity(1)->setUnitPrice(10)->setDescription('Item')->tax(16);
    $invoice->invoiceTotals();

    expect(fn () => $invoice->generateXml())->toThrow(InvalidArgumentException::class, $message);
})->with([
    [fn ($c) => $c->setId('AB123', 'PN'), 'Customer ID must contain digits only'],
    [fn ($c) => $c->setPostalCode('123456'), 'Customer postal code must be at most 5 digits'],
    [fn ($c) => $c->setPhone('12345678'), 'Customer phone must be 9 to 14 digits'],
    [fn ($c) => $c->setPhone('+962791234567'), 'Customer phone must be 9 to 14 digits'],
]);

test('non-local sales invoices must be zero-rated on every line', function () {
    $invoice = v15Invoice('general_sales', 'export');
    $invoice->items()->addItem('1')->setQuantity(1)->setUnitPrice(10)->setDescription('Item')->tax(16);
    $invoice->invoiceTotals();

    expect(fn () => $invoice->generateXml())
        ->toThrow(InvalidArgumentException::class, "All items must be zero-rated (use zeroTax()) for 'export' invoices");

    $invoice = v15Invoice('general_sales', 'export');
    $invoice->items()->addItem('1')->setQuantity(1)->setUnitPrice(10)->setDescription('Item')->zeroTax();
    $invoice->invoiceTotals();

    expect($invoice->generateXml())->toContain('<cbc:InvoiceTypeCode name="112">388</cbc:InvoiceTypeCode>');
});

test('development zone invoices require the buyer tax number', function () {
    $invoice = v15Invoice('general_sales', 'development_zone');
    $invoice->customerInformation()->setId('9991234567', 'NIN');
    $invoice->items()->addItem('1')->setQuantity(1)->setUnitPrice(10)->setDescription('Item')->zeroTax();
    $invoice->invoiceTotals();

    expect(fn () => $invoice->generateXml())
        ->toThrow(InvalidArgumentException::class, 'Customer tax number (ID type TN) is required for development zone invoices');

    $invoice->customerInformation()->setId('33445544', 'TN');

    expect($invoice->generateXml())->toContain('<cbc:InvoiceTypeCode name="212">388</cbc:InvoiceTypeCode>');
});

test('buyer name is required for receivable invoices of every transaction type', function () {
    $invoice = new JoFotaraService('test-client-id', 'test-client-secret');
    $invoice->basicInformation()
        ->setInvoiceId('INV-001')
        ->setUuid('123e4567-e89b-12d3-a456-426614174000')
        ->setIssueDate('16-02-2025')
        ->setInvoiceType('general_sales')
        ->setTransactionType('transit')
        ->receivable();
    $invoice->sellerInformation()->setName('Seller')->setTin('12345678');
    $invoice->supplierIncomeSource('9932895');
    $invoice->items()->addItem('1')->setQuantity(1)->setUnitPrice(10)->setDescription('Item')->zeroTax();
    $invoice->invoiceTotals();

    expect(fn () => $invoice->generateXml())
        ->toThrow(InvalidArgumentException::class, 'Customer name is required');
});

test('only approved general tax rates are accepted', function () {
    $invoice = v15Invoice();
    $invoice->items()->addItem('1')->setQuantity(1)->setUnitPrice(10)->setDescription('Item')->tax(6);
    $invoice->invoiceTotals();

    expect(fn () => $invoice->generateXml())
        ->toThrow(InvalidArgumentException::class, 'Tax percentage must be one of: 1, 2, 3, 4, 5, 7, 8, 10, 16');
});

test('special sales line matches the guide example', function () {
    // Guide v1.5, special sales invoice lines: 10 x 50.00, discount 5.00,
    // special tax 10.00, general tax 10%
    $item = (new InvoiceLineItem('1'))
        ->setQuantity(10)
        ->setUnitPrice(50)
        ->setDiscount(5)
        ->setDescription('Item')
        ->setSpecialTax(10)
        ->tax(10);

    expect($item->getAmountAfterDiscount())->toEqualWithDelta(495.0, 1e-9)
        ->and($item->getTaxAmount())->toEqualWithDelta(50.5, 1e-9)
        ->and($item->getTaxInclusiveAmount())->toEqualWithDelta(555.5, 1e-9)
        ->and($item->toXml())
        ->toContain('<cbc:TaxableAmount currencyID="JO">495.000000000</cbc:TaxableAmount>')
        ->toContain('<cbc:TaxAmount currencyID="JO">10.000000000</cbc:TaxAmount>')
        ->toContain('<cbc:ID schemeAgencyID="6" schemeID="UN/ECE 5153">OTH</cbc:ID>')
        ->toContain('<cbc:TaxAmount currencyID="JO">50.500000000</cbc:TaxAmount>')
        ->toContain('<cbc:RoundingAmount currencyID="JO">555.500000000</cbc:RoundingAmount>');
});

test('special sales invoice totals match the guide example and validate against UBL 2.1', function () {
    $invoice = v15Invoice('special_sales');
    foreach (['1', '2'] as $id) {
        $invoice->items()->addItem($id)
            ->setQuantity(10)
            ->setUnitPrice(50)
            ->setDiscount(5)
            ->setDescription('Item '.$id)
            ->setSpecialTax(10)
            ->tax(10);
    }
    $invoice->invoiceTotals();

    $xml = $invoice->generateXml();
    $validation = $this->validateAgainstUblSchema($xml);

    expect($validation['isValid'])->toBeTrue()
        ->and($xml)
        ->toContain('<cbc:TaxExclusiveAmount currencyID="JO">1000.000000000</cbc:TaxExclusiveAmount>')
        ->toContain('<cbc:TaxInclusiveAmount currencyID="JO">1111.000000000</cbc:TaxInclusiveAmount>')
        ->toContain('<cbc:AllowanceTotalAmount currencyID="JO">10.000000000</cbc:AllowanceTotalAmount>')
        ->toContain('<cbc:PayableAmount currencyID="JO">1111.000000000</cbc:PayableAmount>')
        ->toContain("<cac:TaxTotal>\n    <cbc:TaxAmount currencyID=\"JO\">101.000000000</cbc:TaxAmount>\n</cac:TaxTotal>");
});

test('special sales lines always carry the special tax subtotal', function () {
    $invoice = v15Invoice('special_sales');
    $invoice->items()->addItem('1')->setQuantity(1)->setUnitPrice(10)->setDescription('Item')->tax(16);
    $invoice->invoiceTotals();

    expect($invoice->generateXml())->toContain('<cbc:ID schemeAgencyID="6" schemeID="UN/ECE 5153">OTH</cbc:ID>');
});

test('special tax is rejected outside special sales invoices', function () {
    $invoice = v15Invoice('general_sales');
    $invoice->items()->addItem('1')->setQuantity(1)->setUnitPrice(10)->setDescription('Item')->setSpecialTax(1)->tax(16);
    $invoice->invoiceTotals();

    expect(fn () => $invoice->generateXml())
        ->toThrow(InvalidArgumentException::class, 'Special tax is only allowed on special sales invoices');
});

test('consumer price drives the general tax as in the guide examples', function (float $consumerPrice, float $tax, float $total) {
    // Guide v1.5, consumer price for general sales: 10 x 5.000, discount 10.000, 16%
    $item = (new InvoiceLineItem('1'))
        ->setQuantity(10)
        ->setUnitPrice(5)
        ->setDiscount(10)
        ->setDescription('Biscuit')
        ->setConsumerPrice($consumerPrice)
        ->tax(16);

    expect($item->getTaxAmount())->toEqualWithDelta($tax, 1e-9)
        ->and($item->getTaxInclusiveAmount())->toEqualWithDelta($total, 1e-9)
        ->and($item->toXml())->toContain(sprintf(
            "<cac:ItemPriceExtension>\n        <cbc:Amount currencyID=\"JO\">%.9f</cbc:Amount>\n    </cac:ItemPriceExtension>\n</cac:InvoiceLine>",
            $consumerPrice
        ));
})->with([
    'equal to unit price' => [5.0, 6.4, 46.4],
    'above unit price' => [6.0, 9.6, 49.6],
]);

test('consumer price combines with special tax as in the guide examples', function (float $consumerPrice, float $tax, float $total) {
    // Guide v1.5, consumer price for special sales: 10 x 50.000, discount 5.000,
    // special tax 10.00, 16%
    $item = (new InvoiceLineItem('1'))
        ->setQuantity(10)
        ->setUnitPrice(50)
        ->setDiscount(5)
        ->setDescription('Item')
        ->setSpecialTax(10)
        ->setConsumerPrice($consumerPrice)
        ->tax(16);

    expect($item->getTaxAmount())->toEqualWithDelta($tax, 1e-9)
        ->and($item->getTaxInclusiveAmount())->toEqualWithDelta($total, 1e-9);
})->with([
    'equal to unit price' => [50.0, 80.8, 585.8],
    'above unit price' => [55.0, 89.6, 594.6],
]);

test('consumer price rules are enforced', function () {
    // Lower than the unit price
    $invoice = v15Invoice();
    $invoice->items()->addItem('1')->setQuantity(1)->setUnitPrice(10)->setDescription('Item')->setConsumerPrice(9)->tax(16);
    $invoice->invoiceTotals();
    expect(fn () => $invoice->generateXml())
        ->toThrow(InvalidArgumentException::class, 'Item consumer price cannot be lower than the unit price');

    // Set on some lines only
    $invoice = v15Invoice();
    $invoice->items()->addItem('1')->setQuantity(1)->setUnitPrice(10)->setDescription('Item')->setConsumerPrice(12)->tax(16);
    $invoice->items()->addItem('2')->setQuantity(1)->setUnitPrice(10)->setDescription('Item')->tax(16);
    $invoice->invoiceTotals();
    expect(fn () => $invoice->generateXml())
        ->toThrow(InvalidArgumentException::class, 'Consumer price must be set on all items of the invoice or on none of them');

    // Not available on income invoices
    $invoice = v15Invoice('income');
    $invoice->items()->addItem('1')->setQuantity(1)->setUnitPrice(10)->setDescription('Item')->setConsumerPrice(12)->taxExempted();
    $invoice->invoiceTotals();
    expect(fn () => $invoice->generateXml())
        ->toThrow(InvalidArgumentException::class, 'Consumer price is only allowed on general sales and special sales invoices');
});

test('invoice with consumer price validates against UBL 2.1', function () {
    $invoice = v15Invoice();
    $invoice->items()->addItem('1')->setQuantity(10)->setUnitPrice(5)->setDiscount(10)->setDescription('Biscuit')->setConsumerPrice(6)->tax(16);
    $invoice->invoiceTotals();

    $xml = $invoice->generateXml();

    expect($this->validateAgainstUblSchema($xml)['isValid'])->toBeTrue()
        ->and($xml)->toContain('<cbc:PayableAmount currencyID="JO">49.600000000</cbc:PayableAmount>');
});

test('totals within the 0.001 rounding tolerance are accepted', function () {
    $invoice = v15Invoice();
    $invoice->items()->addItem('1')->setQuantity(3)->setUnitPrice(1.111)->setDescription('Item')->tax(16);
    // 3.333 + 0.53328 = 3.86628, rounded to three decimals by the caller
    $invoice->invoiceTotals()
        ->setTaxExclusiveAmount(3.333)
        ->setTaxInclusiveAmount(3.866)
        ->setTaxTotalAmount(0.533)
        ->setPayableAmount(3.866);

    expect($invoice->generateXml())->toContain('<cbc:PayableAmount currencyID="JO">3.866000000</cbc:PayableAmount>');

    $invoice->invoiceTotals()->setPayableAmount(3.9);
    expect(fn () => $invoice->generateXml())
        ->toThrow(InvalidArgumentException::class, 'Invoice totals do not match calculated values from line items');
});

test('original invoice total of a credit invoice has no thousands separator', function () {
    $info = (new BasicInvoiceInformation)
        ->setInvoiceId('CR-1')
        ->setUuid('123e4567-e89b-12d3-a456-426614174000')
        ->setIssueDate('16-02-2025')
        ->setInvoiceType('income')
        ->cash()
        ->asCreditInvoice('INV-1', '123e4567-e89b-12d3-a456-426614174001', 12345.678);

    expect($info->toXml())->toContain('<cbc:DocumentDescription>12345.678</cbc:DocumentDescription>');
});

test('response exposes already submitted status and QR code presence', function () {
    $response = new JoFotaraResponse([
        'EINV_RESULTS' => ['status' => 'PASS', 'INFO' => [], 'WARNINGS' => [], 'ERRORS' => []],
        'EINV_STATUS' => 'ALREADY_SUBMITTED',
        'EINV_SINGED_INVOICE' => 'abc',
        'EINV_QR' => 'qr',
        'EINV_NUM' => 'EIN000123456',
        'EINV_INV_UUID' => '123e4567-e89b-12d3-a456-426614174000',
    ], 200);

    expect($response->isSuccess())->toBeTrue()
        ->and($response->isAlreadySubmitted())->toBeTrue()
        ->and($response->hasQrCode())->toBeTrue();

    $rejected = new JoFotaraResponse([
        'EINV_RESULTS' => ['status' => 'ERROR', 'INFO' => [], 'WARNINGS' => [], 'ERRORS' => [[
            'type' => 'ERROR',
            'status' => 'ERROR',
            'EINV_CODE' => 'totalGeneralTaxesAmount',
            'EINV_CATEGORY' => 'invoice',
            'EINV_MESSAGE' => 'Total General Amount is Not Correct',
        ]]],
        'EINV_STATUS' => 'NOT_SUBMITTED',
        'EINV_SINGED_INVOICE' => null,
        'EINV_QR' => null,
        'EINV_NUM' => null,
        'EINV_INV_UUID' => null,
    ], 400);

    expect($rejected->isSuccess())->toBeFalse()
        ->and($rejected->isAlreadySubmitted())->toBeFalse()
        ->and($rejected->hasQrCode())->toBeFalse()
        ->and($rejected->getErrorSummary())->toBe('[totalGeneralTaxesAmount] invoice: Total General Amount is Not Correct');
});
