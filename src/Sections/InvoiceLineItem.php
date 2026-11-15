<?php

namespace JBadarneh\JoFotara\Sections;

use InvalidArgumentException;
use JBadarneh\JoFotara\Contracts\ValidatableSection;
use JBadarneh\JoFotara\Traits\ComparesAmounts;
use JBadarneh\JoFotara\Traits\WithValidationConfigs;
use JBadarneh\JoFotara\Traits\XmlHelperTrait;

class InvoiceLineItem implements ValidatableSection
{
    use ComparesAmounts, WithValidationConfigs, XmlHelperTrait;

    private string $id;

    private float $quantity;

    private float $unitPrice;

    private float $discount = 0.0;

    private string $description;

    private string $taxCategory = 'S'; // Default to standard rate

    private float $taxPercent = 16.0; // Default to 16%

    private string $unitCode = 'PCE'; // Default to piece

    private float $specialTax = 0.0;

    private ?float $consumerPrice = null;

    /**
     * General sales tax rates accepted by JoFotara for the standard (S) category
     */
    public const STANDARD_TAX_RATES = [1.0, 2.0, 3.0, 4.0, 5.0, 7.0, 8.0, 10.0, 16.0];

    public function __construct(string $id)
    {
        $this->id = $id;
    }

    /**
     * Set the quantity
     *
     * @param  float  $quantity  The quantity of items
     *
     * @throws InvalidArgumentException If quantity is not positive and validations are enabled
     */
    public function setQuantity(float $quantity): self
    {
        if ($this->validationsEnabled && $quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than 0');
        }
        $this->quantity = $quantity;

        return $this;
    }

    /**
     * Set the unit price
     *
     * @param  float  $price  The unit price
     *
     * @throws InvalidArgumentException If price is negative and validations are enabled
     */
    public function setUnitPrice(float $price): self
    {
        if ($this->validationsEnabled && $price < 0) {
            throw new InvalidArgumentException('Unit price cannot be negative');
        }
        $this->unitPrice = $price;

        return $this;
    }

    /**
     * Set the discount amount for this item
     *
     * @param  float  $amount  The discount amount
     *
     * @throws InvalidArgumentException If discount is negative or greater than total amount and validations are enabled
     */
    public function setDiscount(float $amount): self
    {
        if ($this->validationsEnabled) {
            if ($amount < 0) {
                throw new InvalidArgumentException('Discount amount cannot be negative');
            }

            if (isset($this->quantity) && isset($this->unitPrice) && $this->amountExceeds($amount, $this->quantity * $this->unitPrice)) {
                throw new InvalidArgumentException('Discount cannot be greater than total amount');
            }
        }

        $this->discount = $amount;

        return $this;
    }

    /**
     * Set the item description
     *
     * @param  string  $description  The item description
     *
     * @throws InvalidArgumentException If description is empty and validations are enabled
     */
    public function setDescription(string $description): self
    {
        if ($this->validationsEnabled && empty($description)) {
            throw new InvalidArgumentException('Description cannot be empty');
        }
        $this->description = $description;

        return $this;
    }

    /**
     * Set the item as tax exempted (0% tax rate)
     */
    public function taxExempted(): self
    {
        return $this->setTaxCategory('Z');
    }

    /**
     * Set the item as zero rated (0% tax rate)
     */
    public function zeroTax(): self
    {
        return $this->setTaxCategory('O');
    }

    /**
     * Set the item's tax rate (standard rate category)
     *
     * @param  float  $rate  The tax rate (1-16%)
     *
     * @throws InvalidArgumentException If rate is invalid
     */
    public function tax(float $rate): self
    {
        return $this->setTaxCategory('S', $rate);
    }

    /**
     * Set the special sales tax amount of this line (special sales invoices only).
     * It is an amount entered as-is, not a percentage; the general sales tax is
     * calculated on top of it.
     *
     * @param  float  $amount  The special tax amount for the whole line
     *
     * @throws InvalidArgumentException If the amount is negative
     */
    public function setSpecialTax(float $amount): self
    {
        if ($this->validationsEnabled && $amount < 0) {
            throw new InvalidArgumentException('Special tax amount cannot be negative');
        }
        $this->specialTax = $amount;

        return $this;
    }

    /**
     * Get the special sales tax amount of this line
     */
    public function getSpecialTax(): float
    {
        return $this->specialTax;
    }

