<?php

namespace JBadarneh\JoFotara;

use InvalidArgumentException;
use JBadarneh\JoFotara\Response\JoFotaraResponse;
use JBadarneh\JoFotara\Sections\BasicInvoiceInformation;
use JBadarneh\JoFotara\Sections\CustomerInformation;
use JBadarneh\JoFotara\Sections\InvoiceItems;
use JBadarneh\JoFotara\Sections\InvoiceTotals;
use JBadarneh\JoFotara\Sections\ReasonForReturn;
use JBadarneh\JoFotara\Sections\SellerInformation;
use JBadarneh\JoFotara\Sections\SupplierIncomeSource;
use RuntimeException;

class JoFotaraService
{
    private const API_URL = 'https://backend.jofotara.gov.jo/core/invoices/';

    /**
     * Maximum rounding difference accepted between the invoice totals and the
     * values calculated from the line items
     */
    private const ROUNDING_TOLERANCE = 0.001;

    private BasicInvoiceInformation $basicInfo;

    private ?SellerInformation $sellerInfo = null;

    private ?CustomerInformation $customerInfo = null;

    private ?SupplierIncomeSource $supplierIncomeSource = null;

    private ?InvoiceItems $items = null;

    private ?InvoiceTotals $invoiceTotals = null;

    private ?ReasonForReturn $reasonForReturn = null;

    private string $clientId;

    private string $clientSecret;

    private bool $validationsEnabled = true;

    public function __construct(string $clientId, string $clientSecret, bool $enableValidations = true)
    {
        if (empty($clientId) || empty($clientSecret)) {
            throw new InvalidArgumentException('JoFotara client ID and secret are required');
        }

        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->validationsEnabled = $enableValidations;
        $this->basicInfo = new BasicInvoiceInformation;
        $this->basicInfo->setValidationsEnabled($this->validationsEnabled);
    }

    /**
     * Get the basic invoice information section builder
     */
    public function basicInformation(): BasicInvoiceInformation
    {
        return $this->basicInfo;
    }

    /**
     * Get the seller information section builder
     */
    public function sellerInformation(): SellerInformation
    {
        if (! $this->sellerInfo) {
            $this->sellerInfo = new SellerInformation;
            $this->sellerInfo->setValidationsEnabled($this->validationsEnabled);
        }

        return $this->sellerInfo;
    }

    /**
     * Get the customer information section builder
     */
    public function customerInformation(): CustomerInformation
    {
        if (! $this->customerInfo) {
            $this->customerInfo = new CustomerInformation;
            $this->customerInfo->setId('', 'NIN');
            $this->customerInfo->setValidationsEnabled($this->validationsEnabled);
        }

        return $this->customerInfo;
    }

    /**
     * Get the supplier information section builder
     */
    public function supplierIncomeSource(string $sequence): SupplierIncomeSource
    {
        if (! $this->supplierIncomeSource) {
            $this->supplierIncomeSource = new SupplierIncomeSource($sequence);
            $this->supplierIncomeSource->setValidationsEnabled($this->validationsEnabled);
        }

        return $this->supplierIncomeSource;
    }

    /**
     * Get the invoice items section builder
     */
    public function items(): InvoiceItems
    {
        if (! $this->items) {
            $this->items = new InvoiceItems;
            $this->items->setValidationsEnabled($this->validationsEnabled);
        }

        return $this->items;
    }

    /**
     * Set the reason for return
     *
     * @param  string  $reason  The reason for returning the invoice
     */
    public function setReasonForReturn(string $reason): self
    {
        if (! $this->reasonForReturn) {
            $this->reasonForReturn = new ReasonForReturn;
            $this->reasonForReturn->setValidationsEnabled($this->validationsEnabled);
        }

        $this->reasonForReturn->setReason($reason);

        return $this;
    }

    /**
     * Get the monetary totals section builder
     */
    public function invoiceTotals(): InvoiceTotals
    {
        if (! $this->invoiceTotals) {
            $this->invoiceTotals = new InvoiceTotals;
            $this->invoiceTotals->setValidationsEnabled($this->validationsEnabled);

            // If we have items, calculate totals from them
            if ($this->items && count($this->items->getItems()) > 0) {
                $totals = $this->calculateTotalsFromItems();

                $this->invoiceTotals
                    ->setTaxExclusiveAmount($totals['taxExclusiveAmount'])
                    ->setDiscountTotalAmount($totals['discountTotalAmount'])
                    ->setTaxInclusiveAmount($totals['taxInclusiveAmount'])
                    ->setTaxTotalAmount($totals['taxTotalAmount'])
                    ->setPayableAmount($totals['payableAmount']);
            }
        }

        return $this->invoiceTotals;
    }

