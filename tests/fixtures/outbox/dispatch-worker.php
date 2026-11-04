<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';
require __DIR__.'/IsolatedOutboxDatabase.php';

use App\Support\Outbox\OutboxDispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Outbox\IsolatedOutboxDatabase;
use Tests\TestCase;

$test = new class('worker') extends TestCase {};
$test->createApplication();

$directory = $argv[1] ?? '';
$role = $argv[2] ?? '';
$limit = isset($argv[3]) ? (int) $argv[3] : 0;

if (! in_array($role, ['first', 'second'], true) || $limit < 1) {
    fwrite(STDERR, 'Invalid worker arguments.');
    exit(1);
}

try {
    IsolatedOutboxDatabase::connect($directory);

    $queue = new class implements QueueFactory
    {
        /** @var list<array{id: int, token: string}> */
        public array $pushed = [];

        public function connection($name = null)
        {
            return $this;
        }

        public function push(object $job, mixed $data = '', ?string $queue = null): void
        {
            $this->pushed[] = ['id' => $job->deliveryId, 'token' => $job->leaseToken];
        }
    };

    app()->instance(QueueFactory::class, $queue);

    // Both workers must read the same candidates before either claims, so the
    // compare-and-set, not process scheduling, is what keeps claims disjoint.
    $selected = false;
    DB::listen(function ($query) use (&$selected, $directory, $role): void {
        if (! $selected && stripos(ltrim($query->sql), 'select') === 0 && str_contains($query->sql, 'outbox_deliveries')) {
            $selected = true;
            touch($directory.'/'.$role.'-selected');
            IsolatedOutboxDatabase::wait($directory.'/'.($role === 'first' ? 'second' : 'first').'-selected');
        }
    });

    file_put_contents($directory.'/'.$role.'-ready', 'ready');
    IsolatedOutboxDatabase::wait($directory.'/go');

    $accepted = app(OutboxDispatcher::class)->dispatchBatch($limit);

    echo json_encode(['accepted' => $accepted, 'pushed' => $queue->pushed], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(1);
}
