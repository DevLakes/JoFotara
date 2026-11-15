<?php

namespace JBadarneh\JoFotara\Response;

/**
 * Handles responses from the JoFotara API
 */
class JoFotaraResponse
{
    /**
     * Error categories reported by getErrors() for errors the SDK classifies
     * itself. Errors returned by the API keep the category the API gave them.
     */
    public const CATEGORY_API_VALIDATION = 'API Validation';

    public const CATEGORY_AUTHENTICATION = 'Authentication';

    public const CATEGORY_SERVER_ERROR = 'Server Error';

    public const CATEGORY_GATEWAY_TIMEOUT = 'Gateway Timeout';

    public const CATEGORY_HTTP = 'HTTP';

    public const CATEGORY_RESPONSE = 'Response';

    /**
     * HTTP status codes with a known meaning, per the JoFotara integration
     * guide. This is the single source for their error code, message and
     * category, used by fromHttpResponse() and getErrors() alike.
     *
     * A status marked "discardBody" is reported from this table alone: whatever
     * body the API returned is ignored.
     */
    private const STATUS_ERRORS = [
        403 => [
            'code' => 'AUTH_ERROR',
            'message' => 'Authentication failed. Please check your client ID and secret.',
            'category' => self::CATEGORY_AUTHENTICATION,
            'discardBody' => true,
        ],
        500 => [
            'code' => 'SERVER_ERROR',
            'message' => 'Internal server error. Check the seller TIN, the income source sequence, the client ID and secret, and that tax rates are among the approved rates',
            'category' => self::CATEGORY_SERVER_ERROR,
            'discardBody' => false,
        ],
        504 => [
            'code' => 'GATEWAY_TIMEOUT',
            'message' => 'Gateway timeout. JoFotara could not be reached; retry with the same invoice ID and UUID',
            'category' => self::CATEGORY_GATEWAY_TIMEOUT,
            'discardBody' => false,
        ],
    ];

    /**
     * The raw response data from the API
     */
    private array $rawResponse;

    /**
     * Indicates if the response represents a successful submission
     */
    private bool $success;

    /**
     * The HTTP status code from the API response
     */
    private int $statusCode;

    /**
     * Build a response from the raw HTTP body and status code returned by the API
     *
     * Never throws: a body that is empty, is not valid JSON or does not decode
     * to an object or array becomes an error response that keeps the body under
     * "raw_response".
     *
     * @param  string|false|null  $body  The raw response body
     * @param  int  $statusCode  The HTTP status code
     */
    public static function fromHttpResponse(string|false|null $body, int $statusCode): self
    {
        $known = self::STATUS_ERRORS[$statusCode] ?? null;

        if ($known !== null && $known['discardBody']) {
            return new self(['error' => $known['message'], 'code' => $known['code']], $statusCode);
        }

        $decoded = empty($body) ? null : json_decode($body, true);

        if (is_array($decoded)) {
            return new self($decoded, $statusCode);
        }

        return new self([
            'error' => match (true) {
                $known !== null => $known['message'],
                empty($body) => 'Empty response from API',
                json_last_error() !== JSON_ERROR_NONE => 'Invalid JSON response from API: '.json_last_error_msg(),
                default => 'Unexpected response from API',
            },
            'raw_response' => $body,
        ], $statusCode);
    }

    /**
     * Create a new JoFotaraResponse instance
     *
     * @param  array  $response  The raw response from the API
     * @param  int  $statusCode  The HTTP status code from the API response
     */
    public function __construct(array $response, int $statusCode = 200)
    {
        $this->rawResponse = $response;
        $this->statusCode = $statusCode;

        // If status code is not 200, it's automatically not a success
        if ($statusCode !== 200) {
            $this->success = false;

            return;
        }

        // Determine if this is a success or error response based on structure.
        // The integration guide requires the QR code to be present before a
        // submitted invoice is treated as received and approved.
        if (isset($response['validationResults'])) {
            $this->success = $response['validationResults']['status'] === 'PASS' &&
                             ($response['invoiceStatus'] === 'SUBMITTED' || $response['invoiceStatus'] === 'ALREADY_SUBMITTED') &&
                             $this->hasQrCode();
        } elseif (isset($response['EINV_RESULTS'])) {
            $this->success = $response['EINV_RESULTS']['status'] !== 'ERROR' &&
                             ($response['EINV_STATUS'] === 'SUBMITTED' || $response['EINV_STATUS'] === 'ALREADY_SUBMITTED') &&
                             $this->hasQrCode();
        } else {
            $this->success = false;
        }
    }

