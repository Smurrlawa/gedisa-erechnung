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
use easybill\eInvoicing\CII\Models\HeaderTradeAgreement;
use easybill\eInvoicing\CII\Models\HeaderTradeDelivery;
use easybill\eInvoicing\CII\Models\HeaderTradeSettlement;
use easybill\eInvoicing\CII\Models\Indicator;
use easybill\eInvoicing\CII\Models\LineTradeAgreement;
use easybill\eInvoicing\CII\Models\LineTradeDelivery;
use easybill\eInvoicing\CII\Models\LineTradeSettlement;
use easybill\eInvoicing\CII\Models\Period;
use easybill\eInvoicing\CII\Models\Quantity;
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
use Stripe\Invoice as StripeInvoice;

final class ZugferdInvoiceGenerator
{
    private const EN16931_PROFILE = 'urn:cen.eu:en16931:2017';

    private const DATE_FORMAT = 102;

    public function generate(StripeInvoice $invoice): string
    {
        $currency = $this->currency($this->str($invoice, 'currency') ?? throw new RuntimeException('Stripe invoice has no currency.'));

        $lineObjects = $this->lineObjects($invoice);
        if ($lineObjects === []) {
            throw new RuntimeException('Stripe invoice has no line items.');
        }

        $isCreditNote = $this->str($invoice, 'status') === 'void' || ($this->int($invoice, 'total') ?? 0) < 0;

        $lineItems = [];
        $lineTotalMinor = 0;
        $basisByRate = [];
        $position = 1;
        foreach ($lineObjects as $lineObject) {
            $line = $this->buildLine($lineObject, (string) $position);
            $lineItems[] = $line['item'];
            $lineTotalMinor += $line['net'];

            $key = $line['category'].'|'.$line['rate'];
            $basisByRate[$key] ??= ['category' => $line['category'], 'rate' => $line['rate'], 'basis' => 0];
            $basisByRate[$key]['basis'] += $line['net'];
            $position++;
        }

        [$taxNodes, $taxTotalMinor] = $this->buildHeaderTaxes($invoice, $basisByRate);

        $grandTotalMinor = $lineTotalMinor + $taxTotalMinor;
        $stripeTotal = abs($this->int($invoice, 'total') ?? 0);
        if ($grandTotalMinor !== $stripeTotal) {
            throw new RuntimeException(sprintf(
                'Rounding mismatch: computed grand total %d != Stripe total %d (minor units).',
                $grandTotalMinor,
                $stripeTotal,
            ));
        }

        $prepaidMinor = abs($this->int($invoice, 'amount_paid') ?? 0);
        $documentNumber = $this->str($invoice, 'number') ?? $this->str($invoice, 'id')
            ?? throw new RuntimeException('Stripe invoice has no number/id.');

        $document = new CrossIndustryInvoice;
        $document->exchangedDocumentContext = $this->context();
        $document->exchangedDocument = $this->document($invoice, $documentNumber, $isCreditNote);
        $document->supplyChainTradeTransaction = $this->transaction(
            $invoice,
            $documentNumber,
            $currency,
            $lineItems,
            $taxNodes,
            $lineTotalMinor,
            $taxTotalMinor,
            $grandTotalMinor,
            $prepaidMinor,
        );

        return Transformer::create()->transformToXml($document);
    }

    private function context(): ExchangedDocumentContext
    {
        $context = new ExchangedDocumentContext;
        $context->documentContextParameter = new DocumentContextParameter;
        $context->documentContextParameter->id = self::EN16931_PROFILE;

        return $context;
    }

    private function document(StripeInvoice $invoice, string $number, bool $isCreditNote): ExchangedDocument
    {
        $document = new ExchangedDocument;
        $document->id = $number;
        $document->typeCode = $isCreditNote ? DocumentType::CREDIT_NOTE : DocumentType::COMMERCIAL_INVOICE;
        $document->issueDateTime = $this->date(
            $this->ymd($this->int($invoice, 'status_transitions.finalized_at') ?? $this->int($invoice, 'created'))
                ?? throw new RuntimeException('Stripe invoice has no issue date.')
        );

        return $document;
    }

    /**
     * @param  SupplyChainTradeLineItem[]  $lineItems
     * @param  TradeTax[]  $taxNodes
     */
    private function transaction(
        StripeInvoice $invoice,
        string $documentNumber,
        CurrencyCode $currency,
        array $lineItems,
        array $taxNodes,
        int $lineTotalMinor,
        int $taxTotalMinor,
        int $grandTotalMinor,
        int $prepaidMinor,
    ): SupplyChainTradeTransaction {
        $tx = new SupplyChainTradeTransaction;
        $tx->lineItems = $lineItems;

        $tx->applicableHeaderTradeAgreement = new HeaderTradeAgreement;
        $tx->applicableHeaderTradeAgreement->sellerTradeParty = $this->seller();
        $tx->applicableHeaderTradeAgreement->buyerTradeParty = $this->buyer($invoice);

        $tx->applicableHeaderTradeDelivery = new HeaderTradeDelivery;
        [, $deliveryYmd] = $this->servicePeriod($invoice);
        if ($deliveryYmd !== null) {
            $tx->applicableHeaderTradeDelivery->chainEvent = $event = new SupplyChainEvent;
            $event->date = $this->date($deliveryYmd);
        }

        $tx->applicableHeaderTradeSettlement = $this->settlement(
            $invoice,
            $documentNumber,
            $currency,
            $taxNodes,
            $lineTotalMinor,
            $taxTotalMinor,
            $grandTotalMinor,
            $prepaidMinor,
        );

        return $tx;
    }

