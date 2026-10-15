<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GenerateZugferdXmlFromStripeInvoice;
use Illuminate\Console\Command;
use Throwable;

final class GenerateZugferdXmlCommand extends Command
{
    protected $signature = 'zugferd:generate {invoice : Stripe Invoice ID (in_...) oder Credit Note ID (cn_...)} {--out= : Optionaler Dateipfad für das XML}';

    protected $description = 'TEMP: Erzeugt ZUGFeRD-XML aus einer Stripe Invoice ID';

    public function handle(GenerateZugferdXmlFromStripeInvoice $action): int
    {
        try {
            $xml = $action->handle($this->argument('invoice'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $out = $this->option('out');
        if (is_string($out) && $out !== '') {
            file_put_contents($out, $xml);
            $this->info("XML written to: {$out}");

            return self::SUCCESS;
        }

        $this->line($xml);

        return self::SUCCESS;
    }
}
