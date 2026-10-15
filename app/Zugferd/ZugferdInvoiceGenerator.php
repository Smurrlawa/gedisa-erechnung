<?php

declare(strict_types=1);

namespace App\Zugferd;

use easybill\eInvoicing\CII\Documents\CrossIndustryInvoice;
use easybill\eInvoicing\CII\Models\Amount;
use easybill\eInvoicing\CII\Models\DateTime;
use easybill\eInvoicing\CII\Models\DocumentContextParameter;
use easybill\eInvoicing\CII\Models\DocumentLineDocument;
use easybill\eInvoicing\CII\Models\ExchangedDocument;
use easybill\eInvoicing\CII\Models\ExchangedDocumentContext;
use easybill\eInvoicing\CII\Models\FormattedDateTime;
use easybill\eInvoicing\CII\Models\HeaderTradeAgreement;
use easybill\eInvoicing\CII\Models\HeaderTradeDelivery;
use easybill\eInvoicing\CII\Models\HeaderTradeSettlement;
use easybill\eInvoicing\CII\Models\Indicator;
use easybill\eInvoicing\CII\Models\LineTradeAgreement;
use easybill\eInvoicing\CII\Models\LineTradeDelivery;
use easybill\eInvoicing\CII\Models\LineTradeSettlement;
use easybill\eInvoicing\CII\Models\Period;
use easybill\eInvoicing\CII\Models\Quantity;
use easybill\eInvoicing\CII\Models\ReferencedDocument;
use easybill\eInvoicing\CII\Models\SupplyChainEvent;
use easybill\eInvoicing\CII\Models\SupplyChainTradeLineItem;
use easybill\eInvoicing\CII\Models\SupplyChainTradeTransaction;
use easybill\eInvoicing\CII\Models\TaxRegistration;
use easybill\eInvoicing\CII\Models\TradeAddress;
use easybill\eInvoicing\CII\Models\TradeAllowanceCharge;
use easybill\eInvoicing\CII\Models\TradeContact;
use easybill\eInvoicing\CII\Models\TradeParty;
use easybill\eInvoicing\CII\Models\TradePaymentTerms;
use easybill\eInvoicing\CII\Models\TradePrice;
use easybill\eInvoicing\CII\Models\TradeProduct;
use easybill\eInvoicing\CII\Models\TradeSettlementHeaderMonetarySummation;
use easybill\eInvoicing\CII\Models\TradeSettlementLineMonetarySummation;
use easybill\eInvoicing\CII\Models\TradeSettlementPaymentMeans;
use easybill\eInvoicing\CII\Models\TradeTax;
use easybill\eInvoicing\CII\Models\UniversalCommunication;
use easybill\eInvoicing\CII\Models\UriID;
use easybill\eInvoicing\Enums\CountryCode;
use easybill\eInvoicing\Enums\CurrencyCode;
use easybill\eInvoicing\Enums\DocumentType;
use easybill\eInvoicing\Enums\ElectronicAddressScheme;
use easybill\eInvoicing\Enums\UnitCode;
use easybill\eInvoicing\Transformer;
use RuntimeException;
use Stripe\CreditNote as StripeCreditNote;
use Stripe\Invoice as StripeInvoice;
use Stripe\StripeObject;

final class ZugferdInvoiceGenerator
{
    private const EN16931_PROFILE = 'urn:cen.eu:en16931:2017';

    private const DATE_FORMAT = 102;

    /**
     * @param  array<string, float>  $taxRates
     */
    public function generate(StripeInvoice $invoice, array $taxRates = []): string
    {
        if ($this->str($invoice, 'status') === 'void') {
            throw new RuntimeException('Voided Stripe invoices cannot be converted. A cancellation must be issued as a Stripe credit note.');
        }

        return $this->build(
            source: $invoice,
            invoice: $invoice,
            typeCode: DocumentType::COMMERCIAL_INVOICE,
            number: $this->str($invoice, 'number') ?? $this->str($invoice, 'id')
                ?? throw new RuntimeException('Stripe invoice has no number/id.'),
            issueTimestamp: $this->int($invoice, 'status_transitions.finalized_at') ?? $this->int($invoice, 'created'),
            prepaidMinor: abs($this->int($invoice, 'amount_paid') ?? 0),
            paymentMeans: $this->paymentMeans($invoice),
            paymentTerms: $this->paymentTerms($invoice),
            referencedDocument: null,
            taxRates: $taxRates,
        );
    }

