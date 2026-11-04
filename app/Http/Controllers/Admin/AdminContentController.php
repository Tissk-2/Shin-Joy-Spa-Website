<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PageContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $rules = [
            'values' => ['array'],
            'values.*' => ['array'],
            'values.*.id' => ['nullable', 'string', 'max:1000'],
            'values.*.en' => ['nullable', 'string', 'max:1000'],
        ];

        $validator = Validator::make($request->all(), $rules);
        $rulesFailed = $validator->fails();
        $hasUnknownKeys = $this->hasUnknownKeys($request);

        if ($hasUnknownKeys) {
            $validator->errors()->add('values', __('admin.status.unsupported_field'));
        }

        if ($rulesFailed || $hasUnknownKeys) {
            return back()->withErrors($validator)->withInput();
        }

        $values = $request->input('values', []);

        foreach (self::keys() as $key) {
            $entry = is_array($values[$key] ?? null) ? $values[$key] : [];
            $indonesian = trim((string) ($entry['id'] ?? ''));
            $english = trim((string) ($entry['en'] ?? ''));

            if ($indonesian === '' && $english === '') {
                PageContent::query()->where('key', $key)->delete();

                continue;
            }

            PageContent::query()->updateOrCreate(
                ['key' => $key],
                ['value_id' => $indonesian ?: null, 'value_en' => $english ?: null],
            );
        }

        return back(303)->with('status', __('admin.content.saved'));
    }

    public function reset(): RedirectResponse
    {
        PageContent::query()->delete();

        return back(303)->with('status', __('admin.content.reset'));
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