    /**
     * Validate that all sections are consistent and complete
     *
     * @throws InvalidArgumentException If there are inconsistencies or missing sections
     */
    private function validateSections(): void
    {
        // Always check required sections regardless of validation flag

        // Validate credit invoice requirements before other validations
        if ($this->basicInfo->isCreditInvoice()) {
            if (empty($this->reasonForReturn)) {
                throw new InvalidArgumentException('Credit invoices require a reason for return');
            }
        }

        // Validate all required sections are initialized
        if (! $this->sellerInfo) {
            throw new InvalidArgumentException('Seller information is required');
        }

        // Validate customer information is initialized
        if (! $this->customerInfo) {
            $this->customerInfo = new CustomerInformation;
            $this->customerInfo->setValidationsEnabled($this->validationsEnabled);
            $this->customerInfo->setupAnonymousCustomer();
        }

        if (! $this->supplierIncomeSource) {
            throw new InvalidArgumentException('Supplier income source is required');
        }
        if (! $this->items) {
            throw new InvalidArgumentException('At least one invoice item is required');
        }
        if (! $this->invoiceTotals) {
            throw new InvalidArgumentException('Invoice totals are required');
        }

        // Skip detailed validations if validations are disabled
        if (! $this->validationsEnabled) {
            return;
        }

        // Perform detailed validations only if validations are enabled
        $this->basicInfo->validateSection();

        if ($this->basicInfo->isCreditInvoice() && $this->reasonForReturn) {
            $this->reasonForReturn->validateSection();
        }

        $this->sellerInfo->validateSection();
        // Validate customer information if set
        if ($this->customerInfo) {
            $this->customerInfo->validateSection();
        }
        $this->supplierIncomeSource->validateSection();
        $this->items->validateSection();
        $this->invoiceTotals->validateSection();

        $this->validateCustomerRequirements();
        $this->validateItemsAgainstInvoiceType();

        // Cross-section validation (totals matching items)
        if (count($this->items->getItems()) > 0) {
            $providedTotals = $this->invoiceTotals->toArray();

            foreach ($this->calculateTotalsFromItems() as $key => $expected) {
                // The integration guide allows a rounding difference of up to 0.001.
                // Both sides are already rounded to nine decimals; rounding the
                // difference as well keeps a difference of exactly 0.001 from
                // being rejected because of binary floating point noise.
                if (round(abs($providedTotals[$key] - $expected), 9) > self::ROUNDING_TOLERANCE) {
                    throw new InvalidArgumentException('Invoice totals do not match calculated values from line items');
                }
            }
        }
    }

    /**
     * Calculate the invoice totals from the line items
     *
     * The sums are rounded to nine decimals, the precision InvoiceTotals stores
     * and the XML carries, so that they compare digit for digit with the totals
     * that will be sent.
     *
     * @return array{taxExclusiveAmount: float, taxInclusiveAmount: float, discountTotalAmount: float, taxTotalAmount: float, payableAmount: float}
     */
    private function calculateTotalsFromItems(): array
    {
        $amountBeforeDiscount = 0.0;
        $taxTotalAmount = 0.0;
        $specialTaxTotalAmount = 0.0;
        $discountTotalAmount = 0.0;

        foreach ($this->items->getItems() as $item) {
            $amountBeforeDiscount += $item->getAmountBeforeDiscount();
            $taxTotalAmount += $item->getTaxAmount();
            $specialTaxTotalAmount += $item->getSpecialTax();
            $discountTotalAmount += $item->getDiscount();
        }

        // Invoice total = total before discount - discounts + special tax + general tax
        $taxInclusiveAmount = $amountBeforeDiscount - $discountTotalAmount + $specialTaxTotalAmount + $taxTotalAmount;

        return [
            'taxExclusiveAmount' => round($amountBeforeDiscount, 9),
            'taxInclusiveAmount' => round($taxInclusiveAmount, 9),
            'discountTotalAmount' => round($discountTotalAmount, 9),
            // The invoice-level tax total carries the general sales tax only
            'taxTotalAmount' => round($taxTotalAmount, 9),
            'payableAmount' => round($taxInclusiveAmount, 9),
        ];
    }

    /**
     * Validate the buyer details that depend on the invoice type and total
     *
     * @throws InvalidArgumentException If a required buyer detail is missing
     */
    private function validateCustomerRequirements(): void
    {
        // The buyer name is mandatory for receivable invoices and for cash
        // invoices above 10,000 JOD. For other currencies the guide asks for the
        // JOD equivalent, which the SDK cannot compute, so only JOD is checked.
        $isOverThreshold = $this->basicInfo->getCurrency() === 'JOD'
            && $this->invoiceTotals->getPayableAmount() > 10000;

        if (($this->basicInfo->isReceivable() || $isOverThreshold) && empty($this->customerInfo->getName())) {
            throw new InvalidArgumentException('Customer name is required for receivable invoices or invoices over 10000 JOD');
        }

        // Development zone invoices require the buyer's tax number
        if ($this->basicInfo->getTransactionType() === 'development_zone'
            && ($this->customerInfo->getIdType() !== 'TN' || empty($this->customerInfo->getId()))) {
            throw new InvalidArgumentException('Customer tax number (ID type TN) is required for development zone invoices');
        }
    }

