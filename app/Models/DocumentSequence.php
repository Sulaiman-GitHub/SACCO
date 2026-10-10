<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Per-SACCO counters behind the loan, savings and repayment reference numbers.
 *
 * The counter is bumped with a single atomic UPDATE, so simultaneous requests
 * each get a different number. Call next() inside the transaction that saves
 * the record: the row lock then serialises writers for that SACCO and a
 * rolled-back save also rolls the counter back (no gaps).
 */
class DocumentSequence extends Model
{
    /**
     * @var array<string, class-string<Model>>
     */
    private const SOURCES = [
        'loan' => Loan::class,
        'saving' => Saving::class,
        'repayment' => Repayment::class,
    ];

    protected $fillable = ['sacco_id', 'name', 'last_value'];

    /**
     * Reserve and return the next number for a SACCO. Run inside a transaction.
     */
    public static function next(int $saccoId, string $name): int
    {
        $key = ['sacco_id' => $saccoId, 'name' => $name];

        return DB::transaction(function () use ($key, $saccoId, $name) {
            $bump = fn () => DB::table('document_sequences')->where($key)->increment('last_value');

            if ($bump() === 0) {
                // First use for this SACCO: start above anything already issued from the old id-based scheme.
                DB::table('document_sequences')->insertOrIgnore($key + [
                    'last_value' => static::currentMaxId($saccoId, $name),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $bump();
            }

            return (int) DB::table('document_sequences')->where($key)->lockForUpdate()->value('last_value');
        });
    }

    /**
     * The number next() would hand out, without reserving it (for form previews).
     */
    public static function peek(int $saccoId, string $name): int
    {
        $current = DB::table('document_sequences')->where(['sacco_id' => $saccoId, 'name' => $name])->value('last_value');

        return ((int) ($current ?? static::currentMaxId($saccoId, $name))) + 1;
    }

    private static function currentMaxId(int $saccoId, string $name): int
    {
        $model = self::SOURCES[$name];

        return (int) $model::withoutTenantScope()->where('sacco_id', $saccoId)->max('id');
    }
}