    /**
     * @param  array<string, float>  $taxRates
     */
    public function generateCreditNote(StripeCreditNote $creditNote, array $taxRates = []): string
    {
        if ($this->str($creditNote, 'status') === 'void') {
            throw new RuntimeException('Voided Stripe credit notes cannot be converted.');
        }

        $invoice = $this->get($creditNote, 'invoice');
        if (! $invoice instanceof StripeObject || $this->str($invoice, 'object') !== 'invoice') {
            throw new RuntimeException('Credit note has no expanded invoice to reference.');
        }

        return $this->build(
            source: $creditNote,
            invoice: $invoice,
            typeCode: DocumentType::CREDIT_NOTE,
            number: $this->str($creditNote, 'number') ?? throw new RuntimeException('Stripe credit note has no number.'),
            issueTimestamp: $this->int($creditNote, 'effective_at') ?? $this->int($creditNote, 'created'),
            prepaidMinor: 0,
            paymentMeans: null,
            paymentTerms: $this->creditNotePaymentTerms($creditNote),
            referencedDocument: $this->referencedInvoice($invoice),
            taxRates: $taxRates,
        );
    }

    /**
     * @return list<string>
     */
    public function taxRateIds(StripeObject $document): array
    {
        $entries = [...($this->get($document, 'total_taxes') ?? []), ...($this->get($document, 'total_tax_amounts') ?? [])];
        foreach ($this->lineObjects($document) as $line) {
            $entries = [...$entries, ...($this->get($line, 'taxes') ?? []), ...($this->get($line, 'tax_amounts') ?? [])];
        }

        $ids = [];
        foreach ($entries as $entry) {
            $id = $this->str($entry, 'tax_rate_details.tax_rate') ?? $this->str($entry, 'tax_rate');
            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param  array<string, float>  $taxRates
     */
    private function build(
        StripeObject $source,
        StripeObject $invoice,
        DocumentType $typeCode,
        string $number,
        ?int $issueTimestamp,
        int $prepaidMinor,
        ?TradeSettlementPaymentMeans $paymentMeans,
        ?TradePaymentTerms $paymentTerms,
        ?ReferencedDocument $referencedDocument,
        array $taxRates,
    ): string {
        $currency = $this->currency($this->str($source, 'currency') ?? throw new RuntimeException('Stripe document has no currency.'));
        $issueYmd = $this->ymd($issueTimestamp) ?? throw new RuntimeException('Stripe document has no issue date.');

        $lineObjects = $this->lineObjects($source);
        if ($lineObjects === []) {
            throw new RuntimeException('Stripe document has no line items.');
        }

        $fallbackNames = $this->lineDescriptions($invoice);
        $documentRates = [];
        foreach ($this->get($source, 'total_taxes') ?? $this->get($source, 'total_tax_amounts') ?? [] as $entry) {
            $resolved = $this->resolveRate($entry, $taxRates);
            $documentRates[implode('|', $resolved)] = $resolved;
        }
        $documentRates = array_values($documentRates);

        $lineItems = [];
        $lineTotalMinor = 0;
        $basisByRate = [];
        $position = 1;
        foreach ($lineObjects as $lineObject) {
            $line = $this->buildLine($lineObject, (string) $position, $fallbackNames, $taxRates, $documentRates);
            $lineItems[] = $line['item'];
            $lineTotalMinor += $line['net'];

            $key = $line['category'].'|'.$line['rate'];
            $basisByRate[$key] ??= ['category' => $line['category'], 'rate' => $line['rate'], 'basis' => 0];
            $basisByRate[$key]['basis'] += $line['net'];
            $position++;
        }

        [$taxNodes, $taxTotalMinor] = $this->buildHeaderTaxes($source, $basisByRate, $taxRates);

        $grandTotalMinor = $lineTotalMinor + $taxTotalMinor;
        $stripeTotal = abs($this->int($source, 'total') ?? 0);
        if ($grandTotalMinor !== $stripeTotal) {
            throw new RuntimeException(sprintf(
                'Rounding mismatch: computed grand total %d != Stripe total %d (minor units).',
                $grandTotalMinor,
                $stripeTotal,
            ));
        }

        if ($grandTotalMinor - $prepaidMinor > 0 && $paymentTerms?->dueDate === null && $paymentTerms?->description === null) {
            throw new RuntimeException('Amount due is positive but neither a due date nor payment terms are available (EN16931 BR-CO-25).');
        }

        [$periodStart, $periodEnd] = $this->servicePeriod($invoice);

        $document = new CrossIndustryInvoice;
        $document->exchangedDocumentContext = $this->context();
        $document->exchangedDocument = $this->document($typeCode, $number, $issueYmd);

        $tx = new SupplyChainTradeTransaction;
        $tx->lineItems = $lineItems;

        $tx->applicableHeaderTradeAgreement = new HeaderTradeAgreement;
        $tx->applicableHeaderTradeAgreement->sellerTradeParty = $this->seller();
        $tx->applicableHeaderTradeAgreement->buyerTradeParty = $this->buyer($invoice);

        $tx->applicableHeaderTradeDelivery = new HeaderTradeDelivery;
        if ($periodEnd !== null) {
            $tx->applicableHeaderTradeDelivery->chainEvent = $event = new SupplyChainEvent;
            $event->date = $this->date($periodEnd);
        }

        $settlement = new HeaderTradeSettlement;
        $settlement->paymentReference = $number;
        $settlement->invoiceCurrency = $currency;
        if ($paymentMeans !== null) {
            $settlement->specifiedTradeSettlementPaymentMeans[] = $paymentMeans;
        }
        $settlement->tradeTaxes = $taxNodes;
        if ($periodStart !== null && $periodEnd !== null) {
            $settlement->billingSpecifiedPeriod = $this->period($periodStart, $periodEnd);
        }
        $settlement->specifiedTradePaymentTerms = $paymentTerms;
        $settlement->specifiedTradeSettlementHeaderMonetarySummation = $this->summation(
            $currency,
            $lineTotalMinor,
            $taxTotalMinor,
            $grandTotalMinor,
            $prepaidMinor,
        );
        $settlement->invoiceReferencedDocument = $referencedDocument;

        $tx->applicableHeaderTradeSettlement = $settlement;
        $document->supplyChainTradeTransaction = $tx;

        return Transformer::create()->transformToXml($document);
    }

    private function context(): ExchangedDocumentContext
    {
        $context = new ExchangedDocumentContext;
        $context->documentContextParameter = new DocumentContextParameter;
        $context->documentContextParameter->id = self::EN16931_PROFILE;

        return $context;
    }

    private function document(DocumentType $typeCode, string $number, string $issueYmd): ExchangedDocument
    {
        $document = new ExchangedDocument;
        $document->id = $number;
        $document->typeCode = $typeCode;
        $document->issueDateTime = $this->date($issueYmd);

        return $document;
    }

    private function referencedInvoice(StripeObject $invoice): ReferencedDocument
    {
        $reference = ReferencedDocument::create(
            $this->str($invoice, 'number') ?? throw new RuntimeException('Referenced invoice has no number.')
        );

        $issuedYmd = $this->ymd($this->int($invoice, 'status_transitions.finalized_at') ?? $this->int($invoice, 'created'));
        if ($issuedYmd !== null) {
            $reference->formattedIssueDateTime = FormattedDateTime::create(self::DATE_FORMAT, $issuedYmd);
        }

        return $reference;
    }

    private function summation(
        CurrencyCode $currency,
        int $lineTotalMinor,
        int $taxTotalMinor,
        int $grandTotalMinor,
        int $prepaidMinor,
    ): TradeSettlementHeaderMonetarySummation {
        $summation = new TradeSettlementHeaderMonetarySummation;
        $summation->lineTotalAmount = $this->amount($lineTotalMinor);
        $summation->chargeTotalAmount = $this->amount(0);
        $summation->allowanceTotalAmount = $this->amount(0);
        $summation->taxBasisTotalAmount[] = $this->amount($lineTotalMinor);
        $summation->taxTotalAmount[] = $this->amount($taxTotalMinor, $currency);
        $summation->grandTotalAmount[] = $this->amount($grandTotalMinor);
        $summation->totalPrepaidAmount = $this->amount($prepaidMinor);
        $summation->duePayableAmount = $this->amount($grandTotalMinor - $prepaidMinor);

        return $summation;
    }

    /**
     * @param  array<string, string>  $fallbackNames
     * @param  array<string, float>  $taxRates
     * @param  list<array{0: string, 1: string}>  $documentRates
     * @return array{item: SupplyChainTradeLineItem, net: int, category: string, rate: string}
     */
    private function buildLine(object $line, string $position, array $fallbackNames, array $taxRates, array $documentRates): array
    {
        $name = $this->str($line, 'description');
        if ($name === null || $name === '') {
            $name = $fallbackNames[$this->str($line, 'invoice_line_item') ?? ''] ?? null;
        }
        if ($name === null || $name === '') {
            throw new RuntimeException("Missing description on line {$position}.");
        }

        $quantity = $this->int($line, 'quantity') ?? 1;

        $grossMinor = abs($this->int($line, 'subtotal')
            ?? $this->int($line, 'amount')
            ?? throw new RuntimeException("Missing amount on line {$position}."));

        $unitMinor = abs($this->decimalMinor($this->str($line, 'pricing.unit_amount_decimal'))
            ?? $this->decimalMinor($this->str($line, 'unit_amount_decimal'))
            ?? $this->int($line, 'price.unit_amount')
            ?? $this->int($line, 'unit_amount')
            ?? ($quantity !== 0 ? intdiv($grossMinor, $quantity) : $grossMinor));

        $lineTax = ($this->get($line, 'taxes') ?? $this->get($line, 'tax_amounts') ?? [])[0] ?? null;
        [$category, $rate] = match (true) {
            $lineTax !== null => $this->resolveRate($lineTax, $taxRates),
            count($documentRates) === 1 => $documentRates[0],
            $documentRates === [] => ['Z', '0'],
            default => throw new RuntimeException("Cannot determine tax rate for line {$position}: no line tax and multiple document rates."),
        };

        $item = new SupplyChainTradeLineItem;
        $item->associatedDocumentLineDocument = DocumentLineDocument::create($position);

        $item->specifiedTradeProduct = new TradeProduct;
        $item->specifiedTradeProduct->name = $name;

        $item->tradeAgreement = new LineTradeAgreement;
        $item->tradeAgreement->grossPrice = TradePrice::create($this->money($unitMinor));
        $item->tradeAgreement->netPrice = TradePrice::create($this->money($unitMinor));

        $item->delivery = new LineTradeDelivery;
        $item->delivery->billedQuantity = Quantity::create((string) $quantity, UnitCode::C62);

        $settlement = new LineTradeSettlement;
        $tax = new TradeTax;
        $tax->typeCode = 'VAT';
        $tax->categoryCode = $category;
        $tax->rateApplicablePercent = $rate;
        $settlement->tradeTax[] = $tax;

        $discountSum = 0;
        foreach ($this->get($line, 'discount_amounts') ?? [] as $discount) {
            $amount = abs($this->int($discount, 'amount') ?? 0);
            if ($amount === 0) {
                continue;
            }
            $discountSum += $amount;
            $settlement->specifiedTradeAllowanceCharge[] = $this->allowance(
                $amount,
                $grossMinor,
                $this->str($discount, 'discount.coupon.name') ?? $this->str($discount, 'discount.coupon.id') ?? 'Discount',
            );
        }

        $periodStart = $this->ymd($this->int($line, 'period.start'));
        $periodEnd = $this->ymd($this->int($line, 'period.end'));
        if ($periodStart !== null && $periodEnd !== null) {
            $settlement->billingSpecifiedPeriod = $this->period($periodStart, $periodEnd);
        }

        $netMinor = $grossMinor - $discountSum;
        $settlement->monetarySummation = TradeSettlementLineMonetarySummation::create($this->money($netMinor));
        $item->specifiedLineTradeSettlement = $settlement;

        return ['item' => $item, 'net' => $netMinor, 'category' => $category, 'rate' => $rate];
    }

    /**
     * @param  array<string, array{category: string, rate: string, basis: int}>  $basisByRate
     * @param  array<string, float>  $taxRates
     * @return array{0: TradeTax[], 1: int}
     */
    private function buildHeaderTaxes(StripeObject $source, array $basisByRate, array $taxRates): array
    {
        $nodes = [];
        $taxTotalMinor = 0;

        $breakdown = $this->get($source, 'total_taxes') ?? $this->get($source, 'total_tax_amounts') ?? [];
        foreach ($breakdown as $entry) {
            [$category, $rate] = $this->resolveRate($entry, $taxRates);
            $basis = abs($this->int($entry, 'taxable_amount') ?? 0);
            $calculated = abs($this->int($entry, 'amount') ?? 0);
            $taxTotalMinor += $calculated;
            $nodes[] = $this->headerTax($category, $rate, $basis, $calculated);
        }

        if ($nodes !== []) {
            return [$nodes, $taxTotalMinor];
        }

        foreach ($basisByRate as $bucket) {
            $calculated = (int) round($bucket['basis'] * ((float) $bucket['rate']) / 100);
            $taxTotalMinor += $calculated;
            $nodes[] = $this->headerTax($bucket['category'], $bucket['rate'], $bucket['basis'], $calculated);
        }

        return [$nodes, $taxTotalMinor];
    }

    private function headerTax(string $category, string $rate, int $basisMinor, int $calculatedMinor): TradeTax
    {
        $tax = new TradeTax;
        $tax->typeCode = 'VAT';
        $tax->categoryCode = $category;
        $tax->rateApplicablePercent = $rate;
        $tax->basisAmount = $this->amount($basisMinor);
        $tax->calculatedAmount = $this->amount($calculatedMinor);

        return $tax;
    }

    private function allowance(int $actualMinor, int $basisMinor, string $reason): TradeAllowanceCharge
    {
        $indicator = new Indicator;
        $indicator->indicator = false;

        return TradeAllowanceCharge::create(
            actualAmount: $this->amount($actualMinor),
            indicator: $indicator,
            basisAmount: $this->amount($basisMinor),
            reason: $reason,
        );
    }

    private function seller(): TradeParty
    {
        /** @var array<string, mixed> $seller */
        $seller = config('erechnung.seller', []);
        foreach (['name', 'street', 'postcode', 'city', 'country'] as $required) {
            if (empty($seller[$required])) {
                throw new RuntimeException("Missing seller '{$required}' in config/erechnung.php.");
            }
        }

        $party = $this->party(
            name: (string) $seller['name'],
            street: (string) $seller['street'],
            postcode: (string) $seller['postcode'],
            city: (string) $seller['city'],
            country: (string) $seller['country'],
            email: isset($seller['email']) ? (string) $seller['email'] : null,
            phone: isset($seller['phone']) ? (string) $seller['phone'] : null,
        );

        if (! empty($seller['vat_id'])) {
            $party->taxRegistrations[] = TaxRegistration::create((string) $seller['vat_id'], 'VA');
        }

        return $party;
    }

    private function buyer(StripeObject $invoice): TradeParty
    {
        $address = $this->get($invoice, 'customer_address') ?? $this->get($invoice, 'customer.address');

        $street = trim(implode(' ', array_filter([$this->str($address, 'line1'), $this->str($address, 'line2')])));
        if ($street === '') {
            throw new RuntimeException('Missing buyer street on Stripe invoice.');
        }

        return $this->party(
            name: $this->str($invoice, 'customer_name') ?? $this->str($invoice, 'customer.name')
                ?? throw new RuntimeException('Missing buyer name on Stripe invoice.'),
            street: $street,
            postcode: $this->str($address, 'postal_code') ?? throw new RuntimeException('Missing buyer postal code on Stripe invoice.'),
            city: $this->str($address, 'city') ?? throw new RuntimeException('Missing buyer city on Stripe invoice.'),
            country: $this->str($address, 'country') ?? throw new RuntimeException('Missing buyer country on Stripe invoice.'),
            email: $this->str($invoice, 'customer_email') ?? $this->str($invoice, 'customer.email'),
            phone: $this->str($invoice, 'customer_phone') ?? $this->str($invoice, 'customer.phone'),
        );
    }

    private function party(
        string $name,
        string $street,
        string $postcode,
        string $city,
        string $country,
        ?string $email = null,
        ?string $phone = null,
    ): TradeParty {
        $party = new TradeParty;
        $party->name = $name;

        $party->postalTradeAddress = new TradeAddress;
        $party->postalTradeAddress->lineOne = $street;
        $party->postalTradeAddress->postcode = $postcode;
        $party->postalTradeAddress->city = $city;
        $party->postalTradeAddress->countryCode = $this->country($country);

        if ($phone !== null || $email !== null) {
            $party->definedTradeContact = $contact = new TradeContact;
            if ($phone !== null) {
                $contact->telephoneUniversalCommunication = $this->phoneEndpoint($phone);
            }
            if ($email !== null) {
                $contact->emailURIUniversalCommunication = $this->emailEndpoint($email);
            }
        }

        if ($email !== null) {
            $party->uriUniversalCommunication = $this->emailEndpoint($email);
        }

        return $party;
    }

    private function paymentMeans(StripeInvoice $invoice): TradeSettlementPaymentMeans
    {
        $means = new TradeSettlementPaymentMeans;

        if ($this->str($invoice, 'payment_intent.payment_method.type') === 'sepa_debit') {
            $means->typeCode = '59';

            return $means;
        }

        $means->typeCode = '68';
        $hostedUrl = $this->str($invoice, 'hosted_invoice_url');
        if ($hostedUrl !== null) {
            $means->information = "Pay online via Stripe: {$hostedUrl}";
        }

        return $means;
    }

    private function paymentTerms(StripeInvoice $invoice): ?TradePaymentTerms
    {
        $dueDateYmd = $this->ymd($this->int($invoice, 'due_date'));
        $mandate = $this->str($invoice, 'payment_intent.payment_method.type') === 'sepa_debit'
            ? $this->str($invoice, 'payment_intent.payment_method.sepa_debit.mandate') ?? $this->str($invoice, 'payment_intent.mandate')
            : null;

        $description = null;
        if ($dueDateYmd === null
            && ($this->int($invoice, 'amount_remaining') ?? 0) > 0
            && $this->str($invoice, 'collection_method') === 'charge_automatically') {
            $methodTypes = $this->get($invoice, 'payment_settings.payment_method_types');
            $description = is_array($methodTypes) && $methodTypes === ['sepa_debit']
                ? 'Der Rechnungsbetrag wird per SEPA-Lastschrift von Ihrem Konto eingezogen.'
                : 'Der Rechnungsbetrag wird automatisch über die hinterlegte Zahlungsmethode eingezogen.';
        }

        if ($dueDateYmd === null && $mandate === null && $description === null) {
            return null;
        }

        $terms = new TradePaymentTerms;
        $terms->description = $description;
        if ($dueDateYmd !== null) {
            $terms->dueDate = $this->date($dueDateYmd);
        }
        if ($mandate !== null) {
            $terms->directDebitMandateID = $mandate;
        }

        return $terms;
    }

    private function creditNotePaymentTerms(StripeCreditNote $creditNote): ?TradePaymentTerms
    {
        $refundedMinor = 0;
        foreach ($this->get($creditNote, 'refunds') ?? [] as $refund) {
            $refundedMinor += abs($this->int($refund, 'amount_refunded') ?? 0);
        }

        $parts = [];
        if ($refundedMinor > 0) {
            $parts[] = 'Der Betrag wird auf die ursprüngliche Zahlungsmethode erstattet.';
        }
        if ($this->get($creditNote, 'customer_balance_transaction') !== null) {
            $parts[] = 'Der Betrag wird Ihrem Kundenguthaben gutgeschrieben.';
        }
        if (($this->int($creditNote, 'out_of_band_amount') ?? 0) > 0) {
            $parts[] = 'Der Betrag wurde außerhalb von Stripe ausgeglichen.';
        }
        if (($this->int($creditNote, 'pre_payment_amount') ?? 0) > 0) {
            $parts[] = 'Der Betrag wird mit der offenen Rechnung verrechnet.';
        }

        if ($parts === []) {
            return null;
        }

        $terms = new TradePaymentTerms;
        $terms->description = implode(' ', $parts);

        return $terms;
    }

    private function emailEndpoint(string $email): UniversalCommunication
    {
        $comm = new UniversalCommunication;
        $comm->uriid = UriID::create($email, ElectronicAddressScheme::ELECTRONIC_MAIL);

        return $comm;
    }

    private function phoneEndpoint(string $number): UniversalCommunication
    {
        $comm = new UniversalCommunication;
        $comm->completeNumber = $number;

        return $comm;
    }

    private function period(string $startYmd, string $endYmd): Period
    {
        $period = new Period;
        $period->startDatetime = $this->date($startYmd);
        $period->endDatetime = $this->date($endYmd);

        return $period;
    }

    private function amount(int $minor, ?CurrencyCode $currency = null): Amount
    {
        return Amount::create($this->money($minor), $currency);
    }

    private function date(string $ymd): DateTime
    {
        return DateTime::create(self::DATE_FORMAT, $ymd);
    }

    /**
     * @param  array<string, float>  $taxRates
     * @return array{0: string, 1: string}
     */
    private function resolveRate(mixed $tax, array $taxRates): array
    {
        $rateId = $this->str($tax, 'tax_rate_details.tax_rate') ?? $this->str($tax, 'tax_rate');

        $percent = $this->get($tax, 'tax_rate.percentage')
            ?? $this->get($tax, 'rate.percentage')
            ?? ($rateId !== null ? $taxRates[$rateId] ?? null : null)
            ?? throw new RuntimeException('Tax rate could not be determined'.($rateId !== null ? " for '{$rateId}'." : '.'));

        $formatted = number_format((float) $percent, 2, '.', '');
        $rate = str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;

        return [((float) $percent) > 0 ? 'S' : 'Z', $rate];
    }

    private function decimalMinor(?string $decimal): ?int
    {
        return $decimal === null ? null : (int) round((float) $decimal);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function servicePeriod(StripeObject $invoice): array
    {
        $start = null;
        $end = null;
        foreach ($this->lineObjects($invoice) as $line) {
            $lineStart = $this->ymd($this->int($line, 'period.start'));
            $lineEnd = $this->ymd($this->int($line, 'period.end'));
            if ($lineStart !== null) {
                $start = $start === null ? $lineStart : min($start, $lineStart);
            }
            if ($lineEnd !== null) {
                $end = $end === null ? $lineEnd : max($end, $lineEnd);
            }
        }

        return [$start, $end];
    }

    /**
     * @return array<string, string>
     */
    private function lineDescriptions(StripeObject $invoice): array
    {
        $names = [];
        foreach ($this->lineObjects($invoice) as $line) {
            $id = $this->str($line, 'id');
            $description = $this->str($line, 'description');
            if ($id !== null && $description !== null) {
                $names[$id] = $description;
            }
        }

        return $names;
    }

    private function money(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    private function currency(string $code): CurrencyCode
    {
        return CurrencyCode::tryFrom(strtoupper($code)) ?? throw new RuntimeException("Unsupported currency: {$code}");
    }

    private function country(string $code): CountryCode
    {
        return CountryCode::tryFrom(strtoupper($code)) ?? throw new RuntimeException("Unsupported country: {$code}");
    }

    /**
     * @return list<object>
     */
    private function lineObjects(StripeObject $document): array
    {
        $lines = $this->get($document, 'lines.data') ?? [];

        return is_array($lines) ? array_values($lines) : iterator_to_array($lines);
    }

    private function get(mixed $object, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            if ($object === null) {
                return null;
            }
            $object = isset($object->{$key}) ? $object->{$key} : null;
        }

        return $object;
    }

    private function str(mixed $object, string $path): ?string
    {
        $value = $this->get($object, $path);

        return is_scalar($value) ? (string) $value : null;
    }

    private function int(mixed $object, string $path): ?int
    {
        $value = $this->get($object, $path);

        return $value === null ? null : (int) $value;
    }

    private function ymd(?int $timestamp): ?string
    {
        return $timestamp === null ? null : date('Ymd', $timestamp);
    }
}
