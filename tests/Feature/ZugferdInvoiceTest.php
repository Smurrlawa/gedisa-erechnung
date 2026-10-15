<?php

declare(strict_types=1);

use App\Actions\GenerateZugferdXmlFromStripeInvoice;
use App\Zugferd\ZugferdInvoiceGenerator;
use Stripe\CreditNote as StripeCreditNote;
use Stripe\Invoice as StripeInvoice;
use Stripe\Service\CreditNoteService;
use Stripe\Service\InvoiceService;
use Stripe\Service\TaxRateService;
use Stripe\StripeClient;
use Stripe\TaxRate;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stripeInvoiceData(array $overrides = []): array
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
                'id' => 'il_1',
                'description' => '1 × Basispaket (at €10.00 / month)',
                'quantity' => 1,
                'subtotal' => 1000,
                'pricing' => ['unit_amount_decimal' => '1000'],
                'period' => ['start' => 1_743_465_600, 'end' => 1_751_328_000],
                'discount_amounts' => [],
                'taxes' => [['amount' => 190, 'taxable_amount' => 1000, 'tax_rate_details' => ['tax_rate' => 'txr_19']]],
            ]],
        ],
        'total_taxes' => [['amount' => 190, 'taxable_amount' => 1000, 'tax_rate_details' => ['tax_rate' => 'txr_19']]],
    ];

    return array_replace_recursive($base, $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeStripeInvoice(array $overrides = []): StripeInvoice
{
    return StripeInvoice::constructFrom(stripeInvoiceData($overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeStripeCreditNote(array $overrides = []): StripeCreditNote
{
    $base = [
        'id' => 'cn_TEST123',
        'object' => 'credit_note',
        'number' => 'GEDISA-0001-CN-01',
        'currency' => 'eur',
        'status' => 'issued',
        'type' => 'post_payment',
        'refunds' => [['amount_refunded' => 1190, 'refund' => 're_1']],
        'created' => 1_752_537_600,
        'total' => 1190,
        'invoice' => stripeInvoiceData(),
        'lines' => [
            'object' => 'list',
            'data' => [[
                'object' => 'credit_note_line_item',
                'type' => 'invoice_line_item',
                'invoice_line_item' => 'il_1',
                'description' => null,
                'quantity' => 1,
                'amount' => 1000,
                'unit_amount_decimal' => '1000',
                'discount_amounts' => [],
                'taxes' => [['amount' => 190, 'taxable_amount' => 1000, 'tax_rate_details' => ['tax_rate' => 'txr_19']]],
            ]],
        ],
        'total_taxes' => [['amount' => 190, 'taxable_amount' => 1000, 'tax_rate_details' => ['tax_rate' => 'txr_19']]],
    ];

    return StripeCreditNote::constructFrom(array_replace_recursive($base, $overrides));
}

/**
 * @return array<string, float>
 */
function taxRates(): array
{
    return ['txr_19' => 19.0];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function xmlFor(array $overrides = []): string
{
    return (new ZugferdInvoiceGenerator)->generate(makeStripeInvoice($overrides), taxRates());
}

function mockedStripeClient(): StripeClient
{
    $taxRates = Mockery::mock(TaxRateService::class);
    $taxRates->shouldReceive('retrieve')->with('txr_19')->andReturn(TaxRate::constructFrom(['id' => 'txr_19', 'percentage' => 19.0]));

    $client = Mockery::mock(StripeClient::class);
    $client->taxRates = $taxRates;

    return $client;
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
                'taxes' => [['amount' => 190, 'taxable_amount' => 1000, 'tax_rate_details' => ['tax_rate' => 'txr_19']]]],
            ['description' => 'Position B', 'quantity' => 1, 'subtotal' => 2000,
                'pricing' => ['unit_amount_decimal' => '2000'], 'discount_amounts' => [],
                'taxes' => [['amount' => 380, 'taxable_amount' => 2000, 'tax_rate_details' => ['tax_rate' => 'txr_19']]]],
        ]],
        'total_taxes' => [['amount' => 570, 'taxable_amount' => 3000, 'tax_rate_details' => ['tax_rate' => 'txr_19']]],
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
            'taxes' => [['amount' => 171, 'taxable_amount' => 900, 'tax_rate_details' => ['tax_rate' => 'txr_19']]],
        ]]],
        'total_taxes' => [['amount' => 171, 'taxable_amount' => 900, 'tax_rate_details' => ['tax_rate' => 'txr_19']]],
    ]);

    expect($xml)
        ->toContain('CardLink Partnerapotheke')
        ->toContain('9.00')
        ->toContain('1.71')
        ->toContain('10.71');
});

