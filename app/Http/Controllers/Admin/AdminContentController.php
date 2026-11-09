<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PageContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

final class AdminContentController extends Controller
{
    /**
     * Editable homepage copy grouped into the sections shown in the editor.
     * Every key must exist in resources/js/data/translations.ts.
     *
     * @var array<string, list<string>>
     */
    public const SECTIONS = [
        'hero' => ['hero.eyebrow', 'hero.heading', 'hero.headingAccent', 'hero.sub'],
        'about' => ['about.eyebrow', 'about.title', 'about.body'],
        'contact' => ['loc.address', 'loc.hours', 'loc.phone'],
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::SECTIONS))));
    }

    public function index(): Response
    {
        return inertia('admin/Content', [
            'sections' => self::SECTIONS,
            'values' => PageContent::query()->get()
                ->mapWithKeys(fn (PageContent $row): array => [
                    $row->key => [
                        'id' => (string) ($row->value_id ?? ''),
                        'en' => (string) ($row->value_en ?? ''),
                    ],
                ])
                ->all(),
            'revision' => $this->currentRevision(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $changes = $this->validatedChanges($request);
        $expected = (int) $request->input('revision');

        DB::transaction(function () use ($expected, $changes): void {
            $this->claimRevision($expected);

            foreach ($changes as $key => $entry) {
                $row = PageContent::query()->firstOrNew(['key' => $key]);

                foreach (['id' => 'value_id', 'en' => 'value_en'] as $locale => $column) {
                    if (! array_key_exists($locale, $entry)) {
                        continue;
                    }

                    $value = is_string($entry[$locale]) ? trim($entry[$locale]) : '';
                    $row->{$column} = $value === '' ? null : $value;
                }

                if ($row->value_id === null && $row->value_en === null) {
                    if ($row->exists) {
                        $row->delete();
                    }

                    continue;
                }

                $row->save();
            }
        });

        return back(303)->with('status', __('admin.content.saved'));
    }

    public function reset(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'revision' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($validated): void {
            $this->claimRevision((int) $validated['revision']);
            PageContent::query()->delete();
        });

        return back(303)->with('status', __('admin.content.reset'));
    }

    private function claimRevision(int $expected): void
    {
        // A missing singleton row would otherwise make every save stale forever.
        DB::table('content_revisions')->insertOrIgnore([
            'id' => 1, 'revision' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $claimed = DB::table('content_revisions')
            ->where('id', 1)
            ->where('revision', $expected)
            ->update(['revision' => $expected + 1, 'updated_at' => now()]);

        if ($claimed !== 1) {
            throw ValidationException::withMessages([
                'revision' => __('admin.content.stale'),
            ]);
        }
    }

    private function currentRevision(): int
    {
        return (int) DB::table('content_revisions')->where('id', 1)->value('revision');
    }

    /**
     * @return array<string, array<string, string|null>>
     */
    private function validatedChanges(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'revision' => ['required', 'integer', 'min:0'],
            'values' => ['required', 'array', 'min:1'],
            'values.*' => ['array'],
            'values.*.id' => ['nullable', 'string', 'max:1000'],
            'values.*.en' => ['nullable', 'string', 'max:1000'],
        ]);

        $failed = $validator->fails();

        if ($this->hasUnknownKeys($request)) {
            $validator->errors()->add('values', __('admin.status.unsupported_field'));
            $failed = true;
        }

        $values = $request->input('values');

        if (is_array($values)) {
            foreach ($values as $entry) {
                if (! is_array($entry) || (! array_key_exists('id', $entry) && ! array_key_exists('en', $entry))) {
                    $validator->errors()->add('values', __('admin.content.empty'));
                    $failed = true;

                    break;
                }
            }
        }

        if ($failed || ! is_array($values)) {
            throw new ValidationException($validator);
        }

        return $values;
    }

    private function hasUnknownKeys(Request $request): bool
    {
        $submitted = $request->input('values');

        if (! is_array($submitted)) {
            return false;
        }

        foreach (array_keys($submitted) as $key) {
            if (! is_string($key) || ! in_array($key, self::keys(), true)) {
                return true;
            }
        }

        return false;
    }
}
