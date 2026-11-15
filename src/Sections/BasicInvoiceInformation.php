<?php

namespace JBadarneh\JoFotara\Sections;

use DateTime;
use InvalidArgumentException;
use JBadarneh\JoFotara\Contracts\ValidatableSection;
use JBadarneh\JoFotara\Traits\WithValidationConfigs;
use JBadarneh\JoFotara\Traits\XmlHelperTrait;

class BasicInvoiceInformation implements ValidatableSection
{
    use WithValidationConfigs, XmlHelperTrait;

    /**
     * Invoice type constants mapping to payment method codes
     *
     * - Income Invoice: For taxpayers not registered for sales tax
     * - General Sales Invoice: For taxpayers registered for sales tax (VAT)
     * - Special Sales Invoice: For taxpayers subject to special sales tax
     *
     * Local codes only; kept for backward compatibility. The code is now built
     * from the transaction type, payment kind and tax type (see getPaymentMethod()).
     */
    public const INVOICE_TYPES = [
        'income' => ['cash' => '011', 'receivable' => '021'],
        'general_sales' => ['cash' => '012', 'receivable' => '022'],
        'special_sales' => ['cash' => '013', 'receivable' => '023'],
    ];

    /**
     * Transaction types (first digit of the InvoiceTypeCode "name" attribute),
     * as defined in the JoFotara integration guide v1.5.
     */
    public const TRANSACTION_TYPES = [
        'local' => '0',
        'export' => '1',
        'development_zone' => '2',
        'transit' => '3',
        'foreign_trade' => '4',
        'free_zone_transfer' => '5',
    ];

    /**
     * Currencies accepted by JoFotara (integration guide v1.5). The currency
     * applies to the whole invoice.
     */
    public const SUPPORTED_CURRENCIES = [
        'JOD', 'USD', 'EUR', 'SAR', 'AED', 'OMR', 'GBP', 'QAR', 'KWD',
        'BHD', 'AUD', 'CAD', 'JPY', 'CHF', 'TRY', 'SYP', 'EGP', 'SEK',
    ];

    /**
     * Payment kinds (second digit of the InvoiceTypeCode "name" attribute)
     */
    private const PAYMENT_KINDS = ['cash' => '1', 'receivable' => '2'];

    /**
     * Tax types (third digit of the InvoiceTypeCode "name" attribute)
     */
    private const TAX_TYPE_DIGITS = ['income' => '1', 'general_sales' => '2', 'special_sales' => '3'];

    private string $invoiceId;

    private string $uuid;

    private DateTime $issueDate;

    private ?string $paymentKind = null;

    private string $transactionType = 'local';

    private ?string $invoiceType = null;

    private ?string $note = null;

    private string $currency = 'JOD';

    private ?float $exchangeRate = null;

    private int $invoiceCounter = 1;

    private bool $isCreditInvoice = false;

    private ?string $originalInvoiceId = null;

    private ?string $originalInvoiceUuid = null;

    private ?float $originalFullAmount = null;

    /**
     * Set the invoice ID (required)
     *
     * @param  string  $invoiceId  The unique identifier for this invoice
     */
    public function setInvoiceId(string $invoiceId): self
    {
        $this->invoiceId = $invoiceId;

        return $this;
    }

    /**
     * Set the invoice UUID (required)
     * This should be a unique identifier that, combined with the invoice ID,
     * forms a primary key to prevent invoice duplication
     *
     * @param  string  $uuid  The UUID for this invoice
     */
    public function setUuid(string $uuid): self
    {
        $this->uuid = $uuid;

        return $this;
    }

