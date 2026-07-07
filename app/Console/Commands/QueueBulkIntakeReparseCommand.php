<?php

namespace App\Console\Commands;

use App\Jobs\ParseIntakeJob;
use App\Jobs\ProcessBulkIntakeBatchItemJob;
use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Services\Intake\IntakeExtractionReuseResolver;
use App\Services\Ocr\OcrNormalize;
use App\Services\Parsing\ParserStrategyResolver;
use Illuminate\Console\Command;

class QueueBulkIntakeReparseCommand extends Command
{
    private const ONLY_ALL = 'all';

    private const ONLY_PARSED = 'parsed';

    private const ONLY_PARSE_ERROR = 'parse_error';

    private const ONLY_NEEDS_REVIEW = 'needs_review';

    private const ONLY_MISSING_PARSED_JSON = 'missing_parsed_json';

    private const REPARSE_REASON = 'latest_parser';

    private const USABLE_PARSE_INPUT_MIN_LENGTH = 20;

    /** @var list<string> */
    private const ALLOWED_ONLY = [
        self::ONLY_ALL,
        self::ONLY_PARSED,
        self::ONLY_PARSE_ERROR,
        self::ONLY_NEEDS_REVIEW,
        self::ONLY_MISSING_PARSED_JSON,
    ];

    protected $signature = 'bulk-intake:queue-reparse
        {batchId : Bulk intake batch id}
        {--dry-run : List eligible items without database writes or job dispatch}
        {--only=all : all, parsed, parse_error, needs_review, or missing_parsed_json}';

    protected $description = 'Queue ParseIntakeJob reparse jobs for existing linked intakes in a bulk intake batch.';

    public function __construct(
        private readonly IntakeExtractionReuseResolver $reuseResolver,
        private readonly ParserStrategyResolver $parserResolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $batchId = (int) $this->argument('batchId');
        $only = strtolower(trim((string) $this->option('only')));
        $dryRun = (bool) $this->option('dry-run');

        if ($batchId < 1) {
            $this->error('Batch id must be a positive integer.');

            return self::FAILURE;
        }

        if (! in_array($only, self::ALLOWED_ONLY, true)) {
            $this->error('Invalid --only value. Allowed values: '.implode(', ', self::ALLOWED_ONLY).'.');

            return self::FAILURE;
        }

        $batch = BulkIntakeBatch::query()->find($batchId);
        if (! $batch instanceof BulkIntakeBatch) {
            $this->error("Bulk intake batch {$batchId} was not found.");

            return self::FAILURE;
        }

        $items = $batch->items()
            ->with('biodataIntake')
            ->orderBy('item_sequence')
            ->get();

        $queuedIntakeIds = [];
        $skippedReasons = [];

        foreach ($items as $item) {
            $decision = $this->queueDecision($item, $only);

            if (! $decision['queue']) {
                $this->countSkippedReason($skippedReasons, $decision['reason']);

                continue;
            }

            $intake = $item->biodataIntake;
            if (! $intake instanceof BiodataIntake) {
                $this->countSkippedReason($skippedReasons, 'missing_linked_intake');

                continue;
            }

            $queuedIntakeIds[] = (int) $intake->id;

            if (! $dryRun) {
                $this->markQueuedAndDispatch($item, $intake);
            }
        }

        $queuedCount = count($queuedIntakeIds);
        $skippedCount = array_sum($skippedReasons);

        $this->info('Bulk intake reparse queue summary');
        $this->line('Batch ID: '.$batch->id);
        $this->line('Only filter: '.$only);
        if ($dryRun) {
            $this->warn('DRY RUN: no database writes or jobs dispatched.');
        }
        $this->line('Total items: '.$items->count());
        $this->line('Queued count: '.$queuedCount);
        $this->line('Skipped count: '.$skippedCount);
        $this->line('Queued intake ids: '.($queuedIntakeIds === [] ? 'none' : implode(', ', $queuedIntakeIds)));
        $this->line('Skipped reasons: '.$this->formatSkippedReasons($skippedReasons));

        return self::SUCCESS;
    }

    /**
     * @return array{queue: bool, reason: string}
     */
    private function queueDecision(BulkIntakeBatchItem $item, string $only): array
    {
        if ($item->biodata_intake_id === null) {
            return ['queue' => false, 'reason' => 'missing_linked_intake_id'];
        }

        $intake = $item->biodataIntake;
        if (! $intake instanceof BiodataIntake) {
            return ['queue' => false, 'reason' => 'missing_linked_intake'];
        }

        if ((bool) $intake->approved_by_user || (bool) $intake->intake_locked) {
            return ['queue' => false, 'reason' => 'approved_or_locked'];
        }

        if (! $this->matchesOnlyFilter($item, $intake, $only)) {
            return ['queue' => false, 'reason' => 'filter_not_matched'];
        }

        if ($this->alreadyQueuedForLatestParser($item)) {
            return ['queue' => false, 'reason' => 'already_reparse_queued'];
        }

        if (! $this->hasUsableParseInput($intake)) {
            return ['queue' => false, 'reason' => 'no_usable_parse_input'];
        }

        return ['queue' => true, 'reason' => ''];
    }

