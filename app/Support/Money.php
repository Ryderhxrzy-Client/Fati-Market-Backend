<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;

/**
 * An exact peso amount, held as an integer number of centavos.
 *
 * Every price, discount and total in the marketplace goes through this class.
 * Nothing here uses floating point arithmetic: floats cannot represent 0.10
 * exactly, and repeated addition of inexact values is how money quietly goes
 * missing. Values arriving from the database as DECIMAL strings ("250.00") are
 * parsed textually rather than cast to float.
 */
final class Money implements JsonSerializable
{
    private function __construct(private readonly int $centavos)
    {
    }

    public static function fromCentavos(int $centavos): self
    {
        return new self($centavos);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parse a peso amount. Accepts the DECIMAL strings Eloquent returns, plain
     * integers, and the numeric strings that arrive from HTTP requests.
     *
     * Floats are accepted but rounded immediately at the centavo boundary, so
     * an inexact value can never propagate further than this method.
     */
    public static function fromPesos(string|int|float|null $value): self
    {
        if ($value === null || $value === '') {
            return self::zero();
        }

        if (is_int($value)) {
            return new self($value * 100);
        }

        if (is_float($value)) {
            return new self((int) round($value * 100));
        }

        $trimmed = trim($value);

        if (!preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', $trimmed, $matches)) {
            // Anything with more than two decimals, or non-numeric, is not a
            // peso amount we are willing to guess at.
            throw new InvalidArgumentException("Invalid peso amount: {$value}");
        }

        $sign = $matches[1] === '-' ? -1 : 1;
        $whole = (int) $matches[2];
        $fraction = str_pad($matches[3] ?? '0', 2, '0', STR_PAD_RIGHT);

        return new self($sign * ($whole * 100 + (int) $fraction));
    }

    /** True when the string is a well-formed peso amount. */
    public static function isValidPesoString(string $value): bool
    {
        return (bool) preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', trim($value));
    }

    public function centavos(): int
    {
        return $this->centavos;
    }

    /** The canonical "250.00" form, safe to store in a DECIMAL(10,2) column. */
    public function toDecimalString(): string
    {
        $sign = $this->centavos < 0 ? '-' : '';
        $abs = abs($this->centavos);

        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Display form, e.g. "PHP 1,250.00" rendered as "1,250.00". */
    public function toFormattedString(): string
    {
        $sign = $this->centavos < 0 ? '-' : '';
        $abs = abs($this->centavos);

        return $sign . number_format(intdiv($abs, 100)) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    public function plus(self $other): self
    {
        return new self($this->centavos + $other->centavos);
    }

    public function minus(self $other): self
    {
        return new self($this->centavos - $other->centavos);
    }

    public function times(int $multiplier): self
    {
        return new self($this->centavos * $multiplier);
    }

    /** Clamps at zero - a discount must never produce a negative bill. */
    public function clampAtZero(): self
    {
        return $this->centavos < 0 ? self::zero() : $this;
    }

    public function isZero(): bool
    {
        return $this->centavos === 0;
    }

    public function isNegative(): bool
    {
        return $this->centavos < 0;
    }

    public function isPositive(): bool
    {
        return $this->centavos > 0;
    }

    public function greaterThan(self $other): bool
    {
        return $this->centavos > $other->centavos;
    }

    public function lessThan(self $other): bool
    {
        return $this->centavos < $other->centavos;
    }

    public function equals(self $other): bool
    {
        return $this->centavos === $other->centavos;
    }

    public function jsonSerialize(): string
    {
        return $this->toDecimalString();
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }
}