    /**
     * Set the invoice issue date (required)
     * The date must be in the format dd-mm-yyyy
     *
     * @param  string|DateTime  $date  The invoice issue date
     *
     * @throws InvalidArgumentException If the date format is invalid and validations are enabled
     */
    public function setIssueDate(string|DateTime $date): self
    {
        if (is_string($date)) {
            $dateTime = DateTime::createFromFormat('d-m-Y', $date);
            if ($this->validationsEnabled && ! $dateTime) {
                throw new InvalidArgumentException('Date must be in the format dd-mm-yyyy');
            }
            $this->issueDate = $dateTime ?: new DateTime;
        } else {
            $this->issueDate = $date;
        }

        return $this;
    }

    /**
     * Set the invoice type
     *
     * @param  string  $type  The invoice type ('income', 'general_sales', or 'special_sales')
     *
     * @throws InvalidArgumentException If the invoice type is invalid and validations are enabled
     */
    public function setInvoiceType(string $type): self
    {
        if ($this->validationsEnabled && ! array_key_exists($type, self::TAX_TYPE_DIGITS)) {
            throw new InvalidArgumentException("Invoice type must be one of: 'income', 'general_sales', 'special_sales'");
        }
        $this->invoiceType = $type;

        return $this;
    }

    /**
     * Get the invoice type ('income', 'general_sales' or 'special_sales')
     */
    public function getInvoiceType(): ?string
    {
        return $this->invoiceType;
    }

    /**
     * Set the transaction type of the invoice. Defaults to 'local'.
     *
     * @param  string  $type  One of: local, export, development_zone, transit, foreign_trade, free_zone_transfer
     *
     * @throws InvalidArgumentException If the transaction type is invalid
     */
    public function setTransactionType(string $type): self
    {
        if (! array_key_exists($type, self::TRANSACTION_TYPES)) {
            throw new InvalidArgumentException('Transaction type must be one of: '.implode(', ', array_keys(self::TRANSACTION_TYPES)));
        }

        $this->transactionType = $type;

        return $this;
    }

    /**
     * Get the transaction type of the invoice
     */
    public function getTransactionType(): string
    {
        return $this->transactionType;
    }

    /**
     * Whether the invoice is paid on account (receivable) rather than in cash
     */
    public function isReceivable(): bool
    {
        return $this->paymentKind === 'receivable';
    }

    /**
     * Get the payment method code (the InvoiceTypeCode "name" attribute),
     * e.g. 012 for a local cash general sales invoice
     *
     * @return string|null The payment method code, or null if it is not set yet
     */
    public function getPaymentMethod(): ?string
    {
        if ($this->paymentKind === null || ! isset(self::TAX_TYPE_DIGITS[$this->invoiceType ?? ''])) {
            return null;
        }

        return self::TRANSACTION_TYPES[$this->transactionType]
            .self::PAYMENT_KINDS[$this->paymentKind]
            .self::TAX_TYPE_DIGITS[$this->invoiceType];
    }

    /**
     * Set the payment method using its three-digit code
     *
     * The code is made of the transaction type (0 local, 1 export, 2 development
     * zone, 3 transit, 4 foreign trade, 5 free zone transfer), the payment kind
     * (1 cash, 2 receivable) and the tax type (1 income, 2 general sales,
     * 3 special sales). Setting it also sets the transaction type.
     *
     * @param  string  $method  The payment method code, e.g. 012 or 322
     *
     * @throws InvalidArgumentException If the payment method is invalid or doesn't match the invoice type
     */
    public function setPaymentMethod(string $method): self
    {
        if ($this->invoiceType === null) {
            throw new InvalidArgumentException('Invoice type must be set before setting payment method. Use setInvoiceType() first.');
        }

        $parts = self::paymentMethodCodes()[$method] ?? null;

        if ($parts === null) {
            throw new InvalidArgumentException('Payment method must be one of: 011, 021 (Income), 012, 022 (General Sales), 013, 023 (Special Sales), or their export (1xx), development zone (2xx), transit (3xx), foreign trade (4xx) and free zone transfer (5xx) variants');
        }

        // Validate that the payment method is valid for the current invoice type
        if ($parts['invoiceType'] !== $this->invoiceType) {
            throw new InvalidArgumentException("Payment method '$method' is not valid for invoice type '{$this->invoiceType}'");
        }

        $this->transactionType = $parts['transactionType'];
        $this->paymentKind = $parts['paymentKind'];

        return $this;
    }

