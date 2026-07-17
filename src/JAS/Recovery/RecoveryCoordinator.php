<?php

declare(strict_types=1);

namespace Jah\JAS\Recovery;

use Jah\DataCore\DataCoreTransactionManager;
use Jah\JAS\Runtime\GovernedRuntime;

final class RecoveryCoordinator
{
    public function __construct(
        private readonly DataCoreTransactionManager $transactions,
        private readonly GovernedRuntime $runtime,
    ) {
    }

    public function recover(): array
    {
        $pendingBefore = $this->transactions->pendingTransactions();
        $transactions = $this->transactions->recover();
        $outbox = $this->runtime->recoverOutbox();

        return [
            'transactions_recovered' => $transactions,
            'outbox_recovered' => $outbox,
            'transaction_diagnostics' => $pendingBefore,
            'remaining_transactions' => $this->transactions->pendingCount(),
        ];
    }
}
