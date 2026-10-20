<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_parses_decimals_to_minor_units_without_float_error(): void
    {
        $this->assertSame(1999, Money::toMinor('19.99', 'EUR'));
        $this->assertSame(1010, Money::toMinor('10.1', 'USD'));
        $this->assertSame(150050, Money::toMinor('1,500.50', 'GBP'));
        $this->assertSame(1500, Money::toMinor('1500', 'JPY'));
    }

    public function test_rejects_garbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::toMinor('12abc', 'EUR');
    }

    public function test_formats_back_to_decimal_string(): void
    {
        $this->assertSame('19.99', Money::toDecimalString(1999, 'EUR'));
        $this->assertSame('1500', Money::toDecimalString(1500, 'JPY'));
    }

    public function test_conversion_uses_only_explicit_rate(): void
    {
        $this->assertSame(1000, Money::convertMinor(1000, 'EUR', 'EUR', null));
        // 10.00 USD at 0.92 EUR = 9.20 EUR
        $this->assertSame(920, Money::convertMinor(1000, 'USD', 'EUR', '0.92'));
        // 1000 JPY (no decimals) at 0.0061 EUR = 6.10 EUR
        $this->assertSame(610, Money::convertMinor(1000, 'JPY', 'EUR', '0.0061'));
    }

    public function test_conversion_without_rate_throws_instead_of_assuming(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::convertMinor(1000, 'USD', 'EUR', null);
    }
}
