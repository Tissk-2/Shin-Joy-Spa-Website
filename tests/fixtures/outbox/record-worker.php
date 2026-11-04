<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';
require __DIR__.'/FakeOutboxHandler.php';
require __DIR__.'/IsolatedOutboxDatabase.php';

use App\Support\Outbox\OutboxWriter;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Outbox\FakeOutboxHandler;
use Tests\Fixtures\Outbox\IsolatedOutboxDatabase;
use Tests\TestCase;

$test = new class('worker') extends TestCase {};
$test->createApplication();
$directory = $argv[1] ?? '';
$role = $argv[2] ?? '';
if (! in_array($role, ['first', 'second'], true)) {
    throw new RuntimeException('Invalid worker arguments.');
}
IsolatedOutboxDatabase::connect($directory);
config(['outbox.events' => ['booking.created' => ['primary' => FakeOutboxHandler::class]]]);
DB::beginTransaction();
file_put_contents($directory.'/'.$role.'-ready', 'ready');
IsolatedOutboxDatabase::wait($directory.'/'.$role.'-go');
if ($role === 'second') {
    DB::connection()->beforeExecuting(function (string $query) use ($directory): void {
        if (str_starts_with(strtolower($query), 'insert or ignore') && str_contains($query, 'outbox_messages')) {
            file_put_contents($directory.'/second-attempting', 'attempting');
        }
    });
}
try {
    $message = app(OutboxWriter::class)->record('booking.created', 'contention-key', [
        'booking_id' => 42,
        'note' => 'same payload',
    ]);
    file_put_contents($directory.'/'.$role.'-recorded', (string) $message->id);
    if ($role === 'first') {
        IsolatedOutboxDatabase::wait($directory.'/release-first');
    }
    DB::commit();
    echo $message->id;
} catch (Throwable $exception) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(1);
}
