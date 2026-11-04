<?php

namespace App\Queries;

use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\ServiceVariant;
use App\Support\Rooms\RoomBlockInterval;
use App\Support\Rooms\RoomBlockTerminationSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

final class AdminRoomIndexQuery
{
    public function get(): array
    {
        $now = CarbonImmutable::now('UTC');
        $until = $now->addDays(30)->startOfSecond();
        $ready = $this->schemaReady();

        return [
            'state' => $ready ? 'ready' : 'unavailable',
            'generatedAt' => $now->toIso8601ZuluString(),
            'blocksUntil' => $until->toIso8601ZuluString(),
            'schedulingEnabled' => (bool) config('booking.scheduling_enabled', false),
            'rooms' => $ready ? $this->rooms($now, $until) : [],
        ];
    }

    private function schemaReady(): bool
    {
        if ((new RoomBlockTerminationSchema)->mode() === 'partial') {
            return false;
        }
        foreach (['rooms', 'room_service_variant', 'room_blocks', 'service_variants', 'services', 'service_categories'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return Schema::hasColumn('rooms', 'version');
    }

    private function rooms(CarbonImmutable $now, CarbonImmutable $until): array
    {
        $interval = new RoomBlockInterval;
        $candidates = RoomBlock::query()->where('room_blocks.ends_at', '>=', $now->startOfSecond()->format('Y-m-d H:i:s'))
            ->where('room_blocks.starts_at', '<=', $until->endOfSecond()->format('Y-m-d H:i:s.u'))->orderBy('starts_at')->orderBy('id')->get();
        $terminal = $interval->loadTerminations($candidates->modelKeys());
        $blocks = $candidates->filter(fn ($block) => $interval->overlaps($block, $terminal[$block->id] ?? null, $now, $until))->groupBy('room_id');

        return Room::query()
            ->select(['id', 'code', 'name', 'type', 'capacity', 'cleaning_minutes', 'is_active', 'version'])
            ->with([
                'variants' => fn ($query) => $query
                    ->select(['service_variants.id', 'service_id', 'code', 'treatment_mode', 'duration_minutes', 'is_active', 'published_at'])
                    ->orderBy('code')->orderBy('service_variants.id'),
                'variants.service:id,category_id,name_id,name_en,is_active,published_at',
                'variants.service.category:id,is_active,published_at',
            ])
            ->orderBy('code')->orderBy('id')->get()
            ->map(function (Room $room) use ($blocks, $interval, $now): array {
                $all = $blocks->get($room->id, collect());
                $room->setRelation('blocks', $all->take(10));

                return [...$this->presentRoom($room),
                    'currentBlockCount' => $all->filter(fn ($block) => $interval->instant($block, 'starts_at') <= $now)->count(),
                    'upcomingBlockCount' => $all->filter(fn ($block) => $interval->instant($block, 'starts_at') > $now)->count()];
            })->all();
    }

    private function presentRoom(Room $room): array
    {
        return [
            'id' => $room->id,
            'version' => $room->version,
            'code' => $room->code,
            'name' => $room->name,
            'type' => $room->type,
            'capacity' => $room->capacity,
            'cleaningMinutes' => $room->cleaning_minutes,
            'isActive' => $room->is_active,
            // A concurrently deleted catalog parent no longer configures a valid relationship.
            'compatibleVariants' => $room->variants
                ->filter(fn (ServiceVariant $variant) => $variant->service?->category !== null)
                ->map(fn (ServiceVariant $variant) => $this->presentVariant($variant))->values()->all(),
            'currentBlockCount' => (int) $room->current_block_count,
            'upcomingBlockCount' => (int) $room->upcoming_block_count,
            'blocks' => $room->blocks->map(fn (RoomBlock $block) => [
                'id' => $block->id,
                // Stored schedule datetimes are UTC regardless of the application display timezone.
                'startsAt' => CarbonImmutable::parse($block->getRawOriginal('starts_at'), 'UTC')->toIso8601ZuluString(),
                'endsAt' => CarbonImmutable::parse($block->getRawOriginal('ends_at'), 'UTC')->toIso8601ZuluString(),
                'reason' => $block->reason,
            ])->all(),
        ];
    }

    private function presentVariant(ServiceVariant $variant): array
    {
        return [
            'id' => $variant->id,
            'code' => $variant->code,
            'serviceNameId' => $variant->service->name_id,
            'serviceNameEn' => $variant->service->name_en,
            'treatmentMode' => $variant->treatment_mode->value,
            'durationMinutes' => $variant->duration_minutes,
            'variantStatus' => $variant->catalogStatus()->value,
            'serviceStatus' => $variant->service->catalogStatus()->value,
            'categoryStatus' => $variant->service->category->catalogStatus()->value,
        ];
    }
}