    private function matchesOnlyFilter(BulkIntakeBatchItem $item, BiodataIntake $intake, string $only): bool
    {
        return match ($only) {
            self::ONLY_ALL => true,
            self::ONLY_PARSED => (string) $intake->parse_status === 'parsed',
            self::ONLY_PARSE_ERROR => (string) $intake->parse_status === 'error',
            self::ONLY_NEEDS_REVIEW => (string) $item->item_status === BulkIntakeBatchItem::STATUS_NEEDS_REVIEW,
            self::ONLY_MISSING_PARSED_JSON => (string) $intake->parse_status === 'parsed' && ! $this->hasParsedJson($intake),
            default => false,
        };
    }

    private function alreadyQueuedForLatestParser(BulkIntakeBatchItem $item): bool
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];

        return (string) $item->item_status === BulkIntakeBatchItem::STATUS_PARSE_QUEUED
            && (string) ($meta['reparse_reason'] ?? '') === self::REPARSE_REASON
            && trim((string) ($meta['reparse_queued_at'] ?? '')) !== '';
    }

    private function hasParsedJson(BiodataIntake $intake): bool
    {
        return is_array($intake->parsed_json) && $intake->parsed_json !== [];
    }

    private function hasUsableParseInput(BiodataIntake $intake): bool
    {
        $cachedText = $this->reuseResolver->getCachedParseInputText((int) $intake->id);

        foreach ([
            (string) ($intake->last_parse_input_text ?? ''),
            (string) ($cachedText ?? ''),
            (string) ($intake->raw_ocr_text ?? ''),
        ] as $text) {
            if (mb_strlen($this->normalizedUsabilityText($text), 'UTF-8') >= self::USABLE_PARSE_INPUT_MIN_LENGTH) {
                return true;
            }
        }

        return false;
    }

    private function normalizedUsabilityText(string $text): string
    {
        $normalized = trim(OcrNormalize::normalizeRawTextForParsing($text));
        $normalized = preg_replace('/\s+/u', ' ', $normalized);

        return is_string($normalized) ? trim($normalized) : '';
    }

    private function markQueuedAndDispatch(BulkIntakeBatchItem $item, BiodataIntake $intake): void
    {
        $queuedAt = now()->toIso8601String();
        $parserVersion = $this->parserVersionForJob($intake);
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];

        $intake->forceFill([
            'parse_status' => 'pending',
            'last_error' => null,
        ])->save();

        IntakeExtractionReuseResolver::flagNextParseJobAsParseInputOnly((int) $intake->id);
        ParseIntakeJob::dispatch((int) $intake->id, true)
            ->onQueue(ProcessBulkIntakeBatchItemJob::QUEUE_NAME);

        $item->forceFill([
            'item_status' => BulkIntakeBatchItem::STATUS_PARSE_QUEUED,
            'item_meta_json' => array_merge($meta, [
                'reparse_queued_at' => $queuedAt,
                'reparse_reason' => self::REPARSE_REASON,
                'reparse_parser_version' => $parserVersion,
                'parse_queue_mode' => 'bulk_intake_reparse_latest_parser',
                'parse_input_only' => true,
            ]),
            'failure_code' => null,
            'failure_message' => null,
        ])->save();
    }

    private function parserVersionForJob(BiodataIntake $intake): string
    {
        return $this->parserResolver->normalizeMode(
            $intake->parser_version ?: $this->parserResolver->resolveActiveMode()
        );
    }

    /**
     * @param  array<string, int>  $skippedReasons
     */
    private function countSkippedReason(array &$skippedReasons, string $reason): void
    {
        $reason = $reason !== '' ? $reason : 'unknown';
        $skippedReasons[$reason] = ($skippedReasons[$reason] ?? 0) + 1;
    }

    /**
     * @param  array<string, int>  $skippedReasons
     */
    private function formatSkippedReasons(array $skippedReasons): string
    {
        if ($skippedReasons === []) {
            return 'none';
        }

        ksort($skippedReasons);

        return collect($skippedReasons)
            ->map(fn (int $count, string $reason): string => $reason.'='.$count)
            ->values()
            ->implode(', ');
    }
}