it('generates a credit note (381) with its own number and a reference to the original invoice (Test 7)', function () {
    $xml = (new ZugferdInvoiceGenerator)->generateCreditNote(makeStripeCreditNote(), taxRates());

    $dom = new DOMDocument;
    expect($dom->loadXML($xml))->toBeTrue();

    expect($xml)
        ->toContain('>381<')
        ->toContain('<ram:ID>GEDISA-0001-CN-01</ram:ID>')
        ->toContain('<ram:InvoiceReferencedDocument>')
        ->toContain('<ram:IssuerAssignedID>GEDISA-0001</ram:IssuerAssignedID>')
        ->toContain('20250701')
        ->toContain('1 × Basispaket')
        ->toContain('Cyrano Test')
        ->toContain('<ram:DuePayableAmount>11.90</ram:DuePayableAmount>')
        ->not->toContain('SpecifiedTradeSettlementPaymentMeans');
});

it('supports partial credit notes', function () {
    $xml = (new ZugferdInvoiceGenerator)->generateCreditNote(makeStripeCreditNote([
        'total' => 595,
        'lines' => ['data' => [[
            'amount' => 500,
            'unit_amount_decimal' => '500',
            'taxes' => [['amount' => 95, 'taxable_amount' => 500, 'tax_rate_details' => ['tax_rate' => 'txr_19']]],
        ]]],
        'total_taxes' => [['amount' => 95, 'taxable_amount' => 500, 'tax_rate_details' => ['tax_rate' => 'txr_19']]],
    ]), taxRates());

    expect($xml)->toContain('<ram:GrandTotalAmount>5.95</ram:GrandTotalAmount>');
});

it('uses the exact Stripe tax rate instead of deriving it from rounded per-line amounts (GEDISA-105338)', function () {
    $xml = xmlFor([
        'total' => 19814,
        'amount_paid' => 19814,
        'lines' => ['data' => [
            ['id' => 'il_1', 'description' => 'Basispaket', 'quantity' => 1, 'subtotal' => 5700,
                'pricing' => ['unit_amount_decimal' => '5700'],
                'discount_amounts' => [['amount' => 5700, 'discount' => 'di_1']],
                'taxes' => [['amount' => 0, 'taxable_amount' => 0, 'tax_rate_details' => ['tax_rate' => 'txr_19']]]],
            ['id' => 'il_2', 'description' => 'CardLink Paket S', 'quantity' => 1, 'subtotal' => 14700,
                'pricing' => ['unit_amount_decimal' => '14700'], 'discount_amounts' => [],
                'taxes' => [['amount' => 2793, 'taxable_amount' => 14700, 'tax_rate_details' => ['tax_rate' => 'txr_19']]]],
            ['id' => 'il_3', 'description' => 'KIM', 'quantity' => 1, 'subtotal' => 1950,
                'pricing' => ['unit_amount_decimal' => '1950'], 'discount_amounts' => [],
                'taxes' => [['amount' => 371, 'taxable_amount' => 1950, 'tax_rate_details' => ['tax_rate' => 'txr_19']]]],
        ]],
        'total_taxes' => [['amount' => 3164, 'taxable_amount' => 16650, 'tax_rate_details' => ['tax_rate' => 'txr_19']]],
    ]);

    expect(substr_count($xml, '<ram:RateApplicablePercent>19</ram:RateApplicablePercent>'))->toBe(4)
        ->and($xml)->not->toContain('19.03')
        ->and($xml)->not->toContain('<ram:CategoryCode>Z</ram:CategoryCode>');
});

