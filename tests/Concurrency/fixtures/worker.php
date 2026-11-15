<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';

use App\Actions\SaveRoomBlock;
use App\Actions\Staff\SetBookingPermission;
use App\Actions\Telegram\RevokeStaffLink;
use App\Actions\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Exceptions\Outbox\OutboxRetryableException;
use App\Models\Booking;
use App\Models\OutboxMessage;
use App\Models\Room;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Support\Outbox\Handlers\TelegramBookingNotificationHandler;
use App\Support\Outbox\OutboxDispatcher;
use App\Support\Telegram\TelegramUpdateHandler;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concurrency\ConcurrencyDatabase;
use Tests\TestCase;

$test = new class('worker') extends TestCase {};
$test->createApplication();
// Laravel's console handler can end a failed worker with exit code 0 and no output, so a worker reports its own failure.
set_exception_handler(function (Throwable $exception): void {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().' at '.$exception->getFile().':'.$exception->getLine().PHP_EOL);
    exit(1);
});

$env = ConcurrencyDatabase::environment();
ConcurrencyDatabase::connect($env);
$dir = ConcurrencyDatabase::barrier($env);

$scenario = $argv[1] ?? '';
$role = $argv[2] ?? '';
$bookingId = $argv[3] ?? '';

if (! in_array($scenario, ['approve', 'block', 'complete', 'dispatch', 'no_show', 'send', 'send_fails', 'telegram', 'unlink', 'unpermit'], true) || ! in_array($role, ['first', 'second'], true) || ! ctype_digit($bookingId)) {
    throw new RuntimeException('Invalid worker arguments.');
}

// A send_fails is a send whose post fails at Telegram.
$sends = in_array($scenario, ['send', 'send_fails'], true);
// A dispatch runs one outbox dispatcher batch, and takes no booking.
$dispatch = $scenario === 'dispatch';
// Telegram, unlink and unpermit act on a staff member's authority, not on a booking row they are given.
$staffSide = in_array($scenario, ['telegram', 'unlink', 'unpermit'], true);
$booking = $staffSide || $dispatch ? null : Booking::findOrFail((int) $bookingId);
$actor = match (true) {
    $scenario === 'telegram' => null,   // a press acts as the linked staff member, whom the callback finds itself
    $sends, $dispatch => null,          // the outbox sends and dispatches as no one
    in_array($scenario, ['block', 'unlink', 'unpermit'], true) => User::where('role', 'owner')->orderBy('id')->firstOrFail(),
    default => User::where('role', 'admin')->orderBy('id')->get()[$role === 'first' ? 0 : 1],
};
$roomId = $scenario === 'block' ? Room::query()->where('code', 'small')->value('id') : null;

if ($scenario === 'block' && $roomId === null) {
    throw new RuntimeException('The fixture room is missing.');
}

CarbonImmutable::setTestNow(in_array($scenario, ['complete', 'no_show'], true) ? '2026-10-25 06:00:00 UTC' : '2026-10-24 00:00:00 UTC');
config(['services.telegram.actions_enabled' => true, 'services.telegram.staff_chat_id' => '-1001234567890']);

if ($sends) {
    // A fake Telegram, which gives a newly posted copy the message ID 456, or fails the post.
    config(['services.telegram.booking_notifications_enabled' => true, 'services.telegram.bot_token' => '123456789:'.str_repeat('A', 35)]);
    Http::preventStrayRequests();
    Http::fake([
        'api.telegram.org/*/sendMessage' => $scenario === 'send'
            ? Http::response(['ok' => true, 'result' => ['message_id' => 456]])
            : Http::response(['ok' => false, 'error_code' => 500, 'description' => 'Internal Server Error'], 500),
        'api.telegram.org/*/editMessageText' => Http::response(['ok' => true, 'result' => ['message_id' => 456]]),
    ]);
}

if ($dispatch) {
    // A queue that records the jobs the dispatcher pushes, instead of running them.
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
}

file_put_contents($dir.'/'.$role.'-ready', 'ready');
ConcurrencyDatabase::wait($dir.'/'.$role.'-go');

$acquired = false;
$guarded = false;
$rowRead = false;

if ($dispatch) {
    // Both dispatchers read the same candidates before either claims. The first pauses as it commits its first claim,
    // still holding that row (MySQL) or the write lock (SQLite), and the second tries to claim the same delivery then.
    $selected = false;
    $claiming = false;
    $claimed = false;
    DB::listen(function (QueryExecuted $query) use ($dir, $role, &$selected, &$claimed): void {
        if (! $selected && preg_match('/\Aselect .*[`"]outbox_deliveries[`"]/is', $query->sql) === 1) {
            $selected = true;
            file_put_contents("{$dir}/{$role}-selected", 'selected');
            ConcurrencyDatabase::wait($dir.'/'.($role === 'first' ? 'second' : 'first').'-selected');
        }
        // How long the first claim's statement ran: the second's includes its wait for the first's lock.
        if (! $claimed && preg_match('/\Aupdate [`"]?outbox_deliveries[`"]?/i', $query->sql) === 1) {
            $claimed = true;
            file_put_contents("{$dir}/{$role}-claim-ms", (string) $query->time);
        }
    });
    DB::connection()->beforeExecuting(function (string $query) use ($dir, $role, &$claiming): void {
        if (! $claiming && preg_match('/\Aupdate [`"]?outbox_deliveries[`"]?/i', $query) === 1) {
            $claiming = true;
            if ($role === 'second') {
                ConcurrencyDatabase::wait("{$dir}/first-acquired");
            }
            file_put_contents("{$dir}/{$role}-attempting", 'attempting');
        }
    });
    Event::listen(TransactionCommitting::class, function () use ($dir, $role, &$acquired): void {
        if (! $acquired) {
            $acquired = true;
            file_put_contents("{$dir}/{$role}-acquired", 'acquired');

            if ($role === 'first') {
                ConcurrencyDatabase::wait("{$dir}/release-first");
            }
        }
    });
}