    /**
     * Validate the line items against the rules of the invoice type
     *
     * @throws InvalidArgumentException If a line item breaks a rule of the invoice type
     */
    private function validateItemsAgainstInvoiceType(): void
    {
        $invoiceType = $this->basicInfo->getInvoiceType();
        $transactionType = $this->basicInfo->getTransactionType();
        $items = $this->items->getItems();
        $itemsWithConsumerPrice = 0;

        foreach ($items as $item) {
            // Export, development zone, transit, foreign trade and free zone
            // transfer sales invoices must be zero-rated (category O) on every line
            if ($invoiceType !== 'income' && $transactionType !== 'local' && $item->getTaxCategory() !== 'O') {
                throw new InvalidArgumentException("All items must be zero-rated (use zeroTax()) for '$transactionType' invoices");
            }

            if ($invoiceType !== 'special_sales' && $item->getSpecialTax() > 0) {
                throw new InvalidArgumentException('Special tax is only allowed on special sales invoices');
            }

            if ($item->getConsumerPrice() !== null) {
                $itemsWithConsumerPrice++;
            }
        }

        if ($itemsWithConsumerPrice > 0) {
            if ($invoiceType === 'income') {
                throw new InvalidArgumentException('Consumer price is only allowed on general sales and special sales invoices');
            }

            if ($itemsWithConsumerPrice !== count($items)) {
                throw new InvalidArgumentException('Consumer price must be set on all items of the invoice or on none of them');
            }
        }
    }

    public function generateXml(): string
    {
        // Validate sections before generating XML
        // This will respect the validationsEnabled flag
        $this->validateSections();

        $xml = [];

        // Add XML declaration
        $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';

        // Add root element with namespaces UBL2.1 standard
        $xml[] = '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">';

        // Add UBLVersionID
        $xml[] = '<cbc:UBLVersionID>2.1</cbc:UBLVersionID>';

        // Add ProfileID as required by the integration guide
        $xml[] = '<cbc:ProfileID>reporting:1.0</cbc:ProfileID>';

        // Add basic information
        $xml[] = $this->basicInfo->toXml();

        // Add seller information if set
        if ($this->sellerInfo) {
            $xml[] = $this->sellerInfo->toXml();
        }

        // Add customer information if set
        if ($this->customerInfo) {
            $xml[] = $this->customerInfo->toXml();
        }

        // Add Supplier information if set
        if ($this->supplierIncomeSource) {
            $xml[] = $this->supplierIncomeSource->toXml();
        }

        // Add reason for return for credit invoices
        if ($this->basicInfo->isCreditInvoice() && $this->reasonForReturn) {
            $xml[] = $this->reasonForReturn->toXml();
        }

        // Add invoice totals
        $xml[] = $this->invoiceTotals->toXml();

        // Add items if set
        if ($this->items) {
            // Special sales lines always carry the special tax subtotal
            $xml[] = $this->items->toXml($this->basicInfo->getInvoiceType() === 'special_sales');
        }

        // Close root element
        $xml[] = '</Invoice>';

        return implode("\n", $xml);
    }

    /**
     * Encode the XML invoice to base64
     *
     * @return string Base64 encoded XML
     *
     * @throws InvalidArgumentException If XML generation fails
     */
    public function encodeInvoice(): string
    {
        $xml = $this->generateXml();

        return base64_encode($xml);
    }

    /**
     * Send the invoice to the JoFotara API
     *
     * @return JoFotaraResponse A response object containing the API response data
     *
     * @throws InvalidArgumentException If configuration is missing
     * @throws RuntimeException If the API request fails
     */
    public function send(): JoFotaraResponse
    {
        $encodedInvoice = $this->encodeInvoice();

        [$response, $statusCode, $error] = $this->executeRequest(
            self::API_URL,
            [
                'Client-Id: '.$this->clientId,
                'Secret-Key: '.$this->clientSecret,
                'Content-Type: application/json',
            ],
            json_encode(['invoice' => $encodedInvoice])
        );

        if ($error) {
            throw new RuntimeException('Failed to send invoice: '.$error);
        }

        // Status handling and body parsing live in JoFotaraResponse
        return JoFotaraResponse::fromHttpResponse($response, $statusCode);
    }

    /**
     * Execute the HTTP request
     *
     * @param  string  $url  The URL to send the request to
     * @param  array  $headers  The HTTP headers
     * @param  string  $body  The request body
     * @return array [response, statusCode, error]
     */
    protected function executeRequest(string $url, array $headers, string $body): array
    {
        $curlHandle = curl_init($url);
        curl_setopt_array($curlHandle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
        ]);

        $response = curl_exec($curlHandle);
        $statusCode = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        $error = curl_error($curlHandle);
        curl_close($curlHandle);

        return [$response, $statusCode, $error];
    }
}
