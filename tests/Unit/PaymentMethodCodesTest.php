<?php

use JBadarneh\JoFotara\Sections\BasicInvoiceInformation;

/**
 * The 36 codes of the integration guide v1.5, written out by hand so that the
 * test does not depend on the tables it checks
 */
function guidePaymentCodes(): array
{
    return [
        // [code, invoice type, transaction type, receivable]
        ['011', 'income', 'local', false], ['021', 'income', 'local', true],
        ['111', 'income', 'export', false], ['121', 'income', 'export', true],
        ['211', 'income', 'development_zone', false], ['221', 'income', 'development_zone', true],
        ['311', 'income', 'transit', false], ['321', 'income', 'transit', true],
        ['411', 'income', 'foreign_trade', false], ['421', 'income', 'foreign_trade', true],
        ['511', 'income', 'free_zone_transfer', false], ['521', 'income', 'free_zone_transfer', true],
        ['012', 'general_sales', 'local', false], ['022', 'general_sales', 'local', true],
        ['112', 'general_sales', 'export', false], ['122', 'general_sales', 'export', true],
        ['212', 'general_sales', 'development_zone', false], ['222', 'general_sales', 'development_zone', true],
        ['312', 'general_sales', 'transit', false], ['322', 'general_sales', 'transit', true],
        ['412', 'general_sales', 'foreign_trade', false], ['422', 'general_sales', 'foreign_trade', true],
        ['512', 'general_sales', 'free_zone_transfer', false], ['522', 'general_sales', 'free_zone_transfer', true],
        ['013', 'special_sales', 'local', false], ['023', 'special_sales', 'local', true],
        ['113', 'special_sales', 'export', false], ['123', 'special_sales', 'export', true],
        ['213', 'special_sales', 'development_zone', false], ['223', 'special_sales', 'development_zone', true],
        ['313', 'special_sales', 'transit', false], ['323', 'special_sales', 'transit', true],
        ['413', 'special_sales', 'foreign_trade', false], ['423', 'special_sales', 'foreign_trade', true],
        ['513', 'special_sales', 'free_zone_transfer', false], ['523', 'special_sales', 'free_zone_transfer', true],
    ];
}

test('the supported codes are exactly the 36 codes of the guide', function () {
    $expected = array_column(guidePaymentCodes(), 0);
    $actual = BasicInvoiceInformation::getSupportedPaymentMethods();

    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected)->toHaveCount(36);
});

test('every supported code is accepted and decoded', function (string $code, string $invoiceType, string $transactionType, bool $receivable) {
    $info = (new BasicInvoiceInformation)->setInvoiceType($invoiceType)->setPaymentMethod($code);

    expect($info->getPaymentMethod())->toBe($code)
        ->and($info->getTransactionType())->toBe($transactionType)
        ->and($info->isReceivable())->toBe($receivable)
        ->and($info->toArray()['paymentMethod'])->toBe($code);
})->with(guidePaymentCodes());

test('every supported code is produced by the fluent setters', function (string $code, string $invoiceType, string $transactionType, bool $receivable) {
    $info = (new BasicInvoiceInformation)->setInvoiceType($invoiceType)->setTransactionType($transactionType);
    $receivable ? $info->receivable() : $info->cash();

    expect($info->getPaymentMethod())->toBe($code);
})->with(guidePaymentCodes());

test('every supported code is rejected for the other invoice types', function (string $code, string $invoiceType) {
    foreach (array_diff(['income', 'general_sales', 'special_sales'], [$invoiceType]) as $otherType) {
        $info = (new BasicInvoiceInformation)->setInvoiceType($otherType);

        expect(fn () => $info->setPaymentMethod($code))->toThrow(
            InvalidArgumentException::class,
            "Payment method '$code' is not valid for invoice type '$otherType'"
        );
    }
})->with(guidePaymentCodes());

test('the legacy INVOICE_TYPES constant still holds the local codes', function () {
    expect(BasicInvoiceInformation::INVOICE_TYPES)->toBe([
        'income' => ['cash' => '011', 'receivable' => '021'],
        'general_sales' => ['cash' => '012', 'receivable' => '022'],
        'special_sales' => ['cash' => '013', 'receivable' => '023'],
    ]);

    foreach (BasicInvoiceInformation::INVOICE_TYPES as $invoiceType => $codes) {
        expect((new BasicInvoiceInformation)->setInvoiceType($invoiceType)->cash()->getPaymentMethod())->toBe($codes['cash'])
            ->and((new BasicInvoiceInformation)->setInvoiceType($invoiceType)->receivable()->getPaymentMethod())->toBe($codes['receivable']);
    }
});

test('invalid, malformed and look-alike codes are rejected', function (string $code) {
    $info = (new BasicInvoiceInformation)->setInvoiceType('general_sales')->cash();

    expect(fn () => $info->setPaymentMethod($code))->toThrow(
        InvalidArgumentException::class,
        'Payment method must be one of: 011, 021 (Income), 012, 022 (General Sales), 013, 023 (Special Sales), or their export (1xx), development zone (2xx), transit (3xx), foreign trade (4xx) and free zone transfer (5xx) variants'
    )
        // A rejected code leaves the previous state untouched
        ->and($info->getPaymentMethod())->toBe('012')
        ->and($info->getTransactionType())->toBe('local');
})->with([
    'empty' => [''],
    'too short' => ['12'],
    'missing leading zero' => ['22'],
    'too long' => ['0122'],
    'two codes' => ['012022'],
    'trailing newline' => ["012\n"],
    'leading newline' => ["\n012"],
    'trailing space' => ['012 '],
    'leading space' => [' 012'],
    'trailing null byte' => ["012\0"],
    'unknown transaction digit' => ['612'],
    'unknown transaction digit 9' => ['912'],
    'payment digit 0' => ['002'],
    'payment digit 3' => ['032'],
    'tax digit 0' => ['010'],
    'tax digit 4' => ['014'],
    'letters' => ['abc'],
    'mixed' => ['01a'],
    'signed' => ['+12'],
    'negative' => ['-12'],
    'decimal' => ['112.0'],
    'float-like' => ['1.2'],
    'exponent' => ['1e2'],
    'hex' => ['0x70'],
    'octal-like padding' => ['0112'],
    'arabic-indic digits' => ['٠١٢'],
    'fullwidth digits' => ['０１２'],
]);

test('the invoice type is still required before a payment method', function () {
    expect(fn () => (new BasicInvoiceInformation)->setPaymentMethod('012'))->toThrow(
        InvalidArgumentException::class,
        'Invoice type must be set before setting payment method. Use setInvoiceType() first.'
    );
});
