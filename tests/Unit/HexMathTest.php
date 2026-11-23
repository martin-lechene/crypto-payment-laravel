<?php

namespace MartinLechene\CryptoPayments\Tests\Unit;

use InvalidArgumentException;
use MartinLechene\CryptoPayments\Support\HexMath;
use MartinLechene\CryptoPayments\Tests\TestCase;

class HexMathTest extends TestCase
{
    public function test_convertit_hex_vers_decimal(): void
    {
        $this->assertSame('10', HexMath::hexToDec('0xa'));
        $this->assertSame('16', HexMath::hexToDec('0x10'));
        $this->assertSame('255', HexMath::hexToDec('0xff'));
        $this->assertSame('1000000000000000000', HexMath::hexToDec('0xde0b6b3a7640000'));
        $this->assertSame('0', HexMath::hexToDec('0x0'));
        $this->assertSame('0', HexMath::hexToDec(''));
    }

    public function test_convertit_decimal_vers_hex(): void
    {
        $this->assertSame('0x0', HexMath::decToHex('0'));
        $this->assertSame('0xa', HexMath::decToHex('10'));
        $this->assertSame('0xff', HexMath::decToHex('255'));
        $this->assertSame('0xde0b6b3a7640000', HexMath::decToHex('1000000000000000000'));
        $this->assertSame('0x5208', HexMath::decToHex('21000'));
    }

    public function test_rejette_les_valeurs_negatives_en_conversion_dechex(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HexMath::decToHex('-5');
    }

    public function test_convertit_wei_vers_ether(): void
    {
        $this->assertSame('1', HexMath::weiToEther('1000000000000000000'));
        $this->assertSame('0.05', HexMath::weiToEther('50000000000000000'));
        $this->assertSame('0.000000000000000001', HexMath::weiToEther('1'));
        $this->assertSame('0', HexMath::weiToEther('0'));
        $this->assertSame('2.5', HexMath::weiToEther('2500000000000000000'));
    }

    public function test_convertit_ether_vers_wei(): void
    {
        $this->assertSame('1000000000000000000', HexMath::etherToWei('1'));
        $this->assertSame('50000000000000000', HexMath::etherToWei('0.05'));
        $this->assertSame('2500000000000000000', HexMath::etherToWei('2.5'));
        $this->assertSame('0', HexMath::etherToWei('0'));
        $this->assertSame('1123456789000000000', HexMath::etherToWei('1.123456789'));
    }

    public function test_arrondit_les_fractions_ether_au_dela_de_18_decimales(): void
    {
        $this->assertSame('0', HexMath::etherToWei('0.0000000000000000009'));
    }

    public function test_compare_les_nombres_decimaux_comme_chaines_exactes(): void
    {
        $this->assertSame(1, HexMath::compare('1000', '999'));
        $this->assertSame(-1, HexMath::compare('0', '5'));
        $this->assertSame(0, HexMath::compare('50000000000000000000', '50000000000000000000'));
        $this->assertSame(-1, HexMath::compare('-10', '2'));
    }

    public function test_additionne_et_soustrait_en_precision_arbitraire(): void
    {
        $this->assertSame('100000000000000000000', HexMath::add('99999999999999999999', '1'));
        $this->assertSame('1', HexMath::sub('1000000000000000000', '999999999999999999'));
        $this->assertSame('-2', HexMath::add('-5', '3'));
        $this->assertSame('0', HexMath::sub('5', '5'));
    }

    public function test_multiplie_par_un_entier(): void
    {
        $this->assertSame('197520', HexMath::multiplyByInt('12345', 16));
        $this->assertSame('110000000000', HexMath::multiplyByInt('1000000000', 110));
    }

    public function test_multiplie_par_des_puissances_de_dix(): void
    {
        $this->assertSame('1000000000000000000', HexMath::multiplyByPowerOfTen('1', 18));
        $this->assertSame('1230', HexMath::multiplyByPowerOfTen('123000', -2));
        $this->assertSame('0', HexMath::multiplyByPowerOfTen('100', -3));
    }

    public function test_divise_par_un_entier(): void
    {
        [$quotient, $remainder] = HexMath::divideByInt('21000', 16);
        $this->assertSame('1312', $quotient);
        $this->assertSame(8, $remainder);
    }

    public function test_rejette_la_division_par_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HexMath::divideByInt('1', 0);
    }

    public function test_convertit_bitcoin_vers_satoshis(): void
    {
        $this->assertSame(100000, HexMath::btcToSatoshi(0.001));
        $this->assertSame(50000, HexMath::btcToSatoshi(0.0005));
        $this->assertSame(100000000, HexMath::btcToSatoshi(1.0));
    }
}
