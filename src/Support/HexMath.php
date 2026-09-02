<?php

namespace MartinLechene\CryptoPayments\Support;

class HexMath
{
    private const WEI_DECIMALS = 18;

    public static function normalize(string $decimal): string
    {
        $negative = str_starts_with($decimal, '-');
        $decimal = ltrim($decimal, '+-');
        $decimal = ltrim($decimal, '0') ?: '0';

        return $negative && $decimal !== '0' ? '-'.$decimal : $decimal;
    }

    public static function compare(string $a, string $b): int
    {
        $a = self::normalize($a);
        $b = self::normalize($b);

        $negA = str_starts_with($a, '-');
        $negB = str_starts_with($b, '-');

        if ($negA !== $negB) {
            return $negA ? -1 : 1;
        }

        $a = $negA ? substr($a, 1) : $a;
        $b = $negB ? substr($b, 1) : $b;

        $cmp = self::comparePositive($a, $b);

        return $negA ? -$cmp : $cmp;
    }

    private static function comparePositive(string $a, string $b): int
    {
        $lenA = strlen($a);
        $lenB = strlen($b);

        if ($lenA !== $lenB) {
            return $lenA <=> $lenB;
        }

        return $a <=> $b;
    }

    public static function add(string $a, string $b): string
    {
        $negA = str_starts_with($a, '-');
        $negB = str_starts_with($b, '-');

        $aAbs = $negA ? substr($a, 1) : $a;
        $bAbs = $negB ? substr($b, 1) : $b;

        if ($negA === $negB) {
            $sum = self::addPositive($aAbs, $bAbs);

            return $negA ? '-'.$sum : $sum;
        }

        $cmp = self::comparePositive($aAbs, $bAbs);

        if ($cmp === 0) {
            return '0';
        }

        if ($cmp > 0) {
            $result = self::subPositive($aAbs, $bAbs);
        } else {
            $result = self::subPositive($bAbs, $aAbs);
        }

        $isNegative = ($cmp > 0 && $negA) || ($cmp < 0 && $negB);

        return $isNegative ? '-'.$result : $result;
    }

    public static function sub(string $a, string $b): string
    {
        $b = self::negate($b);

        return self::add($a, $b);
    }

    public static function negate(string $value): string
    {
        $value = self::normalize($value);

        if ($value === '0') {
            return '0';
        }

        return str_starts_with($value, '-') ? substr($value, 1) : '-'.$value;
    }

    private static function addPositive(string $a, string $b): string
    {
        $carry = 0;
        $result = '';
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;

        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $digit = $carry;
            if ($i >= 0) {
                $digit += (int) $a[$i--];
            }
            if ($j >= 0) {
                $digit += (int) $b[$j--];
            }

            $carry = intdiv($digit, 10);
            $result = ($digit % 10).$result;
        }

