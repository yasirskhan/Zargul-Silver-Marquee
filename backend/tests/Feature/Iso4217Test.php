<?php

namespace Tests\Feature;

use App\Support\Iso4217;
use Tests\TestCase;

class Iso4217Test extends TestCase
{
    public function test_supported_currencies_and_minor_units_are_explicit(): void
    {
        $this->assertTrue(Iso4217::isValid('USD'));
        $this->assertSame(2, Iso4217::minorUnits('USD'));
        $this->assertTrue(Iso4217::isValid('JPY'));
        $this->assertSame(0, Iso4217::minorUnits('JPY'));
        $this->assertTrue(Iso4217::isValid('KWD'));
        $this->assertSame(3, Iso4217::minorUnits('KWD'));
        $this->assertFalse(Iso4217::isValid('ABC'));
        $this->assertFalse(Iso4217::isValid('usd'));
    }
}
