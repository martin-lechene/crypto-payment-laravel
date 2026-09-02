<?php

use MartinLechene\CryptoPayments\Support\HexMath;

it('convertit hex vers decimal', function () {
    expect(HexMath::hexToDec('0xa'))->toBe('10');
    expect(HexMath::hexToDec('0x10'))->toBe('16');
    expect(HexMath::hexToDec('0xff'))->toBe('255');
    expect(HexMath::hexToDec('0xde0b6b3a7640000'))->toBe('1000000000000000000');
    expect(HexMath::hexToDec('0x0'))->toBe('0');
    expect(HexMath::hexToDec(''))->toBe('0');
});

it('convertit decimal vers hex', function () {
    expect(HexMath::decToHex('0'))->toBe('0x0');
    expect(HexMath::decToHex('10'))->toBe('0xa');
    expect(HexMath::decToHex('255'))->toBe('0xff');
    expect(HexMath::decToHex('1000000000000000000'))->toBe('0xde0b6b3a7640000');
    expect(HexMath::decToHex('21000'))->toBe('0x5208');
});

it('rejette les valeurs négatives en conversion dec→hex', function () {
    HexMath::decToHex('-5');
})->throws(InvalidArgumentException::class);

it('convertit wei vers ether', function () {
    expect(HexMath::weiToEther('1000000000000000000'))->toBe('1');
    expect(HexMath::weiToEther('50000000000000000'))->toBe('0.05');
    expect(HexMath::weiToEther('1'))->toBe('0.000000000000000001');
    expect(HexMath::weiToEther('0'))->toBe('0');
    expect(HexMath::weiToEther('2500000000000000000'))->toBe('2.5');
});

it('convertit ether vers wei', function () {
    expect(HexMath::etherToWei('1'))->toBe('1000000000000000000');
    expect(HexMath::etherToWei('0.05'))->toBe('50000000000000000');
    expect(HexMath::etherToWei('2.5'))->toBe('2500000000000000000');
    expect(HexMath::etherToWei('0'))->toBe('0');
    expect(HexMath::etherToWei('1.123456789'))->toBe('1123456789000000000');
});

it('arrondit les fractions ether au-delà de 18 décimales', function () {
    expect(HexMath::etherToWei('0.0000000000000000009'))->toBe('0');
});

it('compare les nombres décimaux comme chaînes exactes', function () {
    expect(HexMath::compare('1000', '999'))->toBe(1);
    expect(HexMath::compare('0', '5'))->toBe(-1);
    expect(HexMath::compare('50000000000000000000', '50000000000000000000'))->toBe(0);
    expect(HexMath::compare('-10', '2'))->toBe(-1);
});

it('additionne et soustrait en précision arbitraire', function () {
    expect(HexMath::add('99999999999999999999', '1'))->toBe('100000000000000000000');
    expect(HexMath::sub('1000000000000000000', '999999999999999999'))->toBe('1');
    expect(HexMath::add('-5', '3'))->toBe('-2');
    expect(HexMath::sub('5', '5'))->toBe('0');
});

it('multiplie par un entier', function () {
    expect(HexMath::multiplyByInt('12345', 16))->toBe('197520');
    expect(HexMath::multiplyByInt('1000000000', 110))->toBe('110000000000');
});

it('multiplie par des puissances de dix', function () {
    expect(HexMath::multiplyByPowerOfTen('1', 18))->toBe('1000000000000000000');
    expect(HexMath::multiplyByPowerOfTen('123000', -2))->toBe('1230');
    expect(HexMath::multiplyByPowerOfTen('100', -3))->toBe('0');
});

it('divise par un entier', function () {
    [$quotient, $remainder] = HexMath::divideByInt('21000', 16);
    expect($quotient)->toBe('1312');
    expect($remainder)->toBe(8);
});

it('réjette la division par zéro', function () {
    HexMath::divideByInt('1', 0);
})->throws(InvalidArgumentException::class);

it('convertit bitcoin vers satoshis', function () {
    expect(HexMath::btcToSatoshi(0.001))->toBe(100000);
    expect(HexMath::btcToSatoshi(0.0005))->toBe(50000);
    expect(HexMath::btcToSatoshi(1.0))->toBe(100000000);
});
