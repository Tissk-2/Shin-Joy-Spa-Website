<?php

namespace Tests\Unit\Support;

use App\Support\CustomerIdentity;
use PHPUnit\Framework\TestCase;

class CustomerIdentityTest extends TestCase
{
    public function test_it_normalizes_customer_names(): void
    {
        $this->assertSame('Ayu Lestari', CustomerIdentity::name('  Ayu   Lestari  '));
    }

    public function test_it_normalizes_customer_emails(): void
    {
        $this->assertSame('ayu@example.com', CustomerIdentity::email(' AYU@Example.COM '));
    }

    public function test_it_normalizes_customer_phone_numbers(): void
    {
        $this->assertSame('+6281234567890', CustomerIdentity::phone(' +62 812-3456-7890 '));
    }
}