$table = $scenario === 'block' ? 'rooms' : 'bookings';
// A Telegram update's transaction writes its telegram_updates row first. On SQLite that write is where it waits, behind
// the database-wide write lock; on MySQL that row isn't contended, so the worker waits at the staff guard, or at the
// message row when a press fills in a lost send's message ID. A send is marked attempting at its insert of the message
// row: on SQLite it waits there; on MySQL the duplicate insert returns at once and it waits at its locking read just after.
$contended = DB::connection()->getDriverName() === 'sqlite' ? 'staff_access_guards|telegram_updates' : 'staff_access_guards|telegram_booking_messages';
DB::connection()->beforeExecuting(function (string $query) use ($dir, $role, $table, $sends, $staffSide, $contended, &$acquired, &$guarded, &$rowRead): void {
    if (preg_match('/\Aupdate [`"]?('.$contended.')[`"]?/i', $query)   // mutation H1
        || ($sends && preg_match('/\Ainsert .*into [`"]?telegram_booking_messages[`"]?/i', $query))) {
        file_put_contents("{$dir}/{$role}-attempting", 'attempting');
    }
    if (preg_match('/\Aupdate [`"]?staff_access_guards[`"]?/i', $query)) {
        $guarded = true;

        return;
    }

    // Not every staff-side path reads bookings, so those pause at their first read under the staff guard.
    $hit = $staffSide   // mutation H2
        ? $guarded && preg_match('/\Aselect /i', $query) === 1
        : preg_match('/\Aselect .*[`"]'.$table.'[`"]/is', $query) === 1 && DB::transactionLevel() > 0;
    if ($sends) {
        // A send pauses at its next statement after it read the message row in its transaction (with a lock, on MySQL).
        $hit = $rowRead;
        $rowRead = $rowRead || (DB::transactionLevel() > 0 && preg_match('/\Aselect .*[`"]telegram_booking_messages[`"]/is', $query) === 1);
    }

    if (! $acquired && $hit) {
        $acquired = true;
        file_put_contents("{$dir}/{$role}-acquired", 'acquired');

        if ($role === 'first') {
            ConcurrencyDatabase::wait("{$dir}/release-first");
        }
    }
});

config(['booking.scheduling_enabled' => true]);

try {
    if ($scenario === 'approve') {
        (new TransitionBookingStatus)($actor, $booking, BookingStatus::Pending, BookingStatus::Confirmed, null, null, null, true);
        echo 'confirmed';
    } elseif ($scenario === 'block') {
        (new SaveRoomBlock)($actor, null, ['roomId' => $roomId, 'startsAt' => '2026-10-25T10:00', 'endsAt' => '2026-10-25T12:00', 'reason' => 'Race '.$role]);
        echo 'saved';
    } elseif ($scenario === 'complete') {
        (new TransitionBookingStatus)($actor, $booking, BookingStatus::Confirmed, BookingStatus::Completed, null, null, null, false);
        echo 'completed';
    } elseif ($scenario === 'telegram') {
        $message = OutboxMessage::query()->findOrFail((int) $bookingId);
        app(TelegramUpdateHandler::class)->handle($message);
        $update = TelegramUpdate::query()->findOrFail($message->payload['telegram_update_id']);
        echo $update->outcome.'/'.$update->outcome_reason;
    } elseif ($sends) {
        // The booking's first message, as the outbox runs it: send, then catch up with a decision made meanwhile.
        try {
            app(TelegramBookingNotificationHandler::class)->handle((new OutboxMessage)->forceFill([
                'type' => 'booking.created', 'payload' => ['booking_id' => $booking->id],
            ]));
            $calls = fn (string $method): int => Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/'.$method))->count();
            echo $calls('sendMessage').'/'.$calls('editMessageText');
        } catch (OutboxRetryableException $exception) {
            echo 'retryable/'.$exception->reason();   // the outbox would try again later
        }
    } elseif ($dispatch) {
        $accepted = app(OutboxDispatcher::class)->dispatchBatch();
        echo json_encode(['accepted' => $accepted, 'pushed' => $queue->pushed], JSON_THROW_ON_ERROR);
    } elseif ($scenario === 'unlink') {
        (new RevokeStaffLink)($actor, (int) $bookingId);
        echo 'unlinked';
    } elseif ($scenario === 'unpermit') {
        (new SetBookingPermission)($actor, (int) $bookingId, false);
        echo 'unpermitted';
    } else {
        (new TransitionBookingStatus)($actor, $booking, BookingStatus::Confirmed, BookingStatus::NoShow, null, null, null, false);
        echo 'no_show';
    }
} catch (ValidationException $exception) {
    $errors = $exception->errors();

    if (isset($errors['room'])) {
        echo $scenario === 'block' ? 'conflict' : 'unavailable';
    } elseif (isset($errors['currentStatus'])) {
        echo 'stale';
    } else {
        throw $exception;
    }
}
