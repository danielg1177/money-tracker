<?php

namespace App\Console\Commands;

use App\Models\PlaidItem;
use App\Models\PlaidPendingImport;
use App\Services\PlaidTransactionSyncService;
use Illuminate\Console\Command;
use Throwable;

class PlaidDailySyncCommand extends Command
{
    protected $signature = 'plaid:daily-sync {--item= : Sync a specific PlaidItem ID only}';

    protected $description = 'Pull new transactions from Plaid for all linked items and process into pending imports or auto-create.';

    public function handle(PlaidTransactionSyncService $syncService): int
    {
        $itemOption = $this->option('item');
        if ($itemOption !== null && $itemOption !== '') {
            $item = PlaidItem::query()->find((int) $itemOption);
            if ($item === null) {
                $this->error("PlaidItem id {$itemOption} not found.");

                return self::FAILURE;
            }
            $items = collect([$item]);
        } else {
            $items = PlaidItem::query()->get();
        }

        foreach ($items as $item) {
            try {
                $result = $syncService->syncItem($item);
                $addedCount = (int) ($result['counts']['added'] ?? 0);
                $ids = $this->extractAddedTransactionIds($result['added'] ?? []);

                $pending = 0;
                $autoCreated = 0;
                if ($ids !== []) {
                    $statusByTid = PlaidPendingImport::query()
                        ->where('plaid_item_id', $item->id)
                        ->whereIn('plaid_transaction_id', $ids)
                        ->pluck('status', 'plaid_transaction_id');

                    foreach ($ids as $tid) {
                        $status = $statusByTid[$tid] ?? null;
                        if ($status === 'pending') {
                            $pending++;
                        } elseif ($status === 'auto_created') {
                            $autoCreated++;
                        }
                    }
                }

                $institution = $item->institution_name
                    ?? $item->institution_id
                    ?? ('Item #'.$item->id);

                $this->line(sprintf(
                    'Synced %s: %d added, %d auto-created, %d queued for review',
                    $institution,
                    $addedCount,
                    $autoCreated,
                    $pending
                ));
            } catch (Throwable $e) {
                report($e);
                $this->error("Failed syncing PlaidItem #{$item->id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $added
     * @return list<string>
     */
    private function extractAddedTransactionIds(array $added): array
    {
        $ids = [];
        foreach ($added as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = data_get($row, 'transaction_id');
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
