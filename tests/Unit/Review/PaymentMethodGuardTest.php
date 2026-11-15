<?php

use JBadarneh\JoFotara\Sections\BasicInvoiceInformation;

// Review finding 8: the invoice type guard in setPaymentMethod() is kept

test('without an invoice type every payment method call reports the missing type first', function (callable $call) {
    expect(fn () => $call(new BasicInvoiceInformation))->toThrow(
        InvalidArgumentException::class,
        'Invoice type must be set before setting payment method. Use setInvoiceType() first.'
    );
})->with([
    'valid code' => [fn ($info) => $info->setPaymentMethod('012')],
    'invalid code' => [fn ($info) => $info->setPaymentMethod('999')],
    'empty code' => [fn ($info) => $info->setPaymentMethod('')],
    'cash' => [fn ($info) => $info->cash()],
    'receivable' => [fn ($info) => $info->receivable()],
]);
