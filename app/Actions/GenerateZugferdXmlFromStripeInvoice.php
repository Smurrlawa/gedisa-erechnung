<?php

declare(strict_types=1);

namespace App\Actions;

use App\Zugferd\ZugferdInvoiceGenerator;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\Invoice;
use Stripe\StripeClient;
use Throwable;

final class GenerateZugferdXmlFromStripeInvoice
{
    /**
     * @var list<string>
     */
    private const array STRIPE_EXPAND = [
        'customer',
    ];

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly ZugferdInvoiceGenerator $generator,
    ) {}

    public function handle(string $stripeInvoiceId): string
    {
        return $this->generator->generate($this->fetchInvoice($stripeInvoiceId));
    }

    private function fetchInvoice(string $stripeInvoiceId): Invoice
    {
        try {
            return $this->stripe->invoices->retrieve($stripeInvoiceId, ['expand' => self::STRIPE_EXPAND]);
        } catch (ApiErrorException $e) {
            throw new RuntimeException("Could not load Stripe invoice '{$stripeInvoiceId}': {$e->getMessage()}", previous: $e);
        } catch (Throwable $e) {
            throw new RuntimeException("Unexpected error loading Stripe invoice '{$stripeInvoiceId}': {$e->getMessage()}", previous: $e);
        }
    }
}