    /**
     * Check if the invoice submission was successful
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Get the raw response data
     */
    public function getRawResponse(): array
    {
        return $this->rawResponse;
    }

    /**
     * Get the invoice status (SUBMITTED, NOT_SUBMITTED, etc.)
     */
    public function getInvoiceStatus(): ?string
    {
        if (isset($this->rawResponse['invoiceStatus'])) {
            return $this->rawResponse['invoiceStatus'];
        }

        if (isset($this->rawResponse['EINV_STATUS'])) {
            return $this->rawResponse['EINV_STATUS'];
        }

        return null;
    }

    /**
     * Whether the invoice had already been submitted with the same ID and UUID.
     * The response still carries the QR code of the original submission, which
     * makes it safe to resend an invoice after a timeout.
     */
    public function isAlreadySubmitted(): bool
    {
        return $this->getInvoiceStatus() === 'ALREADY_SUBMITTED';
    }

    /**
     * Whether the response carries the QR code of the invoice. The integration
     * guide requires checking it before treating the invoice as accepted, and
     * printing it on the seller's invoice.
     */
    public function hasQrCode(): bool
    {
        $qrCode = $this->rawResponse['qrCode'] ?? $this->rawResponse['EINV_QR'] ?? null;

        // Only a non-blank string is a QR code
        return is_string($qrCode) && trim($qrCode) !== '';
    }

    /**
     * Get the submitted invoice in base64 format
     */
    public function getSubmittedInvoice(): ?string
    {
        return $this->rawResponse['submittedInvoice'] ??
               $this->rawResponse['EINV_SINGED_INVOICE'] ??
               null;
    }

    /**
     * Get the submitted invoice decoded from base64 to XML
     */
    public function getInvoiceAsXml(): ?string
    {
        $base64Invoice = $this->getSubmittedInvoice();
        if ($base64Invoice === null) {
            return null;
        }

        $decoded = base64_decode($base64Invoice, true);
        if ($decoded === false) {
            return null;
        }

        return $decoded;
    }

    /**
     * Get the QR code for the invoice
     */
    public function getQrCode(): ?string
    {
        return $this->rawResponse['qrCode'] ??
               $this->rawResponse['EINV_QR'] ??
               null;
    }

    /**
     * Get the invoice number assigned by the system
     */
    public function getInvoiceNumber(): ?string
    {
        return $this->rawResponse['invoiceNumber'] ??
               $this->rawResponse['EINV_NUM'] ??
               null;
    }

    /**
     * Get the invoice UUID assigned by the system
     */
    public function getInvoiceUuid(): ?string
    {
        return $this->rawResponse['invoiceUUID'] ??
               $this->rawResponse['EINV_INV_UUID'] ??
               null;
    }

    /**
     * Get the HTTP status code from the API response
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get all error messages from the response
     */
    public function getErrors(): array
    {
        $known = self::STATUS_ERRORS[$this->statusCode] ?? null;

        // Statuses reported from the table alone, e.g. authentication errors (403)
        if ($known !== null && $known['discardBody']) {
            return [[
                'code' => $this->rawResponse['code'] ?? $known['code'],
                'message' => $this->rawResponse['error'] ?? $known['message'],
                'category' => $known['category'],
            ]];
        }

        // Code and category for errors the API did not classify itself
        $class = $known ?? $this->unlistedStatusClass();

        if (isset($this->rawResponse['validationResults']['errorMessages'])) {
            return $this->withMissingQrCodeError($this->rawResponse['validationResults']['errorMessages']);
        }

        if (isset($this->rawResponse['EINV_RESULTS']['ERRORS'])) {
            return $this->withMissingQrCodeError($this->rawResponse['EINV_RESULTS']['ERRORS']);
        }

        // For error responses with status code 400 or specific error structure
        if (($this->statusCode === 400 || isset($this->rawResponse['error'])) && ! empty($this->rawResponse)) {
            // If there's a specific error structure, use it
            if (isset($this->rawResponse['error']) || isset($this->rawResponse['errors'])) {
                $error = isset($this->rawResponse['errors']) ? $this->rawResponse['errors'] : [[
                    'code' => $this->rawResponse['code'] ?? $class['code'],
                    'message' => $this->rawResponse['error'] ?? json_encode($this->rawResponse),
                    'category' => $class['category'],
                ]];

                return is_array($error) ? $error : [$error];
            }

            // If there's no specific error structure, create one from the response
            if (! isset($this->rawResponse['validationResults']) && ! isset($this->rawResponse['EINV_RESULTS'])) {
                return [[
                    'code' => 'API_ERROR',
                    'message' => json_encode($this->rawResponse),
                    'category' => self::CATEGORY_API_VALIDATION,
                ]];
            }
        }

        // A failed request must not look error-free: a non-200 status that came
        // with neither validation results nor error details is reported by its
        // status code
        if ($this->statusCode !== 200) {
            return [[
                'code' => $class['code'],
                'message' => $known['message'] ?? "Unexpected HTTP status code {$this->statusCode}",
                'category' => $class['category'],
            ]];
        }

        return $this->withMissingQrCodeError([]);
    }

