<?php

namespace App\Queries;

use App\Models\Room;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Support\Rooms\RoomOwner;
use App\Support\Rooms\RoomWriteSchema;

final class RoomManagementQuery
{
    public function access(?User $actor): array
    {
        $owner = (new RoomOwner)->current($actor) !== null;
        $ready = $owner && (new RoomWriteSchema)->ready();

        return ['isOwner' => $owner, 'ready' => $ready];
    }

    public function form(User $actor, ?int $id): array
    {
        (new RoomOwner)->authorize($actor);
        $access = $this->access($actor);
        if (! $access['ready']) {
            return ['management' => $access, 'room' => null, 'variants' => [], 'catalog' => ['packages' => []]];
        }
        $room = $id === null ? null : Room::findOrFail($id);
        $variants = ServiceVariant::with(['service.category'])->orderBy('code')->orderBy('id')->get()
            ->filter(fn ($variant) => $variant->service?->category !== null)
            ->map(fn ($variant) => [
                'id' => $variant->id, 'code' => $variant->code,
                'serviceNameId' => $variant->service->name_id, 'serviceNameEn' => $variant->service->name_en,
                'treatmentMode' => $variant->treatment_mode->value, 'durationMinutes' => $variant->duration_minutes,
                'variantStatus' => $variant->catalogStatus()->value,
                'serviceStatus' => $variant->service->catalogStatus()->value,
                'categoryStatus' => $variant->service->category->catalogStatus()->value,
            ])->values()->all();

        return ['management' => $access, 'variants' => $variants, 'room' => $room ? [
            'id' => $room->id, 'code' => $room->code, 'name' => $room->name, 'type' => $room->type,
            'capacity' => $room->capacity, 'cleaningMinutes' => $room->cleaning_minutes, 'isActive' => $room->is_active,
            'version' => $room->version, 'compatibleVariantIds' => $room->variants()->pluck('service_variants.id')->all(),
        ] : null];
    }
}