        return $result;
    }

    private static function subPositive(string $a, string $b): string
    {
        $borrow = 0;
        $result = '';
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;

        while ($i >= 0) {
            $digit = (int) $a[$i--] - $borrow;
            $digit -= $j >= 0 ? (int) $b[$j--] : 0;

            if ($digit < 0) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }

            $result = $digit.$result;
        }

        return ltrim($result, '0') ?: '0';
    }

    public static function multiplyByInt(string $decimal, int $multiplier): string
    {
        $decimal = self::normalize($decimal);
        $negative = str_starts_with($decimal, '-');
        $decimal = $negative ? substr($decimal, 1) : $decimal;

        $multiplier = abs($multiplier);
        $result = '0';
        $acc = $decimal;

        while ($multiplier > 0) {
            if ($multiplier & 1) {
                $result = self::addPositive($result, $acc);
            }
            $acc = self::addPositive($acc, $acc);
            $multiplier >>= 1;
        }

        return $negative ? '-'.$result : $result;
    }

    public static function multiplyByPowerOfTen(string $decimal, int $power): string
    {
        $decimal = self::normalize($decimal);

        if ($power === 0) {
            return $decimal;
        }

        $negative = str_starts_with($decimal, '-');
        $decimal = $negative ? substr($decimal, 1) : $decimal;

        if ($power > 0) {
            $decimal .= str_repeat('0', $power);
        } else {
            $decimal = ltrim(substr($decimal, 0, strlen($decimal) + $power), '0') ?: '0';
        }

        return $negative && $decimal !== '0' ? '-'.$decimal : $decimal;
    }

    public static function hexToDec(string $hex): string
    {
        $hex = strtolower(ltrim($hex, '0x'));

        if ($hex === '') {
            return '0';
        }

        $result = '0';

        foreach (str_split($hex) as $char) {
            $value = hexdec($char);
            $result = self::add(self::multiplyByInt($result, 16), (string) $value);
        }

        return $result;
    }

    public static function decToHex(string $decimal): string
    {
        $decimal = self::normalize($decimal);

        if ($decimal === '0') {
            return '0x0';
        }

        $negative = str_starts_with($decimal, '-');

        if ($negative) {
            throw new \InvalidArgumentException('decToHex() rejects negative values');
        }

        $hex = '';
        $current = $decimal;

        while ($current !== '0') {
            [$current, $remainder] = self::divideByInt($current, 16);
            $hex = dechex($remainder).$hex;
        }

        return '0x'.$hex;
    }

    public static function divideByInt(string $decimal, int $divisor): array
    {
        if ($divisor === 0) {
            throw new \InvalidArgumentException('Division by zero');
        }

        $decimal = self::normalize($decimal);
        $negative = str_starts_with($decimal, '-');
        $decimal = $negative ? substr($decimal, 1) : $decimal;

        $quotient = '';
        $remainder = 0;

        for ($i = 0, $len = strlen($decimal); $i < $len; $i++) {
            $remainder = $remainder * 10 + (int) $decimal[$i];
            $digit = intdiv($remainder, $divisor);
            $remainder %= $divisor;

            if ($quotient !== '' || $digit > 0) {
                $quotient .= (string) $digit;
            }
        }

        return [
            $negative && $quotient !== '' ? '-'.$quotient : ($quotient ?: '0'),
            $negative ? -$remainder : $remainder,
        ];
    }

    public static function hexToInt(string $hex): int
    {
        return (int) self::hexToDec($hex);
    }

    public static function weiToEther(string $wei): string
    {
        $decimal = self::normalize($wei);
        $negative = str_starts_with($decimal, '-');
        $decimal = $negative ? substr($decimal, 1) : $decimal;

        $sign = $negative ? '-' : '';

        if (strlen($decimal) <= self::WEI_DECIMALS) {
            $fraction = str_pad($decimal, self::WEI_DECIMALS, '0', STR_PAD_LEFT);
            $fraction = rtrim($fraction, '0');

            if ($fraction === '') {
                return $sign.'0';
            }

            return $sign.'0.'.$fraction;
        }

        $intPart = substr($decimal, 0, -self::WEI_DECIMALS);
        $fraction = rtrim(substr($decimal, -self::WEI_DECIMALS), '0');

        return $sign.$intPart.($fraction === '' ? '' : '.'.$fraction);
    }

    public static function etherToWei(string $ether): string
    {
        $ether = self::normalize($ether);
        $negative = str_starts_with($ether, '-');
        $ether = $negative ? substr($ether, 1) : $ether;

        $parts = explode('.', $ether, 2);
        $intPart = $parts[0];

        if (isset($parts[1])) {
            if (strlen($parts[1]) > self::WEI_DECIMALS) {
                $parts[1] = substr($parts[1], 0, self::WEI_DECIMALS);
            }
            $fraction = str_pad($parts[1], self::WEI_DECIMALS, '0', STR_PAD_RIGHT);
        } else {
            $fraction = str_repeat('0', self::WEI_DECIMALS);
        }

        $result = ltrim($intPart.$fraction, '0') ?: '0';

        return $negative ? '-'.$result : $result;
    }

    public static function btcToSatoshi(float $amountBtc): int
    {
        return (int) round($amountBtc * 1e8);
    }
}
