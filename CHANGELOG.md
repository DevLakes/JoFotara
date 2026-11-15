# Changelog

All notable changes to `jofotara` will be documented in this file.

## Unreleased

Aligns the SDK with version 1.5 (May 2026) of the JoFotara technical integration guide.

### Added

- Transaction types for every invoice type: export, development zone, transit, foreign trade and free zone transfer (`setTransactionType()`), 36 payment method codes in total (`getSupportedPaymentMethods()`).
- Invoice currency (`setCurrency()`) with the 18 currencies JoFotara accepts.
- `setExchangeRate()`: the JOD value of one unit of the invoice currency, used only to apply the buyer name rule to foreign currency cash invoices. It is never sent to JoFotara.
- Special sales tax per line (`setSpecialTax()`) and consumer price (`setConsumerPrice()`).
- `JoFotaraResponse::fromHttpResponse()`, `isAlreadySubmitted()`, `hasQrCode()` and the `CATEGORY_*` constants.
- `CustomerInformation::getIdScheme()`: the buyer ID scheme as sent to JoFotara.

### Changed

- The invoice XML carries `<cbc:ProfileID>reporting:1.0</cbc:ProfileID>`, and the buyer's tax number scheme is sent as `TN` (`TIN` is still accepted and still returned by `toArray()`).
- `isSuccess()` requires the QR code: a `SUBMITTED` or `ALREADY_SUBMITTED` response without one is not a success and reports a `QR_CODE_MISSING` error.
- HTTP 500 is reported as `SERVER_ERROR` / `Server Error`, 504 as `GATEWAY_TIMEOUT` / `Gateway Timeout` and other unexpected statuses as `HTTP_ERROR` / `HTTP`, instead of `API_ERROR` / `API Validation`.
- Stricter validation following the guide: buyer ID digits only, postal code at most 5 digits, phone 9 to 14 digits, approved tax rates only, zero-rated lines on non-local sales invoices, buyer tax number on development zone invoices, buyer name on foreign currency cash invoices unless an exchange rate shows the total is not above 10,000 JOD.
- Caller-supplied totals are accepted when they differ from the line items by at most 0.001, as the guide allows.

### Fixed

- Valid invoices were refused because amounts were compared as exact floating point numbers: automatically calculated totals (about 29% of simple invoices, e.g. 7 x 640.86 at 5%) and full line discounts (e.g. 3 x 1.15 with a discount of 3.45).
- Totals calculated by the SDK went stale when line items changed afterwards; they are now recalculated.
- The original invoice total on credit invoices was written with a thousands separator and two decimals.
- A response body that was valid JSON but not an object raised a `TypeError`; a failed response could report no errors.
- The CI test command used an option Pest 4 does not have.
