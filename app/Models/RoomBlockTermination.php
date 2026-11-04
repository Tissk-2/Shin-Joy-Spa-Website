<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class RoomBlockTermination extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'original_starts_at' => 'immutable_datetime', 'original_ends_at' => 'immutable_datetime'];
    }

    public function fromDateTime($value)
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format($this->getDateFormat());
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Room block terminal history is immutable.'));
        self::deleting(fn () => throw new LogicException('Room block terminal history is immutable.'));
    }
}
