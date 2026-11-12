<?php

namespace Tests\Feature;

use Tests\TestCase;

class BookingConfigurationTest extends TestCase
{
    public function test_the_scheduling_switch_reads_the_usual_true_and_false_spellings(): void
    {
        try {
            // Approval tests the switch as truthy, the customer form and bookings:readiness test === true: "1" must be
            // true and "off" false for every one of them, never a string that some count as on and others as off.
            foreach (['true' => true, '1' => true, 'yes' => true, 'on' => true, 'false' => false, '0' => false, 'no' => false, 'off' => false, '' => false] as $value => $expected) {
                $this->setSwitch((string) $value);
                $this->assertSame($expected, (require config_path('booking.php'))['scheduling_enabled'], "value '{$value}'");
            }

            $this->setSwitch(null);
            $this->assertFalse((require config_path('booking.php'))['scheduling_enabled']);
        } finally {
            $this->setSwitch('false');   // TestCase's forced value
        }
    }

    /** TestCase forces its environment into putenv, $_ENV and $_SERVER, which Env's immutable repository won't change. */
    private function setSwitch(?string $value): void
    {
        $key = 'BOOKING_SCHEDULING_ENABLED';

        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
