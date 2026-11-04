<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';

use App\Support\Outbox\OutboxWriter;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

$test = new class('recursive-payload-worker') extends TestCase {};
$test->createApplication();
$migration = require database_path('migrations/2026_10_22_000002_create_outbox.php');
$migration->up();
$payload = [];
$payload['self'] = &$payload;

try {
    DB::transaction(fn () => app(OutboxWriter::class)->record('booking.created', 'recursive', $payload));
    exit(2);
} catch (LogicException) {
    if (DB::table('outbox_messages')->count() !== 0 || DB::table('outbox_deliveries')->count() !== 0) {
        exit(3);
    }
    echo 'rejected-before-write';
}
