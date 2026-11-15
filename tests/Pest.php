<?php

use JBadarneh\JoFotara\JoFotaraService;

// Helpers shared by the review regression tests

function reviewInvoice(string $invoiceType = 'general_sales', bool $withBuyerName = true): JoFotaraService
{
    $invoice = new JoFotaraService('test-client-id', 'test-client-secret');

    $invoice->basicInformation()
        ->setInvoiceId('INV-001')
        ->setUuid('123e4567-e89b-12d3-a456-426614174000')
        ->setIssueDate('16-02-2025')
        ->setInvoiceType($invoiceType)
        ->cash();
    $invoice->sellerInformation()->setName('Seller')->setTin('12345678');
    $invoice->supplierIncomeSource('9932895');

    if ($withBuyerName) {
        $invoice->customerInformation()->setId('33445544', 'TN')->setName('Buyer');
    }

    return $invoice;
}

function xmlAmount(string $xml, string $element): string
{
    preg_match('#<cbc:'.$element.' currencyID="JO">([^<]+)<#', $xml, $match);

    return $match[1] ?? '';
}
