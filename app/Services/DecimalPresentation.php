<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class DecimalPresentation
{
    public static function input(string $value): string
    {
        $value = trim($value);
        if (preg_match('/\A-?[0-9]{1,3}(?:\.[0-9]{3})+(?:,[0-9]+)?\z/', $value)) {
            $value = str_replace('.', '', $value);
        }

        return str_replace(',', '.', $value);
    }

    public static function format(string $value, int $scale = 4): string
    {
        $parts = explode('.', (string) BigDecimal::of($value)->toScale($scale, RoundingMode::HalfUp));
        $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $parts[0]);

        return $integer.($scale > 0 ? ','.$parts[1] : '');
    }

    public static function weight(string $grams): string
    {
        $weight = BigDecimal::of($grams);
        $unit = 'g';
        if ($weight->isGreaterThanOrEqualTo('1000')) {
            $weight = $weight->withPointMovedLeft(3);
            $unit = 'kg';
        }

        $display = self::format((string) $weight, $weight->getScale());
        if ($weight->getScale() > 0) {
            $display = rtrim(rtrim($display, '0'), ',');
        }

        return $display.' '.$unit;
    }

    public static function quantity(string $value): string
    {
        $scale = BigDecimal::of($value)->getScale();
        $display = self::format($value, $scale);

        return $scale > 0 ? rtrim(rtrim($display, '0'), ',') : $display;
    }
}
