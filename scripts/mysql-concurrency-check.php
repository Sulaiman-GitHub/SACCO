<?php

/**
 * Manual MySQL concurrency check for savings withdrawals. NOT part of the test suite.
 *
 * The automated tests run on SQLite, which ignores row locks, so they cannot prove that two people
 * clicking at the same moment are handled. This script starts real parallel PHP processes against a
 * real MySQL database and counts the ledger rows:
 *   A. two payouts of the same approved request      -> exactly one ledger row
 *   B. two withdrawals racing for one balance        -> exactly one ledger row, balance never negative
 *
 * Usage (see SYSTEM_ARCHITECTURE.md, "Concurrency check on MySQL"):
 *   CONCURRENCY_DB=sacco_concurrency_test php scripts/mysql-concurrency-check.php [--rounds=5]
 *
 * Safety: it refuses to run unless CONCURRENCY_DB names an empty scratch database whose name contains
 * "test", "scratch" or "concurrency", that is different from DB_DATABASE, on a non-production
 * environment, and holding no SACCO other than the ones this script creates (license CONC-...).
 * It creates the schema (migrate) and its own sample data in that database and nothing else.
 */

use App\Models\Member;
use App\Models\Sacco;
use App\Models\Saving;
use App\Models\User;
use App\Models\WithdrawalPolicy;
use App\Models\WithdrawalRequest;
use App\Services\WithdrawalRequestService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function fail(string $message): never
{
    fwrite(STDERR, "REFUSED: {$message}\n");
    exit(2);
}

/**
 * Point the app at the scratch database, or stop.
 */
function connectScratchDatabase(): string
{
    $name = (string) getenv('CONCURRENCY_DB');

    if ($name === '') {
        fail('Set CONCURRENCY_DB to the name of an empty scratch MySQL database.');
    }
    if (! preg_match('/^[A-Za-z0-9_]+$/', $name) || ! preg_match('/(test|scratch|concurrency)/i', $name)) {
        fail('The database name must be letters, digits or underscores and contain "test", "scratch" or "concurrency".');
    }
    if ($name === (string) env('DB_DATABASE')) {
        fail('CONCURRENCY_DB must be different from DB_DATABASE. Create a separate scratch database.');
    }
    if (app()->environment('production')) {
        fail('APP_ENV is production.');
    }
    if (! extension_loaded('pdo_mysql')) {
        fail('The pdo_mysql PHP extension is not installed.');
    }

    config(['database.default' => 'mysql', 'database.connections.mysql.database' => $name]);
    DB::purge('mysql');

    try {
        DB::connection('mysql')->select('select 1');
    } catch (Throwable $e) {
        fail("Cannot connect to database '{$name}': ".$e->getMessage().' (create it first, then check DB_HOST/DB_USERNAME/DB_PASSWORD).');
    }

    return $name;
}

/**
 * Refuse if the scratch database already holds anything that is not this script's own data.
 */
function assertScratchIsOurs(): void
{
    if (! Schema::hasTable('saccos')) {
        return;
    }

    $foreign = DB::table('saccos')->where(fn ($q) => $q->whereNull('license_number')->orWhere('license_number', 'not like', 'CONC-%'))->count();
    if ($foreign > 0) {
        fail('The database already contains SACCOs that this script did not create. Use an empty scratch database.');
    }
}

// ---------------------------------------------------------------- worker mode

$options = getopt('', ['worker:', 'start-at:', 'request:', 'staff:', 'member:', 'amount:', 'rounds:']);

if (isset($options['worker'])) {
    connectScratchDatabase();
    DB::select('select 1'); // connect before the starting gun, so only the work itself is racing

    $startAt = (float) $options['start-at'];
    while (microtime(true) < $startAt) {
        usleep(200);
    }

    try {
        if ($options['worker'] === 'payout') {
            $staff = User::withoutTenantScope()->findOrFail((int) $options['staff']);
            app(WithdrawalRequestService::class)->recordPayout($staff, (int) $options['request'], 'cash', null);
        } else {
            $member = Member::withoutTenantScope()->findOrFail((int) $options['member']);
            Saving::recordLocked([
                'member_id' => $member->id, 'type' => 'withdrawal', 'amount' => (float) $options['amount'],
                'payment_method' => 'cash', 'transaction_date' => date('Y-m-d'),
            ], (int) $member->sacco_id, (int) $options['staff']);
        }
        $result = ['outcome' => 'ok'];
    } catch (ValidationException $e) {
        $result = ['outcome' => 'refused', 'message' => collect($e->errors())->flatten()->first()];
    } catch (Throwable $e) {
        $result = ['outcome' => 'error', 'message' => get_class($e).': '.substr($e->getMessage(), 0, 160)];
    }

    echo 'RESULT:'.json_encode($result)."\n";
    exit(0);
}

// ---------------------------------------------------------------- parent mode

$database = connectScratchDatabase();
assertScratchIsOurs();