    /**
     * Set the consumer price of one unit (general and special sales invoices,
     * for taxpayers who were granted the consumer price permission).
     *
     * The consumer price is the final price to the end consumer. It must not be
     * lower than the unit price; when it is higher, general sales tax is
     * calculated on quantity * consumer price instead of the line amount.
     *
     * @param  float  $price  The consumer price per unit
     *
     * @throws InvalidArgumentException If the price is negative
     */
    public function setConsumerPrice(float $price): self
    {
        if ($this->validationsEnabled && $price < 0) {
            throw new InvalidArgumentException('Consumer price cannot be negative');
        }
        $this->consumerPrice = $price;

        return $this;
    }

    /**
     * Get the consumer price per unit, or null when not used
     */
    public function getConsumerPrice(): ?float
    {
        return $this->consumerPrice;
    }

    /**
     * Get the tax category (S, Z or O)
     */
    public function getTaxCategory(): string
    {
        return $this->taxCategory;
    }

    /**
     * Get the discount amount for this item
     */
    public function getDiscount(): float
    {
        return $this->discount;
    }

    /**
     * Set the tax category and percentage
     * Z = Exempt (0%)
     * O = Zero rated (0%)
     * S = Standard rate (1-16%)
     *
     * @param  string  $category  The tax category (Z, O, or S)
     * @param  float|null  $percent  The tax percentage (required for category S)
     *
     * @throws InvalidArgumentException If category or percentage is invalid
     */
    public function setTaxCategory(string $category, ?float $percent = null): self
    {
        $validCategories = ['Z', 'O', 'S'];
        if (! in_array($category, $validCategories)) {
            throw new InvalidArgumentException('Tax category must be Z, O, or S');
        }

        if ($category === 'S') {
            if ($percent === null) {
                throw new InvalidArgumentException('Tax percentage is required for standard rate category');
            }
            if ($percent <= 0) {
                throw new InvalidArgumentException('Invalid tax rate for standard category');
            }
            $this->taxPercent = $percent;
        } else {
            $this->taxPercent = 0;
        }

        $this->taxCategory = $category;

        return $this;
    }

    /**
     * Calculates the total amount before discount
     *
     * @throws InvalidArgumentException If quantity or unit price is not set
     */
    public function getAmountBeforeDiscount(): float
    {
        if (! isset($this->quantity)) {
            throw new InvalidArgumentException('Quantity is required to calculate tax exclusive amount');
        }
        if (! isset($this->unitPrice)) {
            throw new InvalidArgumentException('Unit price is required to calculate tax exclusive amount');
        }

        return $this->quantity * $this->unitPrice;
    }

    /**
     * Calculates the total amount before discount
     *
     * @throws InvalidArgumentException If quantity or unit price is not set
     */
    public function getAmountAfterDiscount(): float
    {
        if (! isset($this->quantity)) {
            throw new InvalidArgumentException('Quantity is required to calculate tax exclusive amount');
        }
        if (! isset($this->unitPrice)) {
            throw new InvalidArgumentException('Unit price is required to calculate tax exclusive amount');
        }

        $gross = $this->quantity * $this->unitPrice;
        $amount = $gross - $this->discount;

        // A full discount can leave floating point noise (3 x 1.15 - 3.45 is
        // -4.4e-16), which would be written as "-0.000000000". Anything below
        // half a unit of the ninth decimal is zero once serialized, and so is a
        // negative remainder the discount check accepts as rounding noise.
        if (abs($amount) < 5e-10 || ($amount < 0 && ! $this->amountExceeds($this->discount, $gross))) {
            return 0.0;
        }

        return $amount;
    }

    /**
     * Calculate the general sales tax amount for this line item
     *
     * The taxable base is the line amount after discount, or quantity * consumer
     * price when a consumer price higher than the unit price is set, plus the
     * special tax amount if any.
     *
     * @throws InvalidArgumentException If quantity or unit price is not set
     */
    public function getTaxAmount(): float
    {
        if ($this->taxCategory !== 'S') {
            return 0;
        }

        $base = $this->getAmountAfterDiscount();
        if ($this->consumerPrice !== null && $this->consumerPrice > $this->unitPrice) {
            $base = $this->quantity * $this->consumerPrice;
        }

        return ($base + $this->specialTax) * ($this->taxPercent / 100);
    }