it('uses the exact Stripe tax rate on credit notes too (GEDISA-105338-CN-02)', function () {
    $xml = (new ZugferdInvoiceGenerator)->generateCreditNote(makeStripeCreditNote([
        'total' => 6605,
        'lines' => ['data' => [
            ['object' => 'credit_note_line_item', 'invoice_line_item' => 'il_1', 'description' => null, 'quantity' => 1,
                'amount' => 4900, 'unit_amount_decimal' => '4900', 'discount_amounts' => [],
                'taxes' => [['amount' => 931, 'taxable_amount' => 4900, 'tax_rate_details' => ['tax_rate' => 'txr_19']]]],
            ['object' => 'credit_note_line_item', 'invoice_line_item' => 'il_1', 'description' => 'KIM', 'quantity' => 1,
                'amount' => 650, 'unit_amount_decimal' => '650', 'discount_amounts' => [],
                'taxes' => [['amount' => 124, 'taxable_amount' => 650, 'tax_rate_details' => ['tax_rate' => 'txr_19']]]],
        ]],
        'total_taxes' => [['amount' => 1055, 'taxable_amount' => 5550, 'tax_rate_details' => ['tax_rate' => 'txr_19']]],
    ]), taxRates());

    expect(substr_count($xml, '<ram:RateApplicablePercent>19</ram:RateApplicablePercent>'))->toBe(3)
        ->and($xml)->not->toContain('19.08')
        ->and($xml)->not->toContain('19.01');
});

it('refuses to guess when a tax rate cannot be resolved', function () {
    (new ZugferdInvoiceGenerator)->generate(makeStripeInvoice(), []);
})->throws(RuntimeException::class, 'Tax rate could not be determined');

it('collects the referenced tax rate ids', function () {
    expect((new ZugferdInvoiceGenerator)->taxRateIds(makeStripeInvoice()))->toBe(['txr_19']);
});

it('adds SEPA payment terms when Stripe collects automatically and sets no due date (GEDISA-121371)', function () {
    $xml = xmlFor([
        'due_date' => null,
        'status' => 'open',
        'amount_remaining' => 1190,
        'collection_method' => 'charge_automatically',
        'payment_settings' => ['payment_method_types' => ['sepa_debit']],
    ]);

    expect($xml)
        ->toContain('<ram:Description>Der Rechnungsbetrag wird per SEPA-Lastschrift von Ihrem Konto eingezogen.</ram:Description>')
        ->not->toContain('DueDateDateTime');
});

it('states the refund in the payment terms of a refunded credit note (GEDISA-105338-CN-02)', function () {
    $xml = (new ZugferdInvoiceGenerator)->generateCreditNote(makeStripeCreditNote(), taxRates());

    expect($xml)->toContain('<ram:Description>Der Betrag wird auf die ursprüngliche Zahlungsmethode erstattet.</ram:Description>');
});

it('refuses an open amount without due date or payment terms (BR-CO-25)', function () {
    xmlFor(['due_date' => null, 'amount_remaining' => 1190]);
})->throws(RuntimeException::class, 'BR-CO-25');

it('refuses voided invoices because a cancellation must be a Stripe credit note', function () {
    xmlFor(['status' => 'void']);
})->throws(RuntimeException::class, 'credit note');

it('refuses credit notes whose invoice is not expanded', function () {
    (new ZugferdInvoiceGenerator)->generateCreditNote(StripeCreditNote::constructFrom([
        'id' => 'cn_X', 'object' => 'credit_note', 'number' => 'CN-X', 'invoice' => 'in_123',
    ]));
})->throws(RuntimeException::class, 'expanded invoice');

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

    $client = mockedStripeClient();
    $client->invoices = $invoices;

    $xml = (new GenerateZugferdXmlFromStripeInvoice($client, new ZugferdInvoiceGenerator))->handle('in_TEST123');

    expect($xml)->toContain('CrossIndustryInvoice')->toContain('GEDISA-0001');
});

it('routes credit note ids (cn_) through the credit note endpoint', function () {
    $creditNotes = Mockery::mock(CreditNoteService::class);
    $creditNotes->shouldReceive('retrieve')->once()->andReturn(makeStripeCreditNote());

    $client = mockedStripeClient();
    $client->creditNotes = $creditNotes;

    $xml = (new GenerateZugferdXmlFromStripeInvoice($client, new ZugferdInvoiceGenerator))->handle('cn_TEST123');

    expect($xml)->toContain('>381<')->toContain('GEDISA-0001-CN-01');
});