    /**
     * Get every payment method code accepted by JoFotara
     *
     * @return list<string> e.g. ['011', '012', '013', '021', ...]
     */
    public static function getSupportedPaymentMethods(): array
    {
        return array_map('strval', array_keys(self::paymentMethodCodes()));
    }

    /**
     * Every valid payment method code with the parts it is made of, built from
     * TRANSACTION_TYPES, PAYMENT_KINDS and TAX_TYPE_DIGITS so that the codes are
     * defined in one place only
     *
     * @return array<string|int, array{transactionType: string, paymentKind: string, invoiceType: string}>
     */
    private static function paymentMethodCodes(): array
    {
        static $codes = null;

        if ($codes === null) {
            $codes = [];
            foreach (self::TRANSACTION_TYPES as $transactionType => $transactionDigit) {
                foreach (self::PAYMENT_KINDS as $paymentKind => $paymentDigit) {
                    foreach (self::TAX_TYPE_DIGITS as $invoiceType => $taxDigit) {
                        $codes[$transactionDigit.$paymentDigit.$taxDigit] = [
                            'transactionType' => $transactionType,
                            'paymentKind' => $paymentKind,
                            'invoiceType' => $invoiceType,
                        ];
                    }
                }
            }
        }

        return $codes;
    }

    /**
     * Set the payment kind ('cash' or 'receivable')
     *
     * @throws InvalidArgumentException If invoice type is not set
     */
    private function setPaymentKind(string $kind): self
    {
        if ($this->invoiceType === null) {
            throw new InvalidArgumentException('Invoice type must be set before setting payment method. Use setInvoiceType() first.');
        }

        $this->paymentKind = $kind;

        return $this;
    }

    /**
     * Set payment method to cash
     *
     * @throws InvalidArgumentException If invoice type is not set
     */
    public function cash(): self
    {
        return $this->setPaymentKind('cash');
    }

    /**
     * Set payment method to receivable
     *
     * @throws InvalidArgumentException If invoice type is not set
     */
    public function receivable(): self
    {
        return $this->setPaymentKind('receivable');
    }

    /**
     * Set the invoice currency. Defaults to JOD. The currency applies to the
     * whole invoice; a credit invoice must use the currency of the original.
     *
     * @param  string  $currency  ISO 4217 code, one of SUPPORTED_CURRENCIES
     *
     * @throws InvalidArgumentException If the currency is not accepted by JoFotara
     */
    public function setCurrency(string $currency): self
    {
        $currency = strtoupper(trim($currency));

        if ($this->validationsEnabled && ! in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            throw new InvalidArgumentException('Currency must be one of: '.implode(', ', self::SUPPORTED_CURRENCIES));
        }

        $this->currency = $currency;

        return $this;
    }

    /**
     * Get the invoice currency
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * State the value of one unit of the invoice currency in JOD, e.g. 0.709
     * for USD. It is not sent to JoFotara: the SDK uses it only to apply the
     * rule that a cash invoice worth more than 10,000 JOD, or the equivalent in
     * a foreign currency, must carry the buyer name. It is ignored for JOD.
     *
     * @param  float  $jodPerUnit  JOD value of one unit of the invoice currency
     *
     * @throws InvalidArgumentException If the rate is not a finite number greater than 0
     */
    public function setExchangeRate(float $jodPerUnit): self
    {
        // NAN compares false with everything, so it must be ruled out explicitly
        if (! is_finite($jodPerUnit) || $jodPerUnit <= 0) {
            throw new InvalidArgumentException('Exchange rate must be greater than 0');
        }

        $this->exchangeRate = $jodPerUnit;

        return $this;
    }

