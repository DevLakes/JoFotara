# JoFotara SDK - Jordan E-Invoice Integration

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jafar-albadarneh/jofotara.svg?style=flat-square)](https://packagist.org/packages/jafar-albadarneh/jofotara)
[![Tests](https://img.shields.io/github/actions/workflow/status/jafar-albadarneh/jofotara/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/jafar-albadarneh/jofotara/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/jafar-albadarneh/jofotara.svg?style=flat-square)](https://packagist.org/packages/jafar-albadarneh/jofotara)

A powerful, developer-friendly PHP SDK for seamless integration with Jordan's electronic tax invoicing system (JoFotara). This package provides:

- 🚀 **Simple, Fluent API**: Intuitive builder pattern for creating invoices
- ✅ **Full UBL 2.1 Compliance**: Generates valid XML according to Jordan Tax Authority standards
- 🔒 **Built-in Validation**: Ensures all required fields and business rules are satisfied
- 🔄 **Multiple Invoice Types**: Support for sales, income, credit invoices, and more
- 💳 **Flexible Payment Methods**: Handle both cash and receivable transactions
- 🧮 **Automatic Calculations**: Built-in tax and total calculations

## 📦 Installation

```bash
composer require jafar-albadarneh/jofotara
```

## 🚀 Quick Start

```php
use JBadarneh\JoFotara\JoFotaraService;

$invoice = new JoFotaraService('your-client-id', 'your-client-secret');

// Create a basic sales invoice
$invoice->basicInformation()
    ->setInvoiceId('INV-001')
    ->setUuid('123e4567-e89b-12d3-a456-426614174000')
    ->setIssueDate('16-02-2025')
    ->setInvoiceType('general_sales')
    ->cash();

$invoice->sellerInformation()
    ->setName('Your Company')
    ->setTin('123456789');

$invoice->customerInformation()
    ->setId('987654321', 'TN')
    ->setName('Customer Name');

$invoice->items()
    ->addItem('1')
    ->setQuantity(2)
    ->setUnitPrice(100.0)
    ->setDescription('Premium Widget')
    ->tax(16);

$response = $invoice->send();
```

## 📖 Documentation

The SDK follows version 1.5 (May 2026) of the official technical integration guide, available in [docs/integration-guide-v1.5-ar.pdf](docs/integration-guide-v1.5-ar.pdf) (Arabic). The earlier English edition is kept in [docs/integration-docs.pdf](docs/integration-docs.pdf).

### Invoice Types

The SDK supports all JoFotara invoice types:

```php
// 1. General Sales Invoice
$invoice->basicInformation()
    ->setInvoiceType('general_sales')
    ->cash();  // or ->receivable()

// 2. Special Sales Invoice (goods subject to special sales tax)
$invoice->basicInformation()
    ->setInvoiceType('special_sales')
    ->cash();

// 3. Income Invoice
$invoice->basicInformation()
    ->setInvoiceType('income')
    ->cash();

// 4. Credit Invoice (for returns/corrections)
$invoice->basicInformation()
    ->setInvoiceType('general_sales')
    ->cash()
    ->asCreditInvoice(
        originalInvoiceId: 'INV-001',
        originalInvoiceUuid: '123e4567-...',
        originalFullAmount: 200.00
    );

// Set reason for credit invoice
$invoice->setReasonForReturn('Defective item returned');
```

### Payment Methods

JoFotara supports two payment methods:

```php
// 1. Cash Payment (code: 012)
$invoice->basicInformation()
    ->setInvoiceType('general_sales')
    ->cash();

// 2. Receivable Payment (code: 022)
$invoice->basicInformation()
    ->setInvoiceType('general_sales')
    ->receivable();
```

### Transaction Types

Since version 1.5 of the integration guide, every invoice type can also be issued as an export, development zone, transit, foreign trade or free zone transfer invoice. The default is `local`.

```php
$invoice->basicInformation()
    ->setInvoiceType('general_sales')
    ->setTransactionType('export')   // local, export, development_zone, transit, foreign_trade, free_zone_transfer
    ->receivable();                  // code: 122
```

| Transaction type     | Income (cash / receivable) | General sales | Special sales | Rules |
|----------------------|----------------------------|---------------|---------------|-------|
| `local`              | 011 / 021                  | 012 / 022     | 013 / 023     | - |
| `export`             | 111 / 121                  | 112 / 122     | 113 / 123     | Sales invoices: every item zero-rated |
| `development_zone`   | 211 / 221                  | 212 / 222     | 213 / 223     | Buyer tax number (`TN`) required; sales invoices: every item zero-rated |
| `transit`            | 311 / 321                  | 312 / 322     | 313 / 323     | Sales invoices: every item zero-rated |
| `foreign_trade`      | 411 / 421                  | 412 / 422     | 413 / 423     | Sales invoices: every item zero-rated |
| `free_zone_transfer` | 511 / 521                  | 512 / 522     | 513 / 523     | Sales invoices: every item zero-rated |

"Zero-rated" means `->zeroTax()` on every item. A credit invoice must use the same transaction type and currency as the original invoice. You can also pass the code directly with `->setPaymentMethod('322')`.

### Currencies

Invoices are issued in JOD by default. The currency applies to the whole invoice:

```php
$invoice->basicInformation()->setCurrency('USD');
```

Supported: JOD, USD, EUR, SAR, AED, OMR, GBP, QAR, KWD, BHD, AUD, CAD, JPY, CHF, TRY, SYP, EGP, SEK.

The buyer name is mandatory for cash invoices above 10,000 JOD or the equivalent in a foreign currency. To let the SDK apply that rule to a foreign currency invoice, state what one unit of the currency is worth in JOD:

```php
$invoice->basicInformation()
    ->setCurrency('USD')
    ->setExchangeRate(0.709);   // 1 USD = 0.709 JOD; used for this check only, never sent
```

Without an exchange rate the SDK cannot tell whether a foreign currency cash invoice is under the threshold, so it requires the buyer name.

### Tax Handling

The SDK supports various tax scenarios. Accepted general sales tax rates are 1, 2, 3, 4, 5, 7, 8, 10 and 16.

```php
// 1. Standard VAT (16%)
$invoice->items()
    ->addItem('1')
    ->setQuantity(1)
    ->setUnitPrice(100.0)
    ->tax(16);

// 2. Tax Exempt
$invoice->items()
    ->addItem('2')
    ->setQuantity(1)
    ->setUnitPrice(50.0)
    ->taxExempted();

// 3. Zero-rated (e.g., exports)
$invoice->items()
    ->addItem('3')
    ->setQuantity(1)
    ->setUnitPrice(75.0)
    ->zeroTax();

// 4. Item with Discount
$invoice->items()
    ->addItem('4')
    ->setQuantity(1)
    ->setUnitPrice(200.0)
    ->setDiscount(20.0)
    ->tax(16);

// 5. Special sales tax (special sales invoices only)
// The special tax is an amount for the whole line; general tax is applied on top of it
$invoice->items()
    ->addItem('5')
    ->setQuantity(10)
    ->setUnitPrice(50.0)
    ->setDiscount(5.0)
    ->setSpecialTax(10.0)
    ->tax(16);

// 6. Consumer price (general and special sales, requires the permission from ISTD)
// Must be set on every item and cannot be lower than the unit price. When it is
// higher, general tax is calculated on quantity * consumer price.
$invoice->items()
    ->addItem('6')
    ->setQuantity(10)
    ->setUnitPrice(5.0)
    ->setConsumerPrice(6.0)
    ->tax(16);
```

### Response Handling

```php
$response = $invoice->send();

if ($response->isSuccess()) {
    // SUBMITTED, or ALREADY_SUBMITTED when the same ID and UUID were sent before
    echo "Status: {$response->getInvoiceStatus()}\n";
    echo "Invoice number: {$response->getInvoiceNumber()}\n";
    echo "UUID: {$response->getInvoiceUuid()}\n";

    // The QR code must be present and printed on the invoice
    $qrCode = $response->getQrCode();
} else {
    // NOT_SUBMITTED: the HTTP status is not 200 and the errors explain why
    echo $response->getErrorSummary();
}
```

Do not rely on the HTTP status alone; `isSuccess()` also checks `EINV_STATUS` and that the QR code is present, as the integration guide requires. A response reported as submitted without a QR code is not a success and carries a `QR_CODE_MISSING` error.

Errors the SDK classifies itself carry one of these categories (`JoFotaraResponse::CATEGORY_*`), so you can tell a rejected invoice from an infrastructure problem worth retrying:

| HTTP status | Code | Category |
|-------------|------|----------|
| 400 | `API_ERROR` (or the API's own errors) | `API Validation` |
| 403 | `AUTH_ERROR` | `Authentication` |
| 500 | `SERVER_ERROR` | `Server Error` |
| 504 | `GATEWAY_TIMEOUT` | `Gateway Timeout` |
| any other failure status | `HTTP_ERROR` | `HTTP` |

Store the invoice ID, UUID, QR code and status. If sending fails or times out, send again with the **same** ID and UUID (never generate a new UUID): JoFotara answers `ALREADY_SUBMITTED` with the original QR code, which `isAlreadySubmitted()` reports.

## 🧪 Testing

**Important**: JoFotara does not provide a sandbox environment. For testing:

1. You need a registered entity with Jordan Tax Department
2. Your entity must be registered for JoFotara
3. Use past dates for test invoices
4. Always issue credit invoices to reverse test transactions

### Running Tests

```bash
# Run the test suite
composer test

# Generate a test invoice
php examples/GenerateGeneralInvoice.php

# On macOS, copy to clipboard
php examples/GenerateGeneralInvoice.php | pbcopy
```

## 🔒 Security

Never commit your JoFotara credentials to version control. Use environment variables:

```php
$invoice = new JoFotaraService(
    clientId: getenv('JOFOTARA_CLIENT_ID'),
    clientSecret: getenv('JOFOTARA_CLIENT_SECRET')
);
```

## 📄 License

The MIT License (MIT). Please see the [License File](LICENSE.md) for more information.


### Basic Invoice Information

```php
$invoice->basicInformation()
    ->setInvoiceId('INV-001')           // Required: Your unique invoice ID
    ->setUuid('123e4567-...')           // Required: UUID v4 format
    ->setIssueDate('16-02-2025')        // Required: Format dd-mm-yyyy
    ->cash()                            // Payment method: cash (012)
    // or
    ->receivable()                      // Payment method: receivable (022)
    ->setNote('Optional note')          // Optional: Invoice note
    ->setInvoiceCounter(1);             // Optional: Sequential counter (ICV)
```

### Seller Information

```php
// Configure default seller info (recommended)
SellerInformation::configureDefaults(
    tin: '123456789',
    name: 'Your Company'
);

// Or set per invoice
$invoice->sellerInformation()
    ->setName('Your Company')           // Required: Company name
    ->setTin('123456789');             // Required: Tax ID Number
```

### Buyer Information

```php
$invoice->customerInformation()
    ->setId('987654321', 'TIN')        // Required: ID (digits only) and type (TIN, NIN, or PN)
    ->setName('Customer Name')          // Required for receivables
    ->setPostalCode('11937')           // Optional: up to 5 digits
    ->setCityCode('JO-IR')             // Optional: Jordan city code
    ->setPhone('0791234567')           // Optional: 9 to 14 digits
    ->setTin('987654321');             // Optional
```

### Supplier Income Source

The supplier income source sequence (تسلسل مصدر الدخل) is a required value that must be set for each invoice. This value is obtained from your JoFotara portal and represents your business's income source sequence number.

```php
// Set the supplier income source sequence
$invoice->supplierIncomeSource('123456789');
```

> **Important**: The supplier income source sequence is mandatory and must be set before generating the invoice XML. You can find this value in the table where it shows your client ID and secret, under column "تسلسل مصدر الدخل".

Example usage in a complete invoice:

```php
$invoice = new JoFotaraService('your-client-id', 'your-client-secret');

// Set basic information
$invoice->basicInformation()
    ->setInvoiceId('INV-001')
    ->setIssueDate('2024-03-20')
    ->cash();

// Set seller information
$invoice->sellerInformation()
    ->setName('Your Company')
    ->setTin('123456789');

// Set buyer information
$invoice->customerInformation()
    ->setId('987654321', 'TN')
    ->setName('Customer Name');

// Set supplier income source (required)
$invoice->supplierIncomeSource('123456789');

// Add items...
$invoice->items()
    ->addItem('1')
    ->setQuantity(1)
    ->setUnitPrice(100.0)
    ->setDescription('Product')
    ->tax(16);
```

The supplier income source sequence is used by the JoFotara system to:
- Track your business's income sources
- Validate invoice submissions
- Ensure proper tax reporting

### Invoice Items and Tax Handling

```php
// Standard VAT item (16%)
$invoice->items()
    ->addItem('1')
    ->setQuantity(2)
    ->setUnitPrice(100.0)
    ->setDescription('Premium Widget')
    ->tax(16);

// Tax exempt item
$invoice->items()
    ->addItem('2')
    ->setQuantity(1)
    ->setUnitPrice(50.0)
    ->setDescription('Basic Widget')
    ->taxExempted();

// Zero-rated item (e.g., exports)
$invoice->items()
    ->addItem('3')
    ->setQuantity(1)
    ->setUnitPrice(75.0)
    ->setDescription('Export Widget')
    ->zeroTax();

// Item with discount
$invoice->items()
    ->addItem('4')
    ->setQuantity(1)
    ->setUnitPrice(200.0)
    ->setDescription('Discounted Widget')
    ->setDiscount(20.0)  // 20 JOD discount
    ->tax(16);
```

### Automatic Total Calculations

The SDK automatically calculates all invoice totals based on the items you add:

- Tax exclusive amount (before tax)
- Tax inclusive amount (after tax)
- Total discounts
- Total tax amount
- Final payable amount

Automatic calculations are applied once you call `->invoiceTotals()`. This method must be called after all items have been added.

For special cases, you can manually set totals:

```php
$invoice->invoiceTotals()
    ->setTaxExclusiveAmount(100.0)
    ->setTaxInclusiveAmount(92.8)
    ->setDiscountTotalAmount(20.0)
    ->setTaxTotalAmount(12.8)
    ->setPayableAmount(92.8);
```

> **Note**: When manually setting totals, they must match the calculated values from the items, or an exception will be thrown to ensure data integrity.

## API Communication

The `send()` method handles the complete flow:

1. XML generation and validation
2. Base64 encoding
3. API authentication
4. Error handling

```php
try {
    $response = $invoice->send();
    $qrCode = $response['qrCode'];
} catch (InvalidArgumentException $e) {
    // Handle validation errors
    echo $e->getMessage();
} catch (RuntimeException $e) {
    // Handle API communication errors
    echo $e->getMessage();
}
```

## Validation

The SDK includes comprehensive validation to ensure your invoice meets JoFotara requirements:

- All required fields are present and properly formatted
- Date formats follow dd-mm-yyyy pattern
- Tax calculations are accurate and consistent
- Totals match line items
- Valid city codes and tax categories

Validation errors throw `InvalidArgumentException` with descriptive messages to help you quickly identify and fix issues.

The buyer's tax number type can be given as `TIN` or as `TN`, the scheme name of the integration guide; both are sent as `TN`.

> **Upgrading:** following the integration guide, the buyer ID must now be digits only for every type (`NIN`, `PN`, `TIN`), the postal code at most 5 digits and the phone 9 to 14 digits. If you were sending alphanumeric passport numbers under `PN`, send the numeric personal number instead.

### Bypassing Validations

For advanced use cases where you need complete manual control over invoice data, you can bypass the built-in validations:

```php
// Initialize the SDK with validations disabled
$invoice = new JoFotaraService(
    clientId: 'your-client-id',
    clientSecret: 'your-client-secret',
    enableValidations: false
);
```

When validations are bypassed:

- Required sections are still checked (basic info, seller info, items, invoice totals)
- Detailed field validations are skipped
- No automatic calculations or consistency checks between line items and totals
- You have full control over all amount values

**Note:** Use this feature with caution. Incorrect invoice data may be rejected by the JoFotara API.

## Development Testing

```bash
vendor/bin/pest
```

## Contributing

Contributions are welcome! Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security-related issues, please email security@jbadarneh.com instead of using the issue tracker.

## Credits

- [Jafar Albadarneh](https://github.com/jafar-albadarneh)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
