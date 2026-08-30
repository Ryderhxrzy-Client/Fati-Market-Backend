<?php

namespace Tests\Unit;

use App\Support\LoyaltyRules;
use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The two loyalty rules and the money type they depend on.
 *
 * These are pure calculations, so they are covered here rather than through
 * HTTP - every endpoint that touches money routes through this same code.
 */
class LoyaltyRulesTest extends TestCase
{
    // ── rewardPoints = floor(publicSellingPrice / 100) ───────────────────

    public static function rewardPointsProvider(): array
    {
        return [
            'PHP 90 earns nothing' => ['90.00', 0],
            'PHP 99.99 still earns nothing' => ['99.99', 0],
            'PHP 100 earns one' => ['100.00', 1],
            'PHP 150 earns one' => ['150.00', 1],
            'PHP 250 earns two' => ['250.00', 2],
            'PHP 1000 earns ten' => ['1000.00', 10],
            'zero earns nothing' => ['0.00', 0],
        ];
    }

    #[Test]
    #[DataProvider('rewardPointsProvider')]
    public function it_calculates_reward_points_by_flooring_hundreds(string $price, int $expected): void
    {
        $this->assertSame($expected, LoyaltyRules::rewardPointsFor(Money::fromPesos($price)));
    }

    // ── pointsDiscount = pointsUsed * PHP 5 ──────────────────────────────

    #[Test]
    public function it_values_each_redeemed_point_at_five_pesos(): void
    {
        $this->assertSame('0.00', LoyaltyRules::discountFor(0)->toDecimalString());
        $this->assertSame('10.00', LoyaltyRules::discountFor(2)->toDecimalString());
        $this->assertSame('50.00', LoyaltyRules::discountFor(10)->toDecimalString());
    }

    // ── finalAmountDue = max(itemPrice - discount, 0) ────────────────────

    #[Test]
    public function a_150_peso_item_with_2_points_costs_140(): void
    {
        $due = LoyaltyRules::amountDue(Money::fromPesos('150.00'), 2);

        $this->assertSame('140.00', $due->toDecimalString());
    }

    #[Test]
    public function a_50_peso_item_with_10_points_costs_nothing(): void
    {
        $due = LoyaltyRules::amountDue(Money::fromPesos('50.00'), 10);

        $this->assertSame('0.00', $due->toDecimalString());
    }

    #[Test]
    public function the_discount_can_never_produce_a_negative_bill(): void
    {
        // 100 points is PHP 500 of discount against a PHP 50 item.
        $due = LoyaltyRules::amountDue(Money::fromPesos('50.00'), 100);

        $this->assertFalse($due->isNegative());
        $this->assertSame('0.00', $due->toDecimalString());
    }

    #[Test]
    public function it_caps_the_points_worth_spending_at_the_bill(): void
    {
        // PHP 50 needs 10 points; spending more would simply be discarded.
        $this->assertSame(10, LoyaltyRules::maxUsefulPoints(Money::fromPesos('50.00')));
        // PHP 12 rounds up to 3 points, since 2 would leave PHP 2 owing.
        $this->assertSame(3, LoyaltyRules::maxUsefulPoints(Money::fromPesos('12.00')));
        $this->assertSame(0, LoyaltyRules::maxUsefulPoints(Money::zero()));
    }

    // ── Money is exact ───────────────────────────────────────────────────

    #[Test]
    public function money_parses_decimal_strings_without_floating_point_drift(): void
    {
        $this->assertSame(10, Money::fromPesos('0.10')->centavos());
        $this->assertSame(19999, Money::fromPesos('199.99')->centavos());
        $this->assertSame(20000, Money::fromPesos('200')->centavos());
    }

    #[Test]
    public function repeated_addition_of_ten_centavos_stays_exact(): void
    {
        // The classic float failure: 0.1 added ten times is not 1.0.
        $total = Money::zero();

        for ($i = 0; $i < 10; $i++) {
            $total = $total->plus(Money::fromPesos('0.10'));
        }

        $this->assertSame('1.00', $total->toDecimalString());
        $this->assertSame(100, $total->centavos());
    }

    #[Test]
    public function money_rejects_values_that_are_not_peso_amounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::fromPesos('250.999');
    }

    #[Test]
    public function money_formats_thousands_for_display(): void
    {
        $this->assertSame('1,250.00', Money::fromPesos('1250.00')->toFormattedString());
    }
}
