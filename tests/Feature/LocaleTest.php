<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('supportedLocaleProvider')]
    public function test_supported_locale_selection_persists_and_controls_validation_messages(
        string $locale,
        string $expectedMessage,
    ): void {
        $this->from('/')->postJson(route('locale.update'), [
            'locale' => $locale,
        ])->assertStatus(303)
            ->assertSessionHas('locale', $locale);

        $this->postJson(route('register.store'), [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', $expectedMessage);
    }

    /** @return array<string, array{string, string}> */
    public static function supportedLocaleProvider(): array
    {
        return [
            'Indonesian' => ['id', 'Nama wajib diisi.'],
            'English' => ['en', 'The name field is required.'],
        ];
    }

    #[DataProvider('invalidLocaleProvider')]
    public function test_invalid_locale_values_are_rejected_with_an_unprocessable_response(mixed $locale): void
    {
        $this->postJson(route('locale.update'), ['locale' => $locale])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('locale')
            ->assertSessionMissing('locale');
    }

    /** @return array<string, array{mixed}> */
    public static function invalidLocaleProvider(): array
    {
        return [
            'missing' => [null],
            'unsupported language' => ['fr'],
            'case variant' => ['EN'],
            'array payload' => [['en']],
        ];
    }

    public function test_invalid_session_locale_falls_back_to_indonesian_safely(): void
    {
        $this->withSession(['locale' => 'fr'])
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home')
                ->where('locale', 'id'));

        $this->assertSame('id', app()->getLocale());
    }

    #[DataProvider('localizedCustomValidationProvider')]
    public function test_auth_specific_validation_messages_follow_the_session_locale(
        string $locale,
        string $field,
        array $overrides,
        string $expectedMessage,
    ): void {
        $this->postJson(route('locale.update'), ['locale' => $locale])
            ->assertStatus(303);

        $this->postJson(route('register.store'), array_replace([
            'name' => 'Ayu Lestari',
            'email' => 'ayu@example.com',
            'phone' => '+6281234567890',
            'password' => 'calmspa9',
            'password_confirmation' => 'calmspa9',
        ], $overrides))
            ->assertUnprocessable()
            ->assertJsonPath("errors.{$field}.0", $expectedMessage);
    }

    /** @return array<string, array{string, string, array<string, string>, string}> */
    public static function localizedCustomValidationProvider(): array
    {
        return [
            'Indonesian phone' => [
                'id',
                'phone',
                ['phone' => '123'],
                'Nomor telepon harus berisi antara 8 dan 15 digit.',
            ],
            'English password' => [
                'en',
                'password',
                ['password' => 'onlyletters', 'password_confirmation' => 'onlyletters'],
                'The password must contain at least one letter and one number.',
            ],
        ];
    }

    public function test_php_translation_dictionaries_have_recursive_pairwise_key_parity(): void
    {
        foreach (['auth', 'passwords', 'mail', 'validation'] as $dictionary) {
            $englishPath = lang_path("en/{$dictionary}.php");
            $indonesianPath = lang_path("id/{$dictionary}.php");

            $this->assertFileExists($englishPath);
            $this->assertFileExists($indonesianPath);

            $englishKeys = $this->recursiveKeys(require $englishPath);
            $indonesianKeys = $this->recursiveKeys(require $indonesianPath);

            $this->assertSame($englishKeys, $indonesianKeys, "Translation key mismatch in {$dictionary}.php");
        }

        $this->assertSame(
            'If an account exists for that email, a password reset link has been sent.',
            trans('passwords.sent', locale: 'en'),
        );
        $this->assertSame(
            'Jika akun dengan email tersebut tersedia, tautan pengaturan ulang kata sandi telah dikirim.',
            trans('passwords.sent', locale: 'id'),
        );
    }

    /**
     * @param  array<string, mixed>  $dictionary
     * @return list<string>
     */
    private function recursiveKeys(array $dictionary, string $prefix = ''): array
    {
        $keys = [];

        foreach ($dictionary as $key => $value) {
            $path = $prefix === '' ? $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $keys = [...$keys, ...$this->recursiveKeys($value, $path)];
            } else {
                $keys[] = $path;
            }
        }

        sort($keys);

        return $keys;
    }
}
