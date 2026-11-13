<?php

declare(strict_types=1);

namespace Foundry\Tests;

use DateTimeImmutable;
use Foundry\RoValidator;
use Foundry\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoValidatorTest extends TestCase
{
    // CNP

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function validCnpProvider(): iterable
    {
        yield 'barbat 1900' => ['1800101420010'];
        yield 'femeie 1900, 29 februarie' => ['2960229401230'];
        yield 'barbat 2000' => ['5000229040079'];
        yield 'femeie 2000, Bucuresti S2' => ['6101231123456'];
        yield 'rezident strain' => ['7041230040018'];
        yield 'cetatean strain' => ['9150831700039'];
        yield 'cu spatii' => [' 1800101420010 '];
        yield 'int' => [1800101420010];
    }

    #[DataProvider('validCnpProvider')]
    public function test_valid_cnp(mixed $cnp): void
    {
        $this->assertTrue(RoValidator::cnp($cnp));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidCnpProvider(): iterable
    {
        yield 'cifra de control' => ['1800101420011', 'invalid control digit'];
        yield 'prea scurt' => ['180010142001', 'expected 13 digits, the first one 1-9'];
        yield 'litere' => ['18001014200AB', 'expected 13 digits, the first one 1-9'];
        yield 'prima cifra 0' => ['0800101420010', 'expected 13 digits, the first one 1-9'];
        yield '31 februarie' => ['1800231420010', 'invalid birth date'];
        yield '29 februarie an nebisect' => ['1810229420015', 'invalid birth date'];
        yield 'luna 13' => ['1801301420017', 'invalid birth date'];
        yield 'in viitor' => ['5991231420019', 'invalid birth date'];
        yield 'judet 49' => ['1800101490011', 'invalid county code'];
        yield 'judet 00' => ['1800101000018', 'invalid county code'];
        yield 'numar de ordine 000' => ['1800101420002', 'invalid serial number'];
        yield 'null' => [null, 'expected a string of 13 digits, got null'];
        yield 'float' => [1800101420010.0, 'expected a string of 13 digits, got float'];
    }

    #[DataProvider('invalidCnpProvider')]
    public function test_invalid_cnp(mixed $cnp, string $reason): void
    {
        $this->assertFalse(RoValidator::cnp($cnp));

        if (is_string($cnp) || is_int($cnp)) {
            try {
                RoValidator::cnpInfo($cnp);
                $this->fail('Expected exception.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame("RoValidator::cnpInfo(): invalid CNP: {$reason}.", $e->getMessage());
            }
        }
    }

    public function test_cnp_info(): void
    {
        $info = RoValidator::cnpInfo('2960229401230');

        $this->assertSame('1996-02-29', $info['birthDate']->format('Y-m-d'));
        $this->assertSame('F', $info['sex']);
        $this->assertSame('40', $info['county']);
        $this->assertFalse($info['foreigner']);

        $info = RoValidator::cnpInfo('5000229040079');
        $this->assertSame('2000-02-29', $info['birthDate']->format('Y-m-d'));
        $this->assertSame('M', $info['sex']);

        $this->assertSame('1810-12-31', RoValidator::cnpInfo('3101231420010')['birthDate']->format('Y-m-d'));
    }

    public function test_cnp_info_for_foreigners_guesses_the_century(): void
    {
        $resident = RoValidator::cnpInfo('7041230040018');
        $this->assertSame('2004-12-30', $resident['birthDate']->format('Y-m-d'));
        $this->assertSame('M', $resident['sex']);
        $this->assertTrue($resident['foreigner']);

        $foreigner = RoValidator::cnpInfo('9150831700039');
        $this->assertSame('2015-08-31', $foreigner['birthDate']->format('Y-m-d'));
        $this->assertNull($foreigner['sex']);

        // 2085 ar fi in viitor, deci 1985.
        $this->assertSame('1985-01-01', RoValidator::cnpInfo('8850101420014')['birthDate']->format('Y-m-d'));
    }

    public function test_cnp_born_today_is_valid(): void
    {
        $today = new DateTimeImmutable('today');
        $base = ((int) $today->format('Y') >= 2000 ? '5' : '1').$today->format('ymd').'40001';

        $this->assertTrue(RoValidator::cnp($base.$this->cnpControl($base)));
    }

    public function test_cnp_info_message_does_not_contain_value(): void
    {
        try {
            RoValidator::cnpInfo('1800101420011');
            $this->fail('Expected exception.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString('180010142001', $e->getMessage());
        }
    }

    // CUI

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function cuiProvider(): iterable
    {
        yield 'fara prefix' => ['6291812', true];
        yield 'cu RO' => ['RO6291812', true];
        yield 'cu RO si spatiu' => ['RO 6291812', true];
        yield 'ro litere mici' => ['ro6291812', true];
        yield 'int' => [6291812, true];
        yield 'cifra de control' => ['6291813', false];
        yield 'control 10 -> 0' => ['10', false];
        yield 'zero in fata' => ['06291812', false];
        yield 'prea lung' => ['12345678901', false];
        yield 'o cifra' => ['1', false];
        yield 'alt prefix' => ['DE6291812', false];
        yield 'gol' => ['', false];
        yield 'null' => [null, false];
    }

    #[DataProvider('cuiProvider')]
    public function test_cui(mixed $cui, bool $valid): void
    {
        $this->assertSame($valid, RoValidator::cui($cui));
    }

    public function test_cui_with_control_digit_ten(): void
    {
        // Suma*10 % 11 = 10 -> cifra de control 0.
        for ($body = 100; $body < 200; $body++) {
            $digits = str_pad((string) $body, 9, '0', STR_PAD_LEFT);
            $sum = 0;
            foreach (str_split($digits) as $i => $digit) {
                $sum += (int) $digit * (int) '753217532'[$i];
            }

            if ($sum * 10 % 11 === 10) {
                $this->assertTrue(RoValidator::cui($body.'0'));
                $this->assertFalse(RoValidator::cui($body.'1'));

                return;
            }
        }

        $this->fail('No CUI with control 10 in range.');
    }

    // IBAN

    /**
     * @return iterable<string, array{mixed, bool, bool}>
     */
    public static function ibanProvider(): iterable
    {
        yield 'RO' => ['RO49AAAA1B31007593840000', false, true];
        yield 'RO cu spatii' => ['RO49 AAAA 1B31 0075 9384 0000', false, true];
        yield 'RO litere mici' => ['ro49aaaa1b31007593840000', false, true];
        yield 'RO cifra de control' => ['RO48AAAA1B31007593840000', false, false];
        yield 'RO prea scurt' => ['RO49AAAA1B3100759384000', false, false];
        yield 'RO banca cu cifre' => ['RO49AA111B31007593840000', false, false];
        yield 'DE implicit respins' => ['DE89370400440532013000', false, false];
        yield 'DE cu anyCountry' => ['DE89370400440532013000', true, true];
        yield 'GB cu anyCountry' => ['GB82 WEST 1234 5698 7654 32', true, true];
        yield 'GB alterat' => ['GB82WEST12345698765433', true, false];
        yield 'RO cu anyCountry' => ['RO49AAAA1B31007593840000', true, true];
        yield 'caractere invalide' => ['RO49AAAA1B3100759384000-', false, false];
        yield 'null' => [null, true, false];
    }

    #[DataProvider('ibanProvider')]
    public function test_iban(mixed $iban, bool $anyCountry, bool $valid): void
    {
        $this->assertSame($valid, RoValidator::iban($iban, $anyCountry));
    }

    // Telefon

    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function phoneProvider(): iterable
    {
        yield 'mobil' => ['0721234567', '+40721234567'];
        yield 'mobil cu spatii' => ['0721 234 567', '+40721234567'];
        yield 'mobil cu +40' => ['+40 721 234 567', '+40721234567'];
        yield 'mobil cu 0040' => ['0040721234567', '+40721234567'];
        yield 'mobil cu puncte' => ['0721.234.567', '+40721234567'];
        yield 'fix Bucuresti' => ['(021) 123-45-67', '+40211234567'];
        yield 'fix 03' => ['0364123456', '+40364123456'];
        yield 'prea scurt' => ['072123456', null];
        yield 'prea lung' => ['07212345678', null];
        yield 'prefix 08' => ['0800123456', null];
        yield '40 fara plus' => ['40721234567', null];
        yield 'plus si zero' => ['+400721234567', null];
        yield 'litere' => ['07212345ab', null];
        yield 'int' => [721234567, null];
    }

    #[DataProvider('phoneProvider')]
    public function test_phone(mixed $phone, ?string $normalized): void
    {
        $this->assertSame($normalized !== null, RoValidator::phone($phone));

        if ($normalized !== null && is_string($phone)) {
            $this->assertSame($normalized, RoValidator::normalizePhone($phone));
        }
    }

    public function test_mobile_phone(): void
    {
        $this->assertTrue(RoValidator::mobilePhone('+40721234567'));
        $this->assertFalse(RoValidator::mobilePhone('0211234567'));
        $this->assertFalse(RoValidator::mobilePhone('abc'));
    }

    public function test_normalize_phone_rejects_invalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('RoValidator::normalizePhone(): value is not a valid Romanian phone number.');

        RoValidator::normalizePhone('12345');
    }

    // Cod postal, carte de identitate

    public function test_postal_code(): void
    {
        $this->assertTrue(RoValidator::postalCode('010011'));
        $this->assertTrue(RoValidator::postalCode(' 400001 '));
        $this->assertFalse(RoValidator::postalCode('40001'));
        $this->assertFalse(RoValidator::postalCode('4000011'));
        $this->assertFalse(RoValidator::postalCode('40000a'));
        $this->assertFalse(RoValidator::postalCode(400001));
    }

    public function test_identity_card(): void
    {
        $this->assertTrue(RoValidator::identityCard('RX 123456'));
        $this->assertTrue(RoValidator::identityCard('rx123456'));
        $this->assertFalse(RoValidator::identityCard('RX 12345'));
        $this->assertFalse(RoValidator::identityCard('R 123456'));
        $this->assertFalse(RoValidator::identityCard('RX-123456'));
        $this->assertFalse(RoValidator::identityCard(null));
    }

    // Integrarea cu Validator

    public function test_used_as_validator_rule(): void
    {
        $rules = ['cnp' => ['required', 'cnp' => RoValidator::cnp(...)], 'iban' => ['iban' => RoValidator::iban(...)]];

        $this->assertTrue((new Validator(['cnp' => '1800101420010', 'iban' => 'RO49AAAA1B31007593840000'], $rules))->passes());
        $this->assertSame(
            ['cnp' => 'cnp', 'iban' => 'iban'],
            (new Validator(['cnp' => '1800101420011', 'iban' => 'RO48AAAA1B31007593840000'], $rules))->codes(),
        );
    }

    private function cnpControl(string $base): string
    {
        $sum = 0;
        foreach (str_split($base) as $i => $digit) {
            $sum += (int) $digit * (int) '279146358279'[$i];
        }

        return (string) ($sum % 11 === 10 ? 1 : $sum % 11);
    }
}