    /**
     * @param  TradeTax[]  $taxNodes
     */
    private function settlement(
        StripeInvoice $invoice,
        string $documentNumber,
        CurrencyCode $currency,
        array $taxNodes,
        int $lineTotalMinor,
        int $taxTotalMinor,
        int $grandTotalMinor,
        int $prepaidMinor,
    ): HeaderTradeSettlement {
        $settlement = new HeaderTradeSettlement;
        $settlement->paymentReference = $documentNumber;
        $settlement->invoiceCurrency = $currency;
        $settlement->specifiedTradeSettlementPaymentMeans[] = $this->paymentMeans($invoice);
        $settlement->tradeTaxes = $taxNodes;

        [$periodStart, $periodEnd] = $this->servicePeriod($invoice);
        if ($periodStart !== null && $periodEnd !== null) {
            $settlement->billingSpecifiedPeriod = $this->period($periodStart, $periodEnd);
        }

        $dueDateYmd = $this->ymd($this->int($invoice, 'due_date'));
        $mandate = $this->mandateReference($invoice);
        if ($dueDateYmd !== null || $mandate !== null) {
            $settlement->specifiedTradePaymentTerms = $terms = new TradePaymentTerms;
            if ($dueDateYmd !== null) {
                $terms->dueDate = $this->date($dueDateYmd);
            }
            if ($mandate !== null) {
                $terms->directDebitMandateID = $mandate;
            }
        }

        $settlement->specifiedTradeSettlementHeaderMonetarySummation = $this->summation(
            $currency,
            $lineTotalMinor,
            $taxTotalMinor,
            $grandTotalMinor,
            $prepaidMinor,
        );

        return $settlement;
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
     * @return array{item: SupplyChainTradeLineItem, net: int, category: string, rate: string}
     */
    private function buildLine(object $line, string $position): array
    {
        $name = $this->str($line, 'description');
        if ($name === null || $name === '') {
            throw new RuntimeException("Missing description on invoice line {$position}.");
        }

        $quantity = $this->int($line, 'quantity') ?? 1;

        $grossMinor = abs($this->int($line, 'subtotal')
            ?? $this->int($line, 'amount')
            ?? throw new RuntimeException("Missing amount on invoice line {$position}."));

        $unitMinor = abs($this->decimalMinor($this->str($line, 'pricing.unit_amount_decimal'))
            ?? $this->int($line, 'price.unit_amount')
            ?? ($quantity !== 0 ? intdiv($grossMinor, $quantity) : $grossMinor));

        [$category, $rate] = $this->resolveRate(($this->get($line, 'taxes') ?? $this->get($line, 'tax_amounts') ?? [])[0] ?? null);

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
     * @return array{0: TradeTax[], 1: int}
     */
    private function buildHeaderTaxes(StripeInvoice $invoice, array $basisByRate): array
    {
        $nodes = [];
        $taxTotalMinor = 0;

        $breakdown = $this->get($invoice, 'total_taxes') ?? $this->get($invoice, 'total_tax_amounts') ?? [];
        foreach ($breakdown as $entry) {
            [$category, $rate] = $this->resolveRate($entry);
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

    private function buyer(StripeInvoice $invoice): TradeParty
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

    private function mandateReference(StripeInvoice $invoice): ?string
    {
        if ($this->str($invoice, 'payment_intent.payment_method.type') !== 'sepa_debit') {
            return null;
        }

        return $this->str($invoice, 'payment_intent.payment_method.sepa_debit.mandate')
            ?? $this->str($invoice, 'payment_intent.mandate');
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
     * @return array{0: string, 1: string}
     */
    private function resolveRate(mixed $tax): array
    {
        if ($tax === null) {
            return ['Z', '0'];
        }

        $percent = $this->get($tax, 'tax_rate.percentage') ?? $this->get($tax, 'rate.percentage');
        if ($percent === null) {
            $base = abs($this->int($tax, 'taxable_amount') ?? 0);
            $percent = $base === 0 ? 0.0 : abs($this->int($tax, 'amount') ?? 0) / $base * 100;
        }

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
    private function servicePeriod(StripeInvoice $invoice): array
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
    private function lineObjects(StripeInvoice $invoice): array
    {
        $lines = $this->get($invoice, 'lines.data') ?? [];

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

        return $value === null ? null : (string) $value;
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
