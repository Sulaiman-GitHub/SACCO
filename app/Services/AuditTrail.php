<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Repayment;
use App\Models\Saving;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Writes and verifies the append-only, hash-chained audit trail.
 *
 * Each SACCO has its own chain: every entry's hash covers its own fields plus the previous
 * entry's hash, so editing or removing any entry breaks every hash after it.
 */
class AuditTrail
{
    /**
     * Record an action against an entity. The acting user is taken from the session, if any.
     *
     * @param  array<string, mixed>  $details
     */
    public function record(string $action, Model $entity, array $details = []): AuditLog
    {
        $saccoId = (int) $entity->getAttribute('sacco_id');
        $user = Auth::user();

        return DB::transaction(function () use ($action, $entity, $details, $saccoId, $user) {
            $previous = AuditLog::withoutTenantScope()
                ->where('sacco_id', $saccoId)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $fields = [
                'sacco_id' => $saccoId,
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'action' => $action,
                'entity_type' => class_basename($entity),
                'entity_id' => (int) $entity->getKey(),
                'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_at' => Carbon::now()->format('Y-m-d H:i:s'),
            ];

            $prevHash = $previous?->hash ?? AuditLog::GENESIS_HASH;

            return AuditLog::create($fields + [
                'prev_hash' => $prevHash,
                'hash' => AuditLog::computeHash($fields, $prevHash),
            ]);
        });
    }

    /**
     * Verify a SACCO's audit chain, and that recorded savings and repayments still match the ledger.
     *
     * Detects edited or removed entries, and edited or deleted ledger rows. It cannot detect entries
     * truncated from the very end of the chain.
     *
     * @return array{ok: bool, checked: int, problems: list<string>}
     */
    public function verify(int $saccoId): array
    {
        $problems = [];
        $checked = 0;
        $expectedPrev = AuditLog::GENESIS_HASH;

        AuditLog::withoutTenantScope()
            ->where('sacco_id', $saccoId)
            ->orderBy('id')
            ->each(function (AuditLog $log) use (&$problems, &$checked, &$expectedPrev) {
                $checked++;
                $raw = $log->getRawOriginal();

                if ($raw['prev_hash'] !== $expectedPrev) {
                    $problems[] = "Entry #{$log->id}: chain is broken (an earlier entry was removed or altered).";
                }

                $recomputed = AuditLog::computeHash([
                    'sacco_id' => $raw['sacco_id'],
                    'user_id' => $raw['user_id'],
                    'user_name' => $raw['user_name'],
                    'action' => $raw['action'],
                    'entity_type' => $raw['entity_type'],
                    'entity_id' => $raw['entity_id'],
                    'details' => $raw['details'],
                    'created_at' => $raw['created_at'],
                ], $raw['prev_hash']);

                if ($recomputed !== $raw['hash']) {
                    $problems[] = "Entry #{$log->id} ({$raw['action']}): contents do not match its hash.";
                }

                $expectedPrev = $raw['hash'];

                $ledgerProblem = $this->checkLedgerEntry($log);
                if ($ledgerProblem !== null) {
                    $problems[] = $ledgerProblem;
                }
            });

        return ['ok' => $problems === [], 'checked' => $checked, 'problems' => $problems];
    }

    /**
     * Compare a recorded ledger entry with the live ledger row.
     */
    private function checkLedgerEntry(AuditLog $log): ?string
    {
        $model = match ($log->entity_type) {
            'Saving' => Saving::class,
            'Repayment' => Repayment::class,
            default => null,
        };

        if ($model === null || ! in_array($log->action, ['savings.recorded', 'repayment.recorded'], true)) {
            return null;
        }

        $row = $model::withoutTenantScope()->find($log->entity_id);

        if ($row === null) {
            return "Entry #{$log->id}: {$log->entity_type} #{$log->entity_id} was recorded but no longer exists in the ledger.";
        }

        $recorded = $log->detailsArray();

        if (round((float) $row->amount, 2) !== round((float) ($recorded['amount'] ?? 0), 2)) {
            return "Entry #{$log->id}: {$log->entity_type} #{$log->entity_id} amount in the ledger differs from the amount recorded at the time.";
        }

        return null;
    }
}
