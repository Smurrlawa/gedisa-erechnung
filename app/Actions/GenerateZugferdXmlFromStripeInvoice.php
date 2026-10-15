<?php

declare(strict_types=1);

namespace App\Actions;

use App\Zugferd\ZugferdInvoiceGenerator;
use RuntimeException;
use Stripe\CreditNote;
use Stripe\Exception\ApiErrorException;
use Stripe\Invoice;
use Stripe\StripeClient;
use Stripe\StripeObject;
use Throwable;

final class GenerateZugferdXmlFromStripeInvoice
{
    /**
     * @var list<string>
     */
    private const array INVOICE_EXPAND = [
        'customer',
    ];

    /**
     * @var list<string>
     */
    private const array CREDIT_NOTE_EXPAND = [
        'invoice',
        'invoice.customer',
    ];

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly ZugferdInvoiceGenerator $generator,
    ) {}

    public function handle(string $stripeId): string
    {
        if (str_starts_with($stripeId, 'cn_')) {
            $creditNote = $this->fetchCreditNote($stripeId);

            return $this->generator->generateCreditNote($creditNote, $this->fetchTaxRates($creditNote));
        }

        $invoice = $this->fetchInvoice($stripeId);

        return $this->generator->generate($invoice, $this->fetchTaxRates($invoice));
    }

    /**
     * @return array<string, float>
     */
    private function fetchTaxRates(StripeObject $document): array
    {
        $rates = [];
        foreach ($this->generator->taxRateIds($document) as $id) {
            try {
                $rates[$id] = (float) $this->stripe->taxRates->retrieve($id)->percentage;
            } catch (Throwable $e) {
                throw new RuntimeException("Could not load Stripe tax rate '{$id}': {$e->getMessage()}", previous: $e);
            }
        }

        return $rates;
    }

    private function fetchInvoice(string $stripeInvoiceId): Invoice
    {
        try {
            return $this->stripe->invoices->retrieve($stripeInvoiceId, ['expand' => self::INVOICE_EXPAND]);
        } catch (ApiErrorException $e) {
            throw new RuntimeException("Could not load Stripe invoice '{$stripeInvoiceId}': {$e->getMessage()}", previous: $e);
        } catch (Throwable $e) {
            throw new RuntimeException("Unexpected error loading Stripe invoice '{$stripeInvoiceId}': {$e->getMessage()}", previous: $e);
        }
    }

    private function fetchCreditNote(string $stripeCreditNoteId): CreditNote
    {
        try {
            return $this->stripe->creditNotes->retrieve($stripeCreditNoteId, ['expand' => self::CREDIT_NOTE_EXPAND]);
        } catch (ApiErrorException $e) {
            throw new RuntimeException("Could not load Stripe credit note '{$stripeCreditNoteId}': {$e->getMessage()}", previous: $e);
        } catch (Throwable $e) {
            throw new RuntimeException("Unexpected error loading Stripe credit note '{$stripeCreditNoteId}': {$e->getMessage()}", previous: $e);
        }
    }
}