echo "Using scratch database '{$database}'. Creating the schema...\n";
Artisan::call('migrate', ['--force' => true]);
assertScratchIsOurs();

$rounds = max(1, (int) ($options['rounds'] ?? 5));

/**
 * @param  list<array<string, string|int|float>>  $workers
 * @return list<array{outcome: string, message?: string}>
 */
function runParallel(array $workers): array
{
    $startAt = microtime(true) + 8.0; // time for every process to boot before the starting gun
    $processes = [];

    foreach ($workers as $args) {
        $command = [PHP_BINARY, __FILE__, '--start-at='.$startAt];
        foreach ($args as $key => $value) {
            $command[] = "--{$key}={$value}";
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
        $processes[] = [$process, $pipes];
    }

    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        proc_close($process);
        $results[] = preg_match('/^RESULT:(.*)$/m', $out, $m) ? json_decode($m[1], true) : ['outcome' => 'error', 'message' => 'worker printed no result: '.trim($out)];
    }

    return $results;
}

function makeFixture(float $balance): array
{
    $sacco = Sacco::factory()->create(['license_number' => 'CONC-'.bin2hex(random_bytes(4)), 'status' => 'active']);
    $admin = User::factory()->create(['sacco_id' => $sacco->id, 'role' => 'sacco_admin', 'status' => 'active']);
    $member = Member::factory()->consented($admin)->create(['sacco_id' => $sacco->id, 'status' => 'active', 'phone' => '0772123456']);
    Saving::factory()->create(['sacco_id' => $sacco->id, 'member_id' => $member->id, 'type' => 'deposit', 'amount' => $balance, 'transaction_date' => date('Y-m-d')]);
    WithdrawalPolicy::withoutTenantScope()->create(['sacco_id' => $sacco->id, 'notice_days' => 0, 'daily_limit_per_member' => null, 'payer_must_differ_from_approver' => 'never']);

    return [$sacco, $admin, $member];
}

function summarise(array $results): string
{
    return collect($results)->map(fn ($r) => $r['outcome'].(isset($r['message']) ? " ({$r['message']})" : ''))->implode('; ');
}

$failures = 0;

for ($round = 1; $round <= $rounds; $round++) {
    // A. Two payouts of the same approved request
    [$sacco, $admin, $member] = makeFixture(1000000);
    $request = new WithdrawalRequest([
        'sacco_id' => $sacco->id, 'member_id' => $member->id, 'reference' => WithdrawalRequest::generateReference($sacco->id), 'amount' => 100000,
        'net_amount' => 100000, 'destination_phone' => '+256772123456', 'status' => 'approved', 'available_from' => date('Y-m-d'),
    ]);
    $request->save();
    $request->forceFill(['decided_by' => $admin->id, 'decided_at' => now()])->save();

    $results = runParallel([
        ['worker' => 'payout', 'request' => $request->id, 'staff' => $admin->id],
        ['worker' => 'payout', 'request' => $request->id, 'staff' => $admin->id],
    ]);

    $rows = Saving::withoutTenantScope()->where('member_id', $member->id)->where('type', 'withdrawal')->count();
    $request->refresh();
    $okCount = collect($results)->where('outcome', 'ok')->count();
    $pass = $rows === 1 && $okCount === 1 && $request->status === 'paid' && $request->saving_id !== null;
    $failures += $pass ? 0 : 1;
    printf("Round %d A (two payouts, one request): %s - ledger rows %d (expected 1) - %s\n", $round, $pass ? 'PASS' : 'FAIL', $rows, summarise($results));

    // B. Two withdrawals racing for one balance
    [$sacco, $admin, $member] = makeFixture(100000);
    $results = runParallel([
        ['worker' => 'withdraw', 'member' => $member->id, 'staff' => $admin->id, 'amount' => 80000],
        ['worker' => 'withdraw', 'member' => $member->id, 'staff' => $admin->id, 'amount' => 80000],
    ]);

    $rows = Saving::withoutTenantScope()->where('member_id', $member->id)->where('type', 'withdrawal')->count();
    $balance = Member::withoutTenantScope()->findOrFail($member->id)->savings_balance;
    $pass = $rows === 1 && $balance >= 0 && collect($results)->where('outcome', 'ok')->count() === 1;
    $failures += $pass ? 0 : 1;
    printf("Round %d B (two withdrawals, one balance): %s - ledger rows %d (expected 1), balance %.2f - %s\n", $round, $pass ? 'PASS' : 'FAIL', $rows, $balance, summarise($results));
}

echo $failures === 0
    ? "\nAll {$rounds} rounds passed: exactly one ledger row each time.\n"
    : "\n{$failures} check(s) FAILED. Do not rely on the locks until this is understood.\n";
echo "Note: a result of 'error (... Deadlock ...)' for one of the two racers is safe for the ledger (that attempt is rolled back) but should be reported.\n";

exit($failures === 0 ? 0 : 1);