    /**
     * Get the JOD value of one unit of the invoice currency: 1.0 for JOD, the
     * rate given to setExchangeRate() otherwise, or null when it is unknown
     */
    public function getExchangeRate(): ?float
    {
        return $this->currency === 'JOD' ? 1.0 : $this->exchangeRate;
    }

    /**
     * Set an optional note or description for the invoice
     *
     * @param  string|null  $note  The note or description
     */
    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }

    /**
     * Set the invoice counter (ICV)
     * This should be a sequential number starting from 1
     *
     * @param  int  $counter  The invoice counter
     *
     * @throws InvalidArgumentException If the counter is less than 1
     */
    public function setInvoiceCounter(int $counter): self
    {
        if ($counter < 1) {
            throw new InvalidArgumentException('Invoice counter must be greater than 0');
        }
        $this->invoiceCounter = $counter;

        return $this;
    }

    /**
     * Set this invoice as a credit invoice
     */
    public function asCreditInvoice(string $originalInvoiceId, string $originalInvoiceUuid, float $originalFullAmount): self
    {
        $this->isCreditInvoice = true;
        $this->originalInvoiceId = $originalInvoiceId;
        $this->originalInvoiceUuid = $originalInvoiceUuid;
        $this->originalFullAmount = $originalFullAmount;

        return $this;
    }

    /**
     * Check if this is a credit invoice
     */
    public function isCreditInvoice(): bool
    {
        return $this->isCreditInvoice;
    }

    /**
     * Convert the basic invoice information to XML
     *
     * @return string The XML representation of the basic invoice information
     *
     * @throws InvalidArgumentException If required fields are missing
     */
    public function toXml(): string
    {
        if ($this->validationsEnabled) {
            if (! isset($this->invoiceId)) {
                throw new InvalidArgumentException('Invoice ID is required');
            }
            if (! isset($this->uuid)) {
                throw new InvalidArgumentException('UUID is required');
            }
            if (! isset($this->issueDate)) {
                throw new InvalidArgumentException('Issue date is required');
            }
        }

        $xml = [];

        // Basic invoice elements
        $xml[] = sprintf('<cbc:ID>%s</cbc:ID>', $this->escapeXml($this->invoiceId ?? ''));
        $xml[] = sprintf('<cbc:UUID>%s</cbc:UUID>', $this->escapeXml($this->uuid ?? ''));
        $xml[] = sprintf('<cbc:IssueDate>%s</cbc:IssueDate>', ($this->issueDate ?? new DateTime)->format('Y-m-d'));

        // Check if invoice type and payment method are set
        if ($this->validationsEnabled && $this->getPaymentMethod() === null) {
            throw new InvalidArgumentException('Payment method is required. Use cash(), receivable(), or setPaymentMethod() to set it.');
        }
        $xml[] = sprintf('<cbc:InvoiceTypeCode name="%s">%s</cbc:InvoiceTypeCode>', $this->escapeXml($this->getPaymentMethod() ?? ''), $this->isCreditInvoice ? '381' : '388');

        // Optional note
        if ($this->note !== null) {
            $xml[] = sprintf('<cbc:Note>%s</cbc:Note>', $this->escapeXml($this->note));
        }

        // Currency codes
        $xml[] = sprintf('<cbc:DocumentCurrencyCode>%s</cbc:DocumentCurrencyCode>', $this->escapeXml($this->currency));
        $xml[] = sprintf('<cbc:TaxCurrencyCode>%s</cbc:TaxCurrencyCode>', $this->escapeXml($this->currency));

        // Billing reference for credit invoices
        if ($this->isCreditInvoice) {
            $xml[] = '<cac:BillingReference>';
            $xml[] = '    <cac:InvoiceDocumentReference>';
            $xml[] = '        <cbc:ID>'.$this->escapeXml($this->originalInvoiceId).'</cbc:ID>';
            $xml[] = '        <cbc:UUID>'.$this->escapeXml($this->originalInvoiceUuid).'</cbc:UUID>';
            $xml[] = '        <cbc:DocumentDescription>'.$this->formatOriginalAmount($this->originalFullAmount).'</cbc:DocumentDescription>';
            $xml[] = '    </cac:InvoiceDocumentReference>';
            $xml[] = '</cac:BillingReference>';
        }

        // Invoice counter
        $xml[] = '<cac:AdditionalDocumentReference>';
        $xml[] = '    <cbc:ID>ICV</cbc:ID>';
        $xml[] = sprintf('    <cbc:UUID>%s</cbc:UUID>', $this->escapeXml((string) $this->invoiceCounter));
        $xml[] = '</cac:AdditionalDocumentReference>';

        return implode("\n", $xml);
    }

    /**
     * Format the original invoice total without thousands separators, keeping
     * at least two and at most nine decimal places
     */
    private function formatOriginalAmount(float $amount): string
    {
        return preg_replace('/0{1,7}$/', '', sprintf('%.9f', $amount));
    }

    /**
     * Get the current state of the invoice as an array
     * This is mainly used for testing purposes
     */
    public function toArray(): array
    {
        $data = [
            'invoiceId' => $this->invoiceId ?? null,
            'uuid' => $this->uuid ?? null,
            'issueDate' => isset($this->issueDate) ? $this->issueDate->format('d-m-Y') : null,
            'invoiceType' => $this->invoiceType,
            'paymentMethod' => $this->getPaymentMethod(),
            'note' => $this->note,
            'currency' => $this->currency,
            'invoiceCounter' => $this->invoiceCounter,
            'isCreditInvoice' => $this->isCreditInvoice,
        ];

        // Fields added after the original array shape appear only when used,
        // so existing callers keep getting the array they know
        if ($this->transactionType !== 'local') {
            $data['transactionType'] = $this->transactionType;
        }

        if ($this->exchangeRate !== null) {
            $data['exchangeRate'] = $this->exchangeRate;
        }

        if ($this->isCreditInvoice) {
            $data['originalInvoiceId'] = $this->originalInvoiceId;
            $data['originalInvoiceUuid'] = $this->originalInvoiceUuid;
            $data['originalFullAmount'] = $this->originalFullAmount;
        }

        return $data;
    }

    /**
     * Validate that all required fields are set and valid
     *
     * @throws InvalidArgumentException If validation fails
     */
    public function validateSection(): void
    {
        if (! $this->validationsEnabled) {
            return;
        }

        if (! isset($this->invoiceId)) {
            throw new InvalidArgumentException('Invoice ID is required');
        }

        if (! isset($this->uuid)) {
            throw new InvalidArgumentException('UUID is required');
        }

        if (! isset($this->issueDate)) {
            throw new InvalidArgumentException('Issue date is required');
        }

        if (! isset($this->invoiceType)) {
            throw new InvalidArgumentException('Invoice type is required. Use setInvoiceType() to set it.');
        }

        if ($this->isCreditInvoice) {
            if (empty($this->originalInvoiceId)) {
                throw new InvalidArgumentException('Original invoice ID is required for credit invoices');
            }
            if (empty($this->originalInvoiceUuid)) {
                throw new InvalidArgumentException('Original invoice UUID is required for credit invoices');
            }
            if ($this->originalFullAmount === null || $this->originalFullAmount <= 0) {
                throw new InvalidArgumentException('Original invoice amount must be greater than zero');
            }
        }

        if ($this->getPaymentMethod() === null) {
            throw new InvalidArgumentException('Payment method is required. Use cash(), receivable(), or setPaymentMethod() to set it.');
        }

        // Additional validation specific to BasicInvoiceInformation
        if (empty(trim($this->invoiceId))) {
            throw new InvalidArgumentException('Invoice ID cannot be empty');
        }

        // Validate UUID format (8-4-4-4-12)
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $this->uuid)) {
            throw new InvalidArgumentException('Invalid UUID format. Must be in format: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
        }
    }
}