    /**
     * Error code and category for a status that is not in STATUS_ERRORS: an API
     * validation error for 200 and 400, an unexpected HTTP response otherwise
     *
     * @return array{code: string, category: string}
     */
    private function unlistedStatusClass(): array
    {
        return in_array($this->statusCode, [200, 400], true)
            ? ['code' => 'API_ERROR', 'category' => self::CATEGORY_API_VALIDATION]
            : ['code' => 'HTTP_ERROR', 'category' => self::CATEGORY_HTTP];
    }

    /**
     * When the API reported the invoice as submitted without any error but the
     * response has no QR code, report that: the invoice is not a success, and
     * a failure must not look error-free
     */
    private function withMissingQrCodeError(array $errors): array
    {
        $submitted = in_array($this->getInvoiceStatus(), ['SUBMITTED', 'ALREADY_SUBMITTED'], true);

        if ($errors !== [] || $this->statusCode !== 200 || ! $submitted || $this->hasQrCode()) {
            return $errors;
        }

        return [[
            'code' => 'QR_CODE_MISSING',
            'message' => 'The invoice was reported as submitted but the response has no QR code',
            'category' => self::CATEGORY_RESPONSE,
        ]];
    }

    /**
     * Get all warning messages from the response
     */
    public function getWarnings(): array
    {
        if (isset($this->rawResponse['validationResults']['warningMessages'])) {
            return $this->rawResponse['validationResults']['warningMessages'];
        }

        if (isset($this->rawResponse['EINV_RESULTS']['WARNINGS'])) {
            return $this->rawResponse['EINV_RESULTS']['WARNINGS'];
        }

        return [];
    }

    /**
     * Get all info messages from the response
     */
    public function getInfoMessages(): array
    {
        if (isset($this->rawResponse['validationResults']['infoMessages'])) {
            return $this->rawResponse['validationResults']['infoMessages'];
        }

        if (isset($this->rawResponse['EINV_RESULTS']['INFO'])) {
            return $this->rawResponse['EINV_RESULTS']['INFO'];
        }

        return [];
    }

    /**
     * Get the validation status (PASS, ERROR, etc.)
     */
    public function getValidationStatus(): ?string
    {
        if (isset($this->rawResponse['validationResults']['status'])) {
            return $this->rawResponse['validationResults']['status'];
        }

        if (isset($this->rawResponse['EINV_RESULTS']['status'])) {
            return $this->rawResponse['EINV_RESULTS']['status'];
        }

        return null;
    }

    /**
     * Check if there are any errors in the response
     */
    public function hasErrors(): bool
    {
        return count($this->getErrors()) > 0;
    }

    /**
     * Check if there are any warnings in the response
     */
    public function hasWarnings(): bool
    {
        return count($this->getWarnings()) > 0;
    }

    /**
     * Get a formatted summary of errors, if any
     */
    public function getErrorSummary(): ?string
    {
        $errors = $this->getErrors();
        if (empty($errors)) {
            return null;
        }

        $summary = [];
        foreach ($errors as $error) {
            $code = $error['code'] ?? $error['EINV_CODE'] ?? 'UNKNOWN';
            $message = $error['message'] ?? $error['EINV_MESSAGE'] ?? 'Unknown error';
            $category = $error['category'] ?? $error['EINV_CATEGORY'] ?? 'Unknown category';

            $summary[] = "[{$code}] {$category}: {$message}";
        }

        return implode("\n", $summary);
    }
}