    /**
     * Calculate the total amount including special and general sales tax
     *
     * @throws InvalidArgumentException If quantity or unit price is not set
     */
    public function getTaxInclusiveAmount(): float
    {
        return $this->getAmountAfterDiscount() + $this->specialTax + $this->getTaxAmount();
    }

    /**
     * Convert the line item to XML
     *
     * @param  bool  $specialTaxBreakdown  Emit the special tax subtotal even when the special tax is zero (special sales invoices)
     *
     * @throws InvalidArgumentException If required fields are missing
     */
    public function toXml(bool $specialTaxBreakdown = false): string
    {
        if (! isset($this->quantity)) {
            throw new InvalidArgumentException('Quantity is required');
        }
        if (! isset($this->unitPrice)) {
            throw new InvalidArgumentException('Unit price is required');
        }
        if (! isset($this->description)) {
            throw new InvalidArgumentException('Description is required');
        }

        $taxAmount = $this->getTaxAmount();
        $taxInclusiveAmount = $this->getTaxInclusiveAmount();
        $taxExclusiveAmount = $this->getAmountAfterDiscount();

        $xml = [];
        $xml[] = '<cac:InvoiceLine>';
        $xml[] = sprintf('    <cbc:ID>%s</cbc:ID>', $this->escapeXml($this->id));
        $xml[] = sprintf('    <cbc:InvoicedQuantity unitCode="%s">%.9f</cbc:InvoicedQuantity>',
            $this->escapeXml($this->unitCode),
            $this->quantity
        );
        $xml[] = sprintf('    <cbc:LineExtensionAmount currencyID="JO">%.9f</cbc:LineExtensionAmount>',
            $taxExclusiveAmount
        );

        // Tax information
        $xml[] = '    <cac:TaxTotal>';
        $xml[] = sprintf('        <cbc:TaxAmount currencyID="JO">%.9f</cbc:TaxAmount>', $taxAmount);
        $xml[] = sprintf('        <cbc:RoundingAmount currencyID="JO">%.9f</cbc:RoundingAmount>', $taxInclusiveAmount);
        $withSpecialTax = $specialTaxBreakdown || $this->specialTax > 0;
        if ($withSpecialTax) {
            // Special sales tax subtotal (an amount, no percentage)
            $xml[] = '        <cac:TaxSubtotal>';
            $xml[] = sprintf('            <cbc:TaxableAmount currencyID="JO">%.9f</cbc:TaxableAmount>', $taxExclusiveAmount);
            $xml[] = sprintf('            <cbc:TaxAmount currencyID="JO">%.9f</cbc:TaxAmount>', $this->specialTax);
            $xml[] = '            <cac:TaxCategory>';
            $xml[] = '                <cbc:ID schemeAgencyID="6" schemeID="UN/ECE 5305">S</cbc:ID>';
            $xml[] = '                <cac:TaxScheme>';
            $xml[] = '                    <cbc:ID schemeAgencyID="6" schemeID="UN/ECE 5153">OTH</cbc:ID>';
            $xml[] = '                </cac:TaxScheme>';
            $xml[] = '            </cac:TaxCategory>';
            $xml[] = '        </cac:TaxSubtotal>';
        }
        $xml[] = '        <cac:TaxSubtotal>';
        if ($withSpecialTax) {
            $xml[] = sprintf('            <cbc:TaxableAmount currencyID="JO">%.9f</cbc:TaxableAmount>', $taxExclusiveAmount);
        }
        $xml[] = sprintf('            <cbc:TaxAmount currencyID="JO">%.9f</cbc:TaxAmount>', $taxAmount);
        $xml[] = '            <cac:TaxCategory>';
        $xml[] = sprintf('                <cbc:ID schemeAgencyID="6" schemeID="UN/ECE 5305">%s</cbc:ID>',
            $this->escapeXml($this->taxCategory)
        );
        $xml[] = sprintf('                <cbc:Percent>%.9f</cbc:Percent>', $this->taxPercent);
        $xml[] = '                <cac:TaxScheme>';
        $xml[] = '                    <cbc:ID schemeAgencyID="6" schemeID="UN/ECE 5153">VAT</cbc:ID>';
        $xml[] = '                </cac:TaxScheme>';
        $xml[] = '            </cac:TaxCategory>';
        $xml[] = '        </cac:TaxSubtotal>';
        $xml[] = '    </cac:TaxTotal>';

        // Item description
        $xml[] = '    <cac:Item>';
        $xml[] = sprintf('        <cbc:Name>%s</cbc:Name>', $this->escapeXml($this->description));
        $xml[] = '    </cac:Item>';

        // Price information
        $xml[] = '    <cac:Price>';
        $xml[] = sprintf('        <cbc:PriceAmount currencyID="JO">%.9f</cbc:PriceAmount>', $this->unitPrice);
        $xml[] = '        <cac:AllowanceCharge>';
        $xml[] = '            <cbc:ChargeIndicator>false</cbc:ChargeIndicator>';
        $xml[] = '            <cbc:AllowanceChargeReason>DISCOUNT</cbc:AllowanceChargeReason>';
        $xml[] = sprintf('            <cbc:Amount currencyID="JO">%.9f</cbc:Amount>', $this->discount);
        $xml[] = '        </cac:AllowanceCharge>';
        $xml[] = '    </cac:Price>';

        // Consumer price
        if ($this->consumerPrice !== null) {
            $xml[] = '    <cac:ItemPriceExtension>';
            $xml[] = sprintf('        <cbc:Amount currencyID="JO">%.9f</cbc:Amount>', $this->consumerPrice);
            $xml[] = '    </cac:ItemPriceExtension>';
        }
        $xml[] = '</cac:InvoiceLine>';

        return $this->normalizeXml(implode("\n", $xml));
    }

