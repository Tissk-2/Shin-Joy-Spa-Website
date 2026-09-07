<?php

namespace Tests\Feature\Auth;

use App\Actions\Fortify\CreateNewUser;
use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\VerifyEmail;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_cannot_assign_an_admin_role(): void
    {
        Notification::fake();

        $this->post(route('register.store'), $this->validRegistrationData([
            'role' => 'admin',
        ]))->assertRedirect('/verify-email');

        $user = User::where('email', 'ayu@example.com')->firstOrFail();
        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertFalse($user->isAdmin());
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/Register'));
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

    public function test_registration_limiter_blocks_the_fourth_invalid_attempt_for_the_same_normalized_email_and_ip(): void
    {
        $request = fn (string $email) => $this
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->post(route('register.store'), $this->validRegistrationData([
                'email' => $email,
                'phone' => '123-4567',
            ]));

        $request(' AYU@EXAMPLE.COM ')->assertSessionHasErrors('phone');
        $request('ayu@example.com')->assertSessionHasErrors('phone');
        $request('Ayu@Example.com')->assertSessionHasErrors('phone');
        $request(' ayu@example.com ')->assertStatus(429);
    }

    public function test_registration_limiter_blocks_the_twenty_first_invalid_attempt_from_an_ip_without_affecting_another_ip(): void
    {
        foreach (range(1, 20) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
                ->post(route('register.store'), $this->validRegistrationData([
                    'email' => "guest{$attempt}@example.com",
                    'phone' => '123-4567',
                ]))->assertSessionHasErrors('phone');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post(route('register.store'), $this->validRegistrationData([
                'email' => 'guest21@example.com',
                'phone' => '123-4567',
            ]))->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.21'])
            ->post(route('register.store'), $this->validRegistrationData([
                'email' => 'guest21@example.com',
                'phone' => '123-4567',
            ]))->assertSessionHasErrors('phone');
    }

    public function test_different_emails_can_register_with_the_same_normalized_phone_number(): void
    {
        $creator = app(CreateNewUser::class);

        $creator->create($this->validRegistrationData([
            'email' => 'ayu@example.com',
            'phone' => ' +62 812-3456-7890 ',
        ]));
        $creator->create($this->validRegistrationData([
            'email' => 'bima@example.com',
            'phone' => '+6281234567890',
        ]));

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('users', ['email' => 'ayu@example.com', 'phone' => '+6281234567890']);
        $this->assertDatabaseHas('users', ['email' => 'bima@example.com', 'phone' => '+6281234567890']);
    }

    #[DataProvider('emailUniqueCollisionMessageProvider')]
    public function test_email_collision_during_registration_returns_a_normal_email_validation_response(
        string $message,
    ): void {
        $shouldThrow = true;

        app('db')->connection()->beforeExecuting(function (string $query) use (&$shouldThrow, $message): void {
            if ($shouldThrow && str_starts_with(strtolower($query), 'insert into "users"')) {
                $shouldThrow = false;

                throw new UniqueConstraintViolationException(
                    'sqlite',
                    'insert into "users"',
                    [],
                    new PDOException($message, 23000),
                );
            }
        });

        $response = $this->post(route('register.store'), $this->validRegistrationData());

        $response
            ->assertStatus(302)
            ->assertSessionHasErrors([
                'email' => trans('validation.unique', ['attribute' => trans('validation.attributes.email')]),
            ])
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('users.email');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    /** @return array<string, array{string}> */
    public static function emailUniqueCollisionMessageProvider(): array
    {
        return [
            'SQLite users.email constraint' => ['SQLSTATE[23000]: UNIQUE constraint failed: users.email'],
            'MySQL or MariaDB users_email_unique constraint' => [
                "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'ayu@example.com' for key 'users_email_unique'",
            ],
        ];
    }

    public function test_non_email_unique_constraint_violation_is_rethrown(): void
    {
        $shouldThrow = true;
        $collision = new UniqueConstraintViolationException(
            'sqlite',
            'insert into "users"',
            [],
            new PDOException('SQLSTATE[23000]: UNIQUE constraint failed: users.id', 23000),
        );

        app('db')->connection()->beforeExecuting(function (string $query) use (&$shouldThrow, $collision): void {
            if ($shouldThrow && str_starts_with(strtolower($query), 'insert into "users"')) {
                $shouldThrow = false;

                throw $collision;
            }
        });

        try {
            app(CreateNewUser::class)->create($this->validRegistrationData());
            $this->fail('Expected a non-email unique constraint violation to be rethrown.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertSame($collision, $exception);
        }

        $this->assertDatabaseCount('users', 0);
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
