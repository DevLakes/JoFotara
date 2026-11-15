<?php

use JBadarneh\JoFotara\Response\JoFotaraResponse;

// Review finding 5: success requires the QR code (guide v1.5, response section)

test('a submitted invoice is a success only with its QR code', function (string $status, mixed $qrCode, bool $success) {
    $body = [
        'EINV_RESULTS' => ['status' => 'PASS', 'INFO' => [], 'WARNINGS' => [], 'ERRORS' => []],
        'EINV_STATUS' => $status,
        'EINV_SINGED_INVOICE' => 'abc',
        'EINV_NUM' => 'EIN000123456',
        'EINV_INV_UUID' => '123e4567-e89b-12d3-a456-426614174000',
    ];
    if ($qrCode !== 'absent') {
        $body['EINV_QR'] = $qrCode;
    }

    $response = new JoFotaraResponse($body, 200);

    expect($response->isSuccess())->toBe($success)
        ->and($response->hasQrCode())->toBe($success)
        ->and($response->getInvoiceStatus())->toBe($status);

    if ($success) {
        expect($response->getErrors())->toBe([])->and($response->hasErrors())->toBeFalse();
    } else {
        expect($response->getErrors())->toBe([[
            'code' => 'QR_CODE_MISSING',
            'message' => 'The invoice was reported as submitted but the response has no QR code',
            'category' => 'Response',
        ]]);
    }
})->with([
    ['SUBMITTED', 'qr-data', true],
    ['ALREADY_SUBMITTED', 'qr-data', true],
    ['SUBMITTED', null, false],
    ['SUBMITTED', '', false],
    ['SUBMITTED', 'absent', false],
    ['ALREADY_SUBMITTED', null, false],
    ['ALREADY_SUBMITTED', 'absent', false],
]);

test('the legacy response format also needs its QR code', function () {
    $body = [
        'validationResults' => ['infoMessages' => [], 'warningMessages' => [], 'errorMessages' => [], 'status' => 'PASS'],
        'invoiceStatus' => 'SUBMITTED',
        'submittedInvoice' => 'abc',
        'invoiceNumber' => 'EIN00032',
        'invoiceUUID' => '6c13d4a6-8fa9-46b4-b9e0-fb74b7c3f44a',
    ];

    expect((new JoFotaraResponse($body, 200))->isSuccess())->toBeFalse()
        ->and((new JoFotaraResponse($body + ['qrCode' => 'qr'], 200))->isSuccess())->toBeTrue();
});

test('a rejected invoice is unaffected by the QR code rule', function () {
    $apiError = ['type' => 'ERROR', 'status' => 'ERROR', 'EINV_CODE' => 'x', 'EINV_CATEGORY' => 'invoice', 'EINV_MESSAGE' => 'Wrong'];
    $response = new JoFotaraResponse([
        'EINV_RESULTS' => ['status' => 'ERROR', 'INFO' => [], 'WARNINGS' => [], 'ERRORS' => [$apiError]],
        'EINV_STATUS' => 'NOT_SUBMITTED',
        'EINV_QR' => null,
    ], 400);

    expect($response->isSuccess())->toBeFalse()->and($response->getErrors())->toBe([$apiError]);
});
