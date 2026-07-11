<?php

namespace App\Console\Commands;

use App\Models\BulkIntakeBatchItem;
use App\Services\Intake\BulkIntakeWhatsAppConsentService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BulkIntakeWhatsAppConsentNoResponseCommand extends Command
{
    protected $signature = 'bulk-intake:whatsapp-consent-no-response
        {--hours= : Override no-response threshold in hours}
        {--dry-run : List eligible items without marking no response}';

    protected $description = 'Advance bulk intake WhatsApp consent queue when permission messages receive no reply within the configured window.';

    public function handle(BulkIntakeWhatsAppConsentService $consentService): int
    {
        $hours = $this->option('hours') !== null
            ? max(1, (int) $this->option('hours'))
            : max(1, (int) config('whatsapp.bulk_consent_no_response_hours', 72));
        $cutoff = now()->subHours($hours);
        $dryRun = (bool) $this->option('dry-run');
        $processed = 0;

        BulkIntakeBatchItem::query()
            ->where('item_meta_json->whatsapp_consent->status', BulkIntakeWhatsAppConsentService::STATUS_PERMISSION_SENT)
            ->orderBy('id')
            ->chunkById(100, function ($items) use ($consentService, $cutoff, $dryRun, &$processed): void {
                foreach ($items as $item) {
                    if (! $item instanceof BulkIntakeBatchItem) {
                        continue;
                    }

                    $sentAtRaw = data_get($item->item_meta_json, 'whatsapp_consent.sent_at');
                    if (! is_string($sentAtRaw) || trim($sentAtRaw) === '') {
                        continue;
                    }

                    try {
                        $sentAt = Carbon::parse($sentAtRaw);
                    } catch (\Throwable) {
                        continue;
                    }

                    if ($sentAt->greaterThan($cutoff)) {
                        continue;
                    }

                    if ($dryRun) {
                        $this->line('Would mark no-response: item #'.$item->id.' (sent '.$sentAt->toDateTimeString().')');
                        $processed++;

                        continue;
                    }

                    $consentService->markNoResponse($item->fresh());
                    $processed++;
                }
            });

        $this->info(($dryRun ? 'Dry run: ' : '').'Processed '.$processed.' item(s) using '.$hours.'h threshold.');

        return self::SUCCESS;
    }
}
