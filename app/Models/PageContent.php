<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PageContent extends Model
{
    protected $fillable = [
        'key',
        'value_id',
        'value_en',
    ];

    /**
     * Overrides keyed by translation key, shaped for the shared Inertia prop.
     * Languages without an override are omitted so the client falls back to
     * the shipped copy.
     *
     * @return array<string, array<string, string>>
     */
    public static function overrides(): array
    {
        return self::query()
            ->get()
            ->mapWithKeys(function (self $row): array {
                $languages = array_filter([
                    'id' => $row->value_id,
                    'en' => $row->value_en,
                ], fn ($value): bool => $value !== null && $value !== '');

                return $languages === [] ? [] : [$row->key => $languages];
            })
            ->all();
    }
}
