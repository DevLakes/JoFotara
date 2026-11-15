<?php

use JBadarneh\JoFotara\JoFotaraService;
use JBadarneh\JoFotara\Response\JoFotaraResponse;

const AUTH_MESSAGE = 'Authentication failed. Please check your client ID and secret.';
const SERVER_ERROR_MESSAGE = 'Internal server error. Check the seller TIN, the income source sequence, the client ID and secret, and that tax rates are among the approved rates';
const GATEWAY_TIMEOUT_MESSAGE = 'Gateway timeout. JoFotara could not be reached; retry with the same invoice ID and UUID';

/**
 * Send a minimal valid invoice through a service whose HTTP call is replaced
 * by the given body, status code and transport error
 */
function sendWithHttpResult(string|false|null $body, int $statusCode, ?string $transportError = null): JoFotaraResponse
{
    $service = new class('client-id', 'client-secret') extends JoFotaraService
    {
        public array $httpResult = [];

        protected function executeRequest(string $url, array $headers, string $body): array
        {
            return $this->httpResult;
        }
    };
    $service->httpResult = [$body, $statusCode, $transportError];

    $service->basicInformation()
        ->setInvoiceId('INV-001')
        ->setUuid('123e4567-e89b-12d3-a456-426614174000')
        ->setIssueDate('16-02-2025')
        ->setInvoiceType('income')
        ->cash();
    $service->sellerInformation()->setTin('12345678')->setName('Seller');
    $service->supplierIncomeSource('123');
    $service->items()->addItem('1')->setQuantity(1)->setUnitPrice(10)->setDescription('Item')->tax(16);
    $service->invoiceTotals();

    return $service->send();
}

// 403

test('403 always yields the authentication error, whatever the body', function (string|false|null $body) {
    $response = sendWithHttpResult($body, 403);

    expect($response->isSuccess())->toBeFalse()
        ->and($response->getStatusCode())->toBe(403)
        ->and($response->getRawResponse())->toBe(['error' => AUTH_MESSAGE, 'code' => 'AUTH_ERROR'])
        ->and($response->getErrors())->toBe([[
            'code' => 'AUTH_ERROR',
            'message' => AUTH_MESSAGE,
            'category' => 'Authentication',
        ]])
        ->and($response->getErrorSummary())->toBe('[AUTH_ERROR] Authentication: '.AUTH_MESSAGE);
})->with([
    'json body' => [json_encode(['error' => 'Forbidden'])],
    'validation results in body' => [json_encode(['EINV_RESULTS' => ['status' => 'ERROR', 'ERRORS' => [['EINV_CODE' => 'x']]]])],
    'html body' => ['<html>403 Forbidden</html>'],
    'empty body' => [''],
    'no body' => [null],
    'failed read' => [false],
]);

test('a 403 response built by hand keeps its existing error shape', function () {
    $default = new JoFotaraResponse([], 403);
    $custom = new JoFotaraResponse(['error' => 'Custom message', 'code' => 'CUSTOM'], 403);

    expect($default->getErrors())->toBe([['code' => 'AUTH_ERROR', 'message' => AUTH_MESSAGE, 'category' => 'Authentication']])
        ->and($custom->getErrors())->toBe([['code' => 'CUSTOM', 'message' => 'Custom message', 'category' => 'Authentication']]);
});

// 500 and 504

test('500 and 504 without a usable body yield their guide message', function (int $statusCode, string $message, string|false|null $body) {
    [$code, $category] = $statusCode === 500 ? ['SERVER_ERROR', 'Server Error'] : ['GATEWAY_TIMEOUT', 'Gateway Timeout'];

    $response = sendWithHttpResult($body, $statusCode);

    expect($response->isSuccess())->toBeFalse()
        ->and($response->getStatusCode())->toBe($statusCode)
        ->and($response->getRawResponse())->toBe(['error' => $message, 'raw_response' => $body])
        ->and($response->getErrors())->toBe([[
            'code' => $code,
            'message' => $message,
            'category' => $category,
        ]]);
})->with([
    '500 empty' => [500, SERVER_ERROR_MESSAGE, ''],
    '500 html' => [500, SERVER_ERROR_MESSAGE, '<html>Internal Server Error</html>'],
    '500 null' => [500, SERVER_ERROR_MESSAGE, null],
    '504 empty' => [504, GATEWAY_TIMEOUT_MESSAGE, ''],
    '504 html' => [504, GATEWAY_TIMEOUT_MESSAGE, '<html>Gateway Time-out</html>'],
    '504 false' => [504, GATEWAY_TIMEOUT_MESSAGE, false],
]);

