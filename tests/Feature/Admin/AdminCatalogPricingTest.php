<?php

namespace Tests\Feature\Admin;

use App\Actions\Catalog\AttestPackagePricing;
use App\Enums\PricingBasis;
use App\Enums\UserRole;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceVariant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminCatalogPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_attestation_rejects_a_stale_owner_after_the_persisted_role_changes(): void
    {
        $this->assertStaleOwnerCannotAttest(['role' => UserRole::Admin->value]);
    }

    public function test_direct_attestation_rejects_a_stale_owner_after_email_verification_is_revoked(): void
    {
        $this->assertStaleOwnerCannotAttest(['email_verified_at' => null]);
    }

    // Production break caught: only a fresh verified owner may attest, and ordinary saves must invalidate changed pricing.
    public function test_owner_attestation_requires_fresh_owner_authority_and_catalog_changes_invalidate_it(): void
    {
        $owner = User::factory()->owner()->create();
        $admin = User::factory()->admin()->create();
        $unverifiedOwner = User::factory()->owner()->unverified()->create();
        $category = $this->category();
        $service = $this->service($category);
        $variant = $this->variant($service, 'priced-regular-60', 60);
        $url = "/admin/catalog/packages/{$service->slug}";
        $attestation = ['version' => 0, 'pricingBasis' => 'per_person', 'minGuests' => 1, 'maxGuests' => 4];

        $this->actingAs($admin)->postJson("{$url}/pricing", $attestation)->assertForbidden();
        $this->actingAs($unverifiedOwner)->post("{$url}/pricing", $attestation)->assertRedirect();
        $this->assertFalse(Gate::forUser($unverifiedOwner)->allows('attestPricing', $service));

        foreach ([$admin, $unverifiedOwner] as $actor) {
            try {
                app(AttestPackagePricing::class)($actor, $service, 0, PricingBasis::PerPerson, 1, 4);
                $this->fail('Expected direct attestation to require a verified owner.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->actingAs($owner)->postJson("{$url}/pricing", $attestation)->assertStatus(303);

        $service->refresh();
        $this->assertSame(PricingBasis::PerPerson, $service->pricing_basis);
        $this->assertSame(1, $service->min_guests);
        $this->assertSame(4, $service->max_guests);
        $this->assertNotNull($service->pricing_verified_at);
        $this->assertSame($owner->id, $service->pricing_verified_by);
        $this->assertSame(1, $service->lock_version);

        $unchangedPricing = [
            'pricingBasis' => 'per_person',
            'minGuests' => 1,
            'maxGuests' => 4,
            'variants' => [$this->choice(['id' => $variant->id, 'code' => 'priced-regular-60'])],
        ];

        $this->actingAs($owner)->patchJson($url, [
            ...$this->payload($category, ['nameId' => 'Renamed Package', ...$unchangedPricing]),
            'version' => 1,
        ])->assertStatus(303);

        $service->refresh();
        $this->assertNotNull($service->pricing_verified_at);
        $this->assertSame($owner->id, $service->pricing_verified_by);

        $this->actingAs($owner)->patchJson($url, [
            ...$this->payload($category, [
                ...$unchangedPricing,
                'variants' => [$this->choice([
                    'id' => $variant->id,
                    'code' => 'priced-regular-60',
                    'priceIdr' => 200000,
                ])],
            ]),
            'version' => 2,
        ])->assertStatus(303);

        $service->refresh();
        $this->assertNull($service->pricing_verified_at);
        $this->assertNull($service->pricing_verified_by);
        $this->assertSame(PricingBasis::PerPerson, $service->pricing_basis);

        app(AttestPackagePricing::class)($owner, $service, (int) $service->lock_version, PricingBasis::PerPerson, 1, 4);

        $this->actingAs($owner)->patchJson($url, [
            ...$this->payload($category, [
                'variants' => [$this->choice([
                    'id' => $variant->id,
                    'code' => 'priced-regular-60',
                    'priceIdr' => 200000,
                ])],
                'pricingBasis' => 'per_person',
                'minGuests' => 2,
                'maxGuests' => 4,
            ]),
            'version' => (int) $service->fresh()->lock_version,
        ])->assertStatus(303);

        $this->assertNull($service->fresh()->pricing_verified_at);
        $this->assertSame(2, $service->fresh()->min_guests);
    }

    // Production break caught: malformed attestation and partial pricing states must never reach the write.
    public function test_pricing_attestation_and_catalog_pricing_fields_reject_invalid_shapes(): void
    {
        $owner = User::factory()->owner()->create();
        $category = $this->category();
        $service = $this->service($category);
        $url = "/admin/catalog/packages/{$service->slug}";
        $valid = ['version' => 0, 'pricingBasis' => 'per_booking', 'minGuests' => 1, 'maxGuests' => 4];

        foreach ([
            [['version' => 9], 'version'],
            [['pricingVerifiedBy' => $owner->id], 'pricingVerifiedBy'],
            [['pricingBasis' => 'vip'], 'pricingBasis'],
            [['minGuests' => 0], 'minGuests'],
            [['maxGuests' => 5], 'maxGuests'],
            [['minGuests' => 4, 'maxGuests' => 2], 'maxGuests'],
            [['pricingBasis' => null], 'pricingBasis'],
            [['minGuests' => null], 'minGuests'],
        ] as [$changes, $field]) {
            $this->actingAs($owner)->postJson("{$url}/pricing", [...$valid, ...$changes])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertNull($service->fresh()->pricing_verified_at);
        $this->assertSame(0, $service->fresh()->lock_version);

        foreach ([
            [['pricingBasis' => 'per_person', 'minGuests' => null], 'minGuests'],
            [['pricingBasis' => null, 'minGuests' => 1], 'pricingBasis'],
            [['pricingBasis' => 'vip', 'minGuests' => 1], 'pricingBasis'],
            [['pricingBasis' => 'per_booking', 'minGuests' => 3, 'maxGuests' => 2], 'maxGuests'],
        ] as [$pricing, $field]) {
            $this->actingAs($owner)->patchJson($url, [
                ...$this->payload($category, $pricing),
                'version' => 0,
            ])->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->assertSame(0, $service->fresh()->lock_version);
    }

    // Production break caught: the edit screen must expose pricing state without granting attestation to ordinary admins.
    public function test_edit_exposes_pricing_state_and_owner_only_attestation_ability(): void
    {
        $owner = User::factory()->owner()->create();
        $admin = User::factory()->admin()->create();
        $category = $this->category();
        $service = $this->service($category);
        $this->variant($service, 'priced-regular-60', 60);
        app(AttestPackagePricing::class)($owner, $service, 0, PricingBasis::PerPerson, 2, 4);

        $response = $this->actingAs($owner)->get("/admin/catalog/packages/{$service->slug}/edit");
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('package.pricingBasis', 'per_person')
            ->where('package.minGuests', 2)
            ->where('package.maxGuests', 4)
            ->where('package.pricingVerified', true)
            ->where('package.pricingVerifiedBy.email', $owner->email)
            ->where('package.can.attestPricing', true));

        $this->actingAs($admin)->get("/admin/catalog/packages/{$service->slug}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('package.can.attestPricing', false));
    }

    /** @param array<string, mixed> $authorityChanges */
    private function assertStaleOwnerCannotAttest(array $authorityChanges): void
    {
        $owner = User::factory()->owner()->create();
        $service = $this->service($this->category());
        $this->variant($service, 'priced-regular-60', 60);
        app(AttestPackagePricing::class)($owner, $service, 0, PricingBasis::PerBooking, 1, 4);
        $service->refresh();
        $before = $service->getRawOriginal();

        User::query()->whereKey($owner->id)->update($authorityChanges);

        $this->assertTrue($owner->isOwner());
        $this->assertTrue($owner->hasVerifiedEmail());
        try {
            app(AttestPackagePricing::class)(
                $owner,
                $service,
                $service->lock_version,
                PricingBasis::PerPerson,
                2,
                3,
            );
            $this->fail('Expected persisted owner authority to be rechecked for direct attestation.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($before, $service->fresh()->getRawOriginal());
    }

    /** @param array<string, mixed> $overrides */
    private function category(array $overrides = []): ServiceCategory
    {
        return ServiceCategory::query()->create([
            'slug' => 'body-care',
            'name_id' => 'Perawatan Tubuh',
            'name_en' => 'Body Care',
            'image_path' => null,
            'published_at' => null,
            'lock_version' => 0,
            'is_active' => false,
            'display_order' => 1,
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function service(ServiceCategory $category, array $overrides = []): Service
    {
        return Service::query()->create([
            'category_id' => $category->id,
            'slug' => 'balinese-signature',
            'name_id' => 'Balinese Signature',
            'name_en' => 'Balinese Signature',
            'description_id' => null,
            'description_en' => null,
            'long_description_id' => null,
            'long_description_en' => null,
            'good_for_id' => null,
            'good_for_en' => null,
            'duration_minutes' => null,
            'price_idr' => null,
            'image_path' => null,
            'gallery_paths' => [],
            'popularity' => 0,
            'is_vip' => false,
            'is_active' => false,
            'published_at' => null,
            'lock_version' => 0,
            'display_order' => 1,
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function variant(Service $service, string $code, int $duration, array $overrides = []): ServiceVariant
    {
        return ServiceVariant::query()->create([
            'service_id' => $service->id,
            'code' => $code,
            'treatment_mode' => 'regular',
            'duration_minutes' => $duration,
            'price_idr' => 180000,
            'is_active' => true,
            'published_at' => null,
            'display_order' => 0,
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(ServiceCategory $category, array $overrides = []): array
    {
        return [
            'categoryId' => $category->id,
            'slug' => 'balinese-signature',
            'nameId' => 'Balinese Signature',
            'nameEn' => 'Balinese Signature',
            'descId' => null,
            'descEn' => null,
            'longId' => null,
            'longEn' => null,
            'benefitId' => null,
            'benefitEn' => null,
            'isVip' => false,
            'displayOrder' => 1,
            'pricingBasis' => null,
            'minGuests' => null,
            'maxGuests' => null,
            'removeCover' => false,
            'retainedGalleryIds' => [],
            'variants' => [],
            'extensions' => [],
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function choice(array $overrides = []): array
    {
        return [
            'code' => 'draft-regular-60',
            'mode' => 'regular',
            'durationMinutes' => 60,
            'priceIdr' => 180000,
            'isActive' => true,
            'displayOrder' => 0,
            ...$overrides,
        ];
    }
}
