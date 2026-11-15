<?php

use JBadarneh\JoFotara\JoFotaraService;
use JBadarneh\JoFotara\Response\JoFotaraResponse;

// Review finding 6: error categories

test('each kind of failure has its own error code and category', function (int $statusCode, string|false|null $body, string $code, string $category, string $message) {
    $response = JoFotaraResponse::fromHttpResponse($body, $statusCode);

    expect($response->isSuccess())->toBeFalse()
        ->and($response->getErrors())->toBe([['code' => $code, 'message' => $message, 'category' => $category]]);
})->with([
    '400 json error' => [400, '{"error":"Bad request"}', 'API_ERROR', 'API Validation', 'Bad request'],
    '400 unstructured' => [400, '{"foo":"bar"}', 'API_ERROR', 'API Validation', '{"foo":"bar"}'],
    '403' => [403, '{"error":"Forbidden"}', 'AUTH_ERROR', 'Authentication', 'Authentication failed. Please check your client ID and secret.'],
    '500 malformed' => [500, '<html>', 'SERVER_ERROR', 'Server Error', 'Internal server error. Check the seller TIN, the income source sequence, the client ID and secret, and that tax rates are among the approved rates'],
    '500 json error' => [500, '{"error":"Boom"}', 'SERVER_ERROR', 'Server Error', 'Boom'],
    '504 empty' => [504, '', 'GATEWAY_TIMEOUT', 'Gateway Timeout', 'Gateway timeout. JoFotara could not be reached; retry with the same invoice ID and UUID'],
    '504 json without details' => [504, '{"path":"/"}', 'GATEWAY_TIMEOUT', 'Gateway Timeout', 'Gateway timeout. JoFotara could not be reached; retry with the same invoice ID and UUID'],
    '502 malformed' => [502, '<html>', 'HTTP_ERROR', 'HTTP', 'Invalid JSON response from API: Syntax error'],
    '502 json error' => [502, '{"error":"Bad gateway"}', 'HTTP_ERROR', 'HTTP', 'Bad gateway'],
    '429 json without details' => [429, '{"path":"/"}', 'HTTP_ERROR', 'HTTP', 'Unexpected HTTP status code 429'],
    '200 malformed' => [200, '<html>', 'API_ERROR', 'API Validation', 'Invalid JSON response from API: Syntax error'],
    '200 empty' => [200, '', 'API_ERROR', 'API Validation', 'Empty response from API'],
]);

test('the error categories are published as constants', function () {
    expect(JoFotaraResponse::CATEGORY_API_VALIDATION)->toBe('API Validation')
        ->and(JoFotaraResponse::CATEGORY_AUTHENTICATION)->toBe('Authentication')
        ->and(JoFotaraResponse::CATEGORY_SERVER_ERROR)->toBe('Server Error')
        ->and(JoFotaraResponse::CATEGORY_GATEWAY_TIMEOUT)->toBe('Gateway Timeout')
        ->and(JoFotaraResponse::CATEGORY_HTTP)->toBe('HTTP')
        ->and(JoFotaraResponse::CATEGORY_RESPONSE)->toBe('Response');
});

test('a transport failure is an exception, not a response', function () {
    $service = new class('client-id', 'client-secret') extends JoFotaraService
    {
        protected function executeRequest(string $url, array $headers, string $body): array
        {
            return [false, 0, 'Operation timed out after 30000 milliseconds'];
        }
    };
    $service->basicInformation()->setInvoiceId('INV-001')->setUuid('123e4567-e89b-12d3-a456-426614174000')->setIssueDate('16-02-2025')->setInvoiceType('income')->cash();
    $service->sellerInformation()->setTin('12345678')->setName('Seller');
    $service->supplierIncomeSource('123');
    $service->items()->addItem('1')->setQuantity(1)->setUnitPrice(10)->setDescription('Item')->tax(16);
    $service->invoiceTotals();

    expect(fn () => $service->send())
        ->toThrow(RuntimeException::class, 'Failed to send invoice: Operation timed out after 30000 milliseconds');
});

test('an error code sent by the API wins over the table', function () {
    $response = JoFotaraResponse::fromHttpResponse('{"error":"Boom","code":"E42"}', 500);

    expect($response->getErrors())->toBe([['code' => 'E42', 'message' => 'Boom', 'category' => 'Server Error']]);
});
