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

test('a QR code that is blank or not a string does not count', function (mixed $qrCode) {
    $response = new JoFotaraResponse([
        'EINV_RESULTS' => ['status' => 'PASS', 'INFO' => [], 'WARNINGS' => [], 'ERRORS' => []],
        'EINV_STATUS' => 'SUBMITTED',
        'EINV_QR' => $qrCode,
    ], 200);

    expect($response->hasQrCode())->toBeFalse()
        ->and($response->isSuccess())->toBeFalse()
        ->and($response->getErrors()[0]['code'])->toBe('QR_CODE_MISSING');
})->with([
    'spaces' => ['   '],
    'newline' => ["\n"],
    'tab' => ["\t "],
    'false' => [false],
    'array' => [[]],
    'non-empty array' => [['qr']],
]);

test('a malformed submitted response is never a success', function (array $body) {
    $response = new JoFotaraResponse($body, 200);

    expect($response->isSuccess())->toBeFalse();
})->with([
    'no validation results' => [['EINV_STATUS' => 'SUBMITTED', 'EINV_QR' => 'qr']],
    'no validation results, no QR' => [['EINV_STATUS' => 'SUBMITTED']],
    'validation errors with QR' => [['EINV_RESULTS' => ['status' => 'ERROR', 'ERRORS' => [['EINV_CODE' => 'x']]], 'EINV_STATUS' => 'SUBMITTED', 'EINV_QR' => 'qr']],
    'unknown status with QR' => [['EINV_RESULTS' => ['status' => 'PASS', 'ERRORS' => []], 'EINV_STATUS' => 'PENDING', 'EINV_QR' => 'qr']],
    'empty body' => [[]],
]);

test('API errors are reported in preference to the missing QR code', function () {
    $apiError = ['type' => 'ERROR', 'status' => 'ERROR', 'EINV_CODE' => 'x', 'EINV_CATEGORY' => 'invoice', 'EINV_MESSAGE' => 'Wrong'];
    $response = new JoFotaraResponse([
        'EINV_RESULTS' => ['status' => 'ERROR', 'INFO' => [], 'WARNINGS' => [], 'ERRORS' => [$apiError]],
        'EINV_STATUS' => 'SUBMITTED',
    ], 200);

    expect($response->isSuccess())->toBeFalse()->and($response->getErrors())->toBe([$apiError]);
});

test('a failed HTTP status is not a success even with a submitted status and a QR code', function (int $statusCode, string $code) {
    $body = json_encode([
        'EINV_RESULTS' => ['status' => 'PASS', 'INFO' => [], 'WARNINGS' => [], 'ERRORS' => []],
        'EINV_STATUS' => 'SUBMITTED',
        'EINV_QR' => 'qr',
    ]);
    $response = JoFotaraResponse::fromHttpResponse($body, $statusCode);

    expect($response->isSuccess())->toBeFalse()
        ->and($response->getStatusCode())->toBe($statusCode)
        ->and($response->getErrorSummary() ?? '')->not->toContain('QR_CODE_MISSING');

    if ($code !== '') {
        expect($response->getErrors()[0]['code'])->toBe($code);
    }
})->with([
    [400, ''],
    [403, 'AUTH_ERROR'],
    [500, ''],
    [504, ''],
    [502, ''],
]);

test('a submitted response with its QR code through the HTTP path is a success', function (string $status) {
    $response = JoFotaraResponse::fromHttpResponse(json_encode([
        'EINV_RESULTS' => ['status' => 'PASS', 'INFO' => [], 'WARNINGS' => [], 'ERRORS' => []],
        'EINV_STATUS' => $status,
        'EINV_QR' => 'AQACAnt9AwVmYWxzZQ==',
    ]), 200);

    expect($response->isSuccess())->toBeTrue()
        ->and($response->getQrCode())->toBe('AQACAnt9AwVmYWxzZQ==')
        ->and($response->getErrors())->toBe([])
        ->and($response->isAlreadySubmitted())->toBe($status === 'ALREADY_SUBMITTED');
})->with(['SUBMITTED', 'ALREADY_SUBMITTED']);
