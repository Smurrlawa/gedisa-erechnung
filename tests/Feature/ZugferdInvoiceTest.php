<?php

declare(strict_types=1);

use App\Actions\GenerateZugferdXmlFromStripeInvoice;
use App\Zugferd\ZugferdInvoiceGenerator;
use Stripe\Invoice as StripeInvoice;
use Stripe\Service\InvoiceService;
use Stripe\StripeClient;

/**
 * @param  array<string, mixed>  $overrides
 */
function makeStripeInvoice(array $overrides = []): StripeInvoice
{
    $base = [
        'id' => 'in_TEST123',
        'object' => 'invoice',
        'number' => 'GEDISA-0001',
        'currency' => 'eur',
        'status' => 'open',
        'created' => 1_751_328_000,
        'due_date' => 1_752_537_600,
        'period_start' => 1_751_328_000,
        'period_end' => 1_751_328_000,
        'total' => 1190,
        'amount_paid' => 0,
        'hosted_invoice_url' => 'https://invoice.stripe.com/i/test',
        'customer_name' => 'Cyrano Test',
        'customer_email' => 'qa@gedisa.de',
        'customer_address' => [
            'line1' => 'Hohenzollernring 49-51',
            'line2' => null,
            'postal_code' => '48145',
            'city' => 'Münster',
            'country' => 'DE',
        ],
        'lines' => [
            'object' => 'list',
            'data' => [[
                'description' => '1 × Basispaket (at €10.00 / month)',
                'quantity' => 1,
                'subtotal' => 1000,
                'pricing' => ['unit_amount_decimal' => '1000'],
                'period' => ['start' => 1_743_465_600, 'end' => 1_751_328_000],
                'discount_amounts' => [],
                'taxes' => [['amount' => 190, 'taxable_amount' => 1000]],
            ]],
        ],
        'total_taxes' => [['amount' => 190, 'taxable_amount' => 1000]],
    ];

    return StripeInvoice::constructFrom(array_replace_recursive($base, $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function xmlFor(array $overrides = []): string
{
    return (new ZugferdInvoiceGenerator)->generate(makeStripeInvoice($overrides));
}

it('generates valid ZUGFeRD XML for a simple invoice (Test 1)', function () {
    $xml = xmlFor();

    $dom = new DOMDocument;
    expect($dom->loadXML($xml))->toBeTrue();

    expect($xml)
        ->toContain('CrossIndustryInvoice')
        ->toContain('urn:cen.eu:en16931:2017')
        ->toContain('>380<')
        ->toContain('GEDISA-0001')
        ->toContain('Cyrano Test')
        ->toContain('Münster')
        ->toContain('DE350031105')
        ->toContain('>19<')
        ->toContain('11.90')
        ->toContain('20250715');
});

it('renders the unit price in EUR, not in cents (Stripe unit_amount_decimal is already minor units)', function () {
    $xml = xmlFor();

    expect($xml)
        ->toContain('<ram:ChargeAmount>10.00</ram:ChargeAmount>')
        ->not->toContain('1000.00');
});

it('derives the header billing period and delivery date from the line periods, not from invoice.period_*', function () {
    $xml = xmlFor();

    expect($xml)
        ->toContain('20250401')
        ->toContain('20250701');

    $headerPeriod = substr($xml, strpos($xml, 'ApplicableHeaderTradeSettlement'));
    expect(substr_count($headerPeriod, '20250401'))->toBeGreaterThanOrEqual(1);
});

it('maps every invoice line separately (Test 2)', function () {
    $xml = xmlFor([
        'total' => 3570,
        'lines' => ['data' => [
            ['description' => 'Position A', 'quantity' => 1, 'subtotal' => 1000,
                'pricing' => ['unit_amount_decimal' => '1000'], 'discount_amounts' => [],
                'taxes' => [['amount' => 190, 'taxable_amount' => 1000]]],
            ['description' => 'Position B', 'quantity' => 1, 'subtotal' => 2000,
                'pricing' => ['unit_amount_decimal' => '2000'], 'discount_amounts' => [],
                'taxes' => [['amount' => 380, 'taxable_amount' => 2000]]],
        ]],
        'total_taxes' => [['amount' => 570, 'taxable_amount' => 3000]],
    ]);

    expect($xml)->toContain('Position A')->toContain('Position B')->toContain('35.70');
});

it('applies a line discount and keeps net/tax/gross consistent (Test 3 & 4)', function () {
    $xml = xmlFor([
        'total' => 1071,
        'lines' => ['data' => [[
            'description' => 'Rabattierte Position', 'quantity' => 1, 'subtotal' => 1000,
            'pricing' => ['unit_amount_decimal' => '1000'],
            'discount_amounts' => [['amount' => 100, 'discount' => ['coupon' => ['name' => 'CardLink Partnerapotheke']]]],
            'taxes' => [['amount' => 171, 'taxable_amount' => 900]],
        ]]],
        'total_taxes' => [['amount' => 171, 'taxable_amount' => 900]],
    ]);

    expect($xml)
        ->toContain('CardLink Partnerapotheke')
        ->toContain('9.00')
        ->toContain('1.71')
        ->toContain('10.71');
});

it('produces a credit note document type for a voided invoice (Test 7)', function () {
    expect(xmlFor(['status' => 'void']))->toContain('>381<');
});

it('sets SEPA direct debit payment means when a SEPA mandate is present (Test 5)', function () {
    $xml = xmlFor([
        'payment_intent' => ['payment_method' => [
            'type' => 'sepa_debit',
            'sepa_debit' => ['mandate' => 'mandate_ref_123'],
        ]],
    ]);

    expect($xml)->toContain('>59<')->toContain('mandate_ref_123');
});

it('throws a clear exception when a required buyer field is missing (Test 8)', function () {
    xmlFor(['customer_address' => ['city' => null]]);
})->throws(RuntimeException::class);

it('throws when the computed total does not match the Stripe total (rounding guard)', function () {
    xmlFor(['total' => 9999]);
})->throws(RuntimeException::class, 'Rounding mismatch');

it('runs end-to-end through the Action with a mocked Stripe client', function () {
    $invoices = Mockery::mock(InvoiceService::class);
    $invoices->shouldReceive('retrieve')->once()->andReturn(makeStripeInvoice());

    $client = Mockery::mock(StripeClient::class);
    $client->invoices = $invoices;

    $xml = (new GenerateZugferdXmlFromStripeInvoice($client, new ZugferdInvoiceGenerator))->handle('in_TEST123');

    expect($xml)->toContain('CrossIndustryInvoice')->toContain('GEDISA-0001');
});
