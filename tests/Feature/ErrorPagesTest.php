<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// Flow review F4: errors showed the framework's bare English page with no way back.
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unknown_address_shows_the_sites_page_with_links_back(): void
    {
        $this->get('/layanan/pijat-lama')
            ->assertNotFound()
            ->assertSee('Halaman tidak ditemukan')
            ->assertSee('Page not found')
            ->assertSee('href="/"', false)
            ->assertSee('href="/services"', false)
            ->assertSee('href="/bookings"', false);
    }

    public function test_a_customer_at_the_staff_area_is_told_the_page_is_not_for_their_account(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertForbidden()
            ->assertSee('Halaman ini tidak tersedia untuk akun Anda');
    }

    public function test_a_server_error_shows_the_sites_page_without_the_error_details(): void
    {
        Route::middleware('web')->get('/__flow-review-boom', fn () => throw new RuntimeException('secret detail'));

        $this->get('/__flow-review-boom')
            ->assertStatus(500)
            ->assertSee('Terjadi kesalahan di sisi kami')
            ->assertDontSee('secret detail');
    }

    public function test_maintenance_and_expired_sessions_have_their_own_wording(): void
    {
        $this->view('errors.503', ['exception' => new HttpException(503)])
            ->assertSee('Sedang dalam perbaikan')
            ->assertSee('Down for maintenance');
        $this->view('errors.419', ['exception' => new HttpException(419)])
            ->assertSee('Sesi Anda berakhir');
        $this->view('errors.4xx', ['exception' => new HttpException(405)])
            ->assertSee('Permintaan tidak dapat diproses');
    }
}