test('500 with validation results in the body reports those errors', function () {
    $apiError = ['type' => 'ERROR', 'status' => 'ERROR', 'EINV_CODE' => 'taxPercent', 'EINV_CATEGORY' => 'invoice', 'EINV_MESSAGE' => 'Invalid tax rate'];
    $body = ['EINV_RESULTS' => ['status' => 'ERROR', 'INFO' => [], 'WARNINGS' => [], 'ERRORS' => [$apiError]], 'EINV_STATUS' => 'NOT_SUBMITTED'];

    $response = sendWithHttpResult(json_encode($body), 500);

    expect($response->isSuccess())->toBeFalse()
        ->and($response->getRawResponse())->toBe($body)
        ->and($response->getErrors())->toBe([$apiError])
        ->and($response->getInvoiceStatus())->toBe('NOT_SUBMITTED');
});

test('500 and 504 with a JSON body that has no error details still report an error', function (int $statusCode, string $message, string $code, string $category) {
    $response = sendWithHttpResult('{"path":"/core/invoices/"}', $statusCode);

    expect($response->getRawResponse())->toBe(['path' => '/core/invoices/'])
        ->and($response->getErrors())->toBe([['code' => $code, 'message' => $message, 'category' => $category]]);
})->with([
    [500, SERVER_ERROR_MESSAGE, 'SERVER_ERROR', 'Server Error'],
    [504, GATEWAY_TIMEOUT_MESSAGE, 'GATEWAY_TIMEOUT', 'Gateway Timeout'],
]);

// Unknown and unexpected statuses

test('unknown statuses with an unusable body keep the existing messages', function (int $statusCode, string|false|null $body, string $message) {
    $response = sendWithHttpResult($body, $statusCode);

    expect($response->isSuccess())->toBeFalse()
        ->and($response->getRawResponse())->toBe(['error' => $message, 'raw_response' => $body])
        ->and($response->getErrors())->toBe([$statusCode === 200
            ? ['code' => 'API_ERROR', 'message' => $message, 'category' => 'API Validation']
            : ['code' => 'HTTP_ERROR', 'message' => $message, 'category' => 'HTTP'],
        ]);
})->with([
    '502 empty' => [502, '', 'Empty response from API'],
    '503 null' => [503, null, 'Empty response from API'],
    '200 empty' => [200, '', 'Empty response from API'],
    '502 html' => [502, '<html>Bad Gateway</html>', 'Invalid JSON response from API: Syntax error'],
    '200 truncated json' => [200, '{"EINV_STATUS":', 'Invalid JSON response from API: Syntax error'],
]);

test('a body that is valid JSON but not an object does not crash', function (string $body) {
    $response = sendWithHttpResult($body, 200);

    expect($response->isSuccess())->toBeFalse()
        ->and($response->getRawResponse())->toBe(['error' => 'Unexpected response from API', 'raw_response' => $body])
        ->and($response->hasErrors())->toBeTrue();
})->with(['null', '123', '"Service unavailable"', 'true']);

test('an unknown failing status without error details is reported by its code', function (int $statusCode) {
    $response = sendWithHttpResult('{"path":"/core/invoices/"}', $statusCode);

    expect($response->isSuccess())->toBeFalse()
        ->and($response->getErrors())->toBe([[
            'code' => 'HTTP_ERROR',
            'message' => "Unexpected HTTP status code {$statusCode}",
            'category' => 'HTTP',
        ]]);
})->with([401, 404, 429, 502, 503]);

test('400 responses are unchanged', function () {
    $apiError = ['type' => 'ERROR', 'status' => 'ERROR', 'EINV_CODE' => 'totalGeneralTaxesAmount', 'EINV_CATEGORY' => 'invoice', 'EINV_MESSAGE' => 'Total General Amount is Not Correct'];
    $withResults = sendWithHttpResult(json_encode(['EINV_RESULTS' => ['status' => 'ERROR', 'ERRORS' => [$apiError]], 'EINV_STATUS' => 'NOT_SUBMITTED']), 400);
    $withError = sendWithHttpResult('{"error":"Bad request","code":"BAD"}', 400);
    $bare = sendWithHttpResult('{"foo":"bar"}', 400);

    expect($withResults->getErrors())->toBe([$apiError])
        ->and($withError->getErrors())->toBe([['code' => 'BAD', 'message' => 'Bad request', 'category' => 'API Validation']])
        ->and($bare->getErrors())->toBe([['code' => 'API_ERROR', 'message' => '{"foo":"bar"}', 'category' => 'API Validation']]);
});

test('a successful 200 response is unchanged and has no errors', function () {
    $response = sendWithHttpResult(json_encode([
        'EINV_RESULTS' => ['status' => 'PASS', 'INFO' => [], 'WARNINGS' => [], 'ERRORS' => []],
        'EINV_STATUS' => 'SUBMITTED',
        'EINV_QR' => 'qr',
    ]), 200);

    expect($response->isSuccess())->toBeTrue()
        ->and($response->getErrors())->toBe([])
        ->and($response->getQrCode())->toBe('qr');
});

test('a transport failure still throws', function () {
    expect(fn () => sendWithHttpResult(false, 0, 'Could not resolve host'))
        ->toThrow(RuntimeException::class, 'Failed to send invoice: Could not resolve host');
});
