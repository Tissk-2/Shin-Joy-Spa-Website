<?php

namespace Tests\Feature\Auth;

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/Register'));
    }

    public function test_registration_form_submits_a_phone_bound_inertia_field(): void
    {
        $source = file_get_contents(resource_path('js/pages/auth/Register.vue'));

        $this->assertIsString($source);
        $this->assertMatchesRegularExpression(
            "/const\\s+form\\s*=\\s*useForm\\(\\{[\\s\\S]*phone:\\s*['\"]{2}/",
            $source,
        );
        $this->assertMatchesRegularExpression(
            '/<Input[\s\S]*?id="phone"[\s\S]*?type="tel"[\s\S]*?required[\s\S]*?autocomplete="tel"[\s\S]*?v-model="form\.phone"/',
            $source,
        );
        $this->assertMatchesRegularExpression(
            "/form\\.post\\(route\\(['\"]register['\"]\\)/",
            $source,
        );
    }

    public function test_customer_can_register_with_normalized_identity(): void
    {
        Notification::fake();

        $response = $this->post(route('register.store'), [
            'name' => '  Ayu   Lestari  ',
            'email' => ' AYU@Example.COM ',
            'phone' => ' +62 812-3456-7890 ',
            'password' => 'calmspa9',
            'password_confirmation' => 'calmspa9',
        ]);

        $response->assertRedirect(route('verification.notice', absolute: false));
        $response->assertDontSee('calmspa9');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'name' => 'Ayu Lestari',
            'email' => 'ayu@example.com',
            'phone' => '+6281234567890',
        ]);

        $user = User::firstOrFail();

        $this->assertTrue(Hash::check('calmspa9', $user->password));
        $this->assertNotSame('calmspa9', $user->password);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_user_creation_does_not_mutate_the_supplied_values(): void
    {
        $input = [
            'name' => '  Ayu   Lestari  ',
            'email' => ' AYU@Example.COM ',
            'phone' => ' +62 812-3456-7890 ',
            'password' => 'calmspa9',
            'password_confirmation' => 'calmspa9',
        ];
        $original = $input;

        app(CreateNewUser::class)->create($input);

        $this->assertSame($original, $input);
    }

    #[DataProvider('invalidNameProvider')]
    public function test_name_must_be_between_two_and_one_hundred_characters(string $name): void
    {
        $this->post(route('register.store'), $this->validRegistrationData([
            'name' => $name,
        ]))->assertSessionHasErrors('name');
    }

    /** @return array<string, array{string}> */
    public static function invalidNameProvider(): array
    {
        return [
            'empty' => [''],
            'one character' => ['A'],
            'one hundred and one characters' => [str_repeat('A', 101)],
        ];
    }

    public function test_name_accepts_the_one_hundred_character_boundary(): void
    {
        Notification::fake();

        $this->post(route('register.store'), $this->validRegistrationData([
            'name' => str_repeat('A', 100),
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['name' => str_repeat('A', 100)]);
    }

    public function test_email_cannot_exceed_two_hundred_and_fifty_four_characters(): void
    {
        $email = str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 62);

        $this->assertSame(255, strlen($email));

        $this->post(route('register.store'), $this->validRegistrationData([
            'email' => $email,
        ]))->assertSessionHasErrors('email');
    }

    public function test_email_is_unique_after_case_and_whitespace_normalization(): void
    {
        User::factory()->create(['email' => 'ayu@example.com']);

        $this->post(route('register.store'), $this->validRegistrationData([
            'email' => ' AYU@Example.COM ',
        ]))->assertSessionHasErrors('email');

        $this->assertDatabaseCount('users', 1);
    }

    #[DataProvider('validPhoneProvider')]
    public function test_phone_accepts_eight_to_fifteen_normalized_digits(string $phone, string $normalized): void
    {
        Notification::fake();

        $this->post(route('register.store'), $this->validRegistrationData([
            'email' => fake()->unique()->safeEmail(),
            'phone' => $phone,
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['phone' => $normalized]);
    }

    /** @return array<string, array{string, string}> */
    public static function validPhoneProvider(): array
    {
        return [
            'eight digits' => ['1234-5678', '12345678'],
            'fifteen digits with plus' => ['+123 456 789 012 345', '+123456789012345'],
        ];
    }

    #[DataProvider('invalidPhoneProvider')]
    public function test_phone_rejects_values_outside_the_normalized_digit_boundary(string $phone): void
    {
        $this->post(route('register.store'), $this->validRegistrationData([
            'phone' => $phone,
        ]))->assertSessionHasErrors('phone');
    }

    /** @return array<string, array{string}> */
    public static function invalidPhoneProvider(): array
    {
        return [
            'seven digits' => ['123-4567'],
            'sixteen digits' => ['1234 5678 9012 3456'],
            'no digits' => ['not-a-phone'],
        ];
    }

    #[DataProvider('invalidPasswordProvider')]
    public function test_password_enforces_length_letters_numbers_and_confirmation(
        string $password,
        string $confirmation,
    ): void {
        $this->post(route('register.store'), $this->validRegistrationData([
            'password' => $password,
            'password_confirmation' => $confirmation,
        ]))->assertSessionHasErrors('password');
    }

    /** @return array<string, array{string, string}> */
    public static function invalidPasswordProvider(): array
    {
        return [
            'fewer than eight characters' => ['calmsp9', 'calmsp9'],
            'more than two hundred and fifty five characters' => [str_repeat('a', 255).'1', str_repeat('a', 255).'1'],
            'letters only' => ['calmspaa', 'calmspaa'],
            'numbers only' => ['12345678', '12345678'],
            'confirmation mismatch' => ['calmspa9', 'calmspa8'],
        ];
    }

    public function test_factory_produces_a_valid_customer_phone(): void
    {
        $user = User::factory()->create();

        $this->assertMatchesRegularExpression('/^\+?\d{8,15}$/', $user->phone);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validRegistrationData(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Ayu Lestari',
            'email' => 'ayu@example.com',
            'phone' => '+6281234567890',
            'password' => 'calmspa9',
            'password_confirmation' => 'calmspa9',
        ], $overrides);
    }
}
