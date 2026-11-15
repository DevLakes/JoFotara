<?php

use JBadarneh\JoFotara\JoFotaraService;
use JBadarneh\JoFotara\Sections\BasicInvoiceInformation;

// Review finding 3: buyer name and foreign currency

function cashInvoiceOf(float $amount, string $currency, ?float $exchangeRate, bool $withBuyerName, bool $credit = false, string $invoiceType = 'general_sales'): JoFotaraService
{
    $invoice = reviewInvoice($invoiceType, $withBuyerName);
    $invoice->basicInformation()->setCurrency($currency);
    if ($exchangeRate !== null) {
        $invoice->basicInformation()->setExchangeRate($exchangeRate);
    }
    if ($credit) {
        $invoice->basicInformation()->asCreditInvoice('INV-000', '123e4567-e89b-12d3-a456-426614174001', $amount);
        $invoice->setReasonForReturn('Returned');
    }
    $invoice->items()->addItem('1')->setQuantity(1)->setUnitPrice($amount)->setDescription('Item')->zeroTax();
    $invoice->invoiceTotals();

    return $invoice;
}

const BUYER_NAME_MESSAGE = 'Customer name is required for receivable invoices or invoices over 10000 JOD';

test('a 50,000 USD cash invoice without a buyer name is refused', function (?float $exchangeRate) {
    expect(fn () => cashInvoiceOf(50000, 'USD', $exchangeRate, false)->generateXml())
        ->toThrow(InvalidArgumentException::class, BUYER_NAME_MESSAGE);
})->with([
    'no exchange rate' => [null],
    'with exchange rate' => [0.709],
]);

test('a 50,000 USD cash invoice with a buyer name is accepted', function (?float $exchangeRate) {
    expect(cashInvoiceOf(50000, 'USD', $exchangeRate, true)->generateXml())
        ->toContain('<cbc:DocumentCurrencyCode>USD</cbc:DocumentCurrencyCode>');
})->with([null, 0.709]);

test('JOD cash invoices need a buyer name only above 10,000', function (float $amount, bool $nameRequired) {
    $invoice = cashInvoiceOf($amount, 'JOD', null, false);

    if ($nameRequired) {
        expect(fn () => $invoice->generateXml())->toThrow(InvalidArgumentException::class, BUYER_NAME_MESSAGE);
    } else {
        expect($invoice->generateXml())->toBeString();
    }
})->with([
    [1.0, false],
    [9999.999, false],
    [10000.0, false],
    [10000.001, true],
    [50000.0, true],
]);

test('foreign currency cash invoices use the exchange rate for the 10,000 JOD threshold', function (string $currency, float $amount, float $rate, bool $nameRequired) {
    $invoice = cashInvoiceOf($amount, $currency, $rate, false);

    if ($nameRequired) {
        expect(fn () => $invoice->generateXml())->toThrow(InvalidArgumentException::class, BUYER_NAME_MESSAGE);
    } else {
        expect($invoice->generateXml())->toBeString();
    }
})->with([
    // 14,000 USD x 0.71 = 9,940 JOD; 14,100 x 0.71 = 10,011 JOD
    'USD below' => ['USD', 14000.0, 0.71, false],
    'USD exactly 10,000 JOD' => ['USD', 20000.0, 0.5, false],
    'USD just above' => ['USD', 20000.01, 0.5, true],
    'USD above' => ['USD', 14100.0, 0.71, true],
    // A strong currency: 5,000 units are worth more than 10,000 JOD
    'KWD above with a small figure' => ['KWD', 5000.0, 2.3, true],
    'KWD below' => ['KWD', 4000.0, 2.3, false],
    // A weak currency: a large figure is worth little
    'JPY below with a large figure' => ['JPY', 50000.0, 0.0046, false],
    'JPY above' => ['JPY', 3000000.0, 0.0046, true],
]);

test('a foreign currency cash invoice without an exchange rate needs a buyer name whatever the amount', function () {
    // Without a rate the JOD equivalent is unknown, so the SDK cannot show the
    // invoice is under the threshold
    expect(fn () => cashInvoiceOf(10, 'USD', null, false)->generateXml())
        ->toThrow(InvalidArgumentException::class, 'Use setExchangeRate()')
        ->and(cashInvoiceOf(10, 'USD', null, true)->generateXml())->toBeString()
        ->and(cashInvoiceOf(10, 'USD', 0.709, false)->generateXml())->toBeString();
});

test('the buyer name rule is the same for credit invoices and every invoice type', function (string $invoiceType, bool $credit) {
    expect(fn () => cashInvoiceOf(50000, 'USD', 0.709, false, $credit, $invoiceType)->generateXml())
        ->toThrow(InvalidArgumentException::class, BUYER_NAME_MESSAGE)
        ->and(cashInvoiceOf(50000, 'USD', 0.709, true, $credit, $invoiceType)->generateXml())->toBeString()
        ->and(cashInvoiceOf(100, 'USD', 0.709, false, $credit, $invoiceType)->generateXml())->toBeString();
})->with([
    ['general_sales', false],
    ['general_sales', true],
    ['special_sales', false],
    ['special_sales', true],
    ['income', false],
    ['income', true],
]);

test('receivable invoices need a buyer name in any currency and amount', function (string $currency) {
    $invoice = reviewInvoice('general_sales', false);
    $invoice->basicInformation()->setCurrency($currency)->setExchangeRate(1.0)->receivable();
    $invoice->items()->addItem('1')->setQuantity(1)->setUnitPrice(1)->setDescription('Item')->zeroTax();
    $invoice->invoiceTotals();

    expect(fn () => $invoice->generateXml())->toThrow(InvalidArgumentException::class, BUYER_NAME_MESSAGE);
})->with(['JOD', 'USD']);

test('the exchange rate must be positive and is never sent', function () {
    $info = new BasicInvoiceInformation;

    expect(fn () => $info->setExchangeRate(0.0))->toThrow(InvalidArgumentException::class, 'Exchange rate must be greater than 0')
        ->and(fn () => $info->setExchangeRate(-1.0))->toThrow(InvalidArgumentException::class, 'Exchange rate must be greater than 0')
        ->and(cashInvoiceOf(100, 'USD', 0.709, true)->generateXml())->not->toContain('0.709');
});