    /**
     * Get the current state as an array
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'quantity' => $this->quantity ?? null,
            'unitPrice' => $this->unitPrice ?? null,
            'discount' => $this->discount,
            'description' => $this->description ?? null,
            'taxCategory' => $this->taxCategory,
            'taxPercent' => $this->taxPercent,
            'unitCode' => $this->unitCode,
        ];

        // Fields added after the original array shape appear only when used,
        // so existing callers keep getting the array they know
        if ($this->specialTax > 0) {
            $data['specialTax'] = $this->specialTax;
        }

        if ($this->consumerPrice !== null) {
            $data['consumerPrice'] = $this->consumerPrice;
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
        // Skip detailed validations if validations are disabled
        if (! $this->validationsEnabled) {
            return;
        }

        // Validate required fields
        if (! isset($this->quantity)) {
            throw new InvalidArgumentException('Item quantity is required');
        }
        if (! isset($this->unitPrice)) {
            throw new InvalidArgumentException('Item unit price is required');
        }
        if (! isset($this->description)) {
            throw new InvalidArgumentException('Item description is required');
        }

        // Validate quantity
        if ($this->quantity <= 0) {
            throw new InvalidArgumentException('Item quantity must be greater than 0');
        }

        // Validate unit price
        if ($this->unitPrice < 0) {
            throw new InvalidArgumentException('Item unit price cannot be negative');
        }

        // Validate discount
        if ($this->discount < 0) {
            throw new InvalidArgumentException('Item discount cannot be negative');
        }
        if ($this->amountExceeds($this->discount, $this->quantity * $this->unitPrice)) {
            throw new InvalidArgumentException('Item discount cannot be greater than total amount');
        }

        // Validate tax category
        if (! in_array($this->taxCategory, ['S', 'Z', 'O'])) {
            throw new InvalidArgumentException('Invalid tax category');
        }

        // Validate tax percent for standard rate
        if ($this->taxCategory === 'S' && ($this->taxPercent <= 0 || $this->taxPercent > 16)) {
            throw new InvalidArgumentException('Tax percentage must be between 0 and 16 for standard rate');
        }
        if ($this->taxCategory === 'S' && ! in_array($this->taxPercent, self::STANDARD_TAX_RATES, true)) {
            throw new InvalidArgumentException('Tax percentage must be one of: '.implode(', ', self::STANDARD_TAX_RATES));
        }

        // Validate special tax and consumer price
        if ($this->specialTax < 0) {
            throw new InvalidArgumentException('Item special tax cannot be negative');
        }
        if ($this->consumerPrice !== null && $this->consumerPrice < $this->unitPrice) {
            throw new InvalidArgumentException('Item consumer price cannot be lower than the unit price');
        }
    }
}
