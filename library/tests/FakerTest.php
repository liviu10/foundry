<?php

declare(strict_types=1);

namespace Foundry\Tests;

use DateTimeImmutable;
use Foundry\Faker;
use Foundry\RoValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FakerTest extends TestCase
{
    public function test_same_seed_gives_same_data(): void
    {
        $a = new Faker(seed: 42);
        $b = new Faker(seed: 42);

        $this->assertEquals($a->person(), $b->person());
        $this->assertSame($a->iban(), $b->iban());
        $this->assertNotEquals((new Faker(seed: 43))->person(), (new Faker(seed: 42))->person());
    }

    public function test_seed_zero_and_negative_work(): void
    {
        $this->assertSame((new Faker(0))->int(1, 1000), (new Faker(0))->int(1, 1000));
        $this->assertSame((new Faker(-5))->name(), (new Faker(-5))->name());
    }

    public function test_does_not_touch_global_random_state(): void
    {
        mt_srand(7);
        $expected = mt_rand();

        mt_srand(7);
        (new Faker(1))->person();
        $this->assertSame($expected, mt_rand());
    }

    public function test_unseeded_data_differs(): void
    {
        $values = [];
        for ($i = 0; $i < 10; $i++) {
            $values[] = (new Faker)->cnp();
        }

        $this->assertGreaterThan(1, count(array_unique($values)));
    }

    public function test_int_range_and_distribution(): void
    {
        $faker = new Faker(1);
        $counts = array_fill(1, 6, 0);
        for ($i = 0; $i < 6000; $i++) {
            $counts[$faker->int(1, 6)]++;
        }

        // Fiecare valoare apare; niciuna nu este grav favorizata.
        foreach ($counts as $count) {
            $this->assertGreaterThan(850, $count);
            $this->assertLessThan(1150, $count);
        }

        $this->assertSame(5, $faker->int(5, 5));
        $this->assertSame(-3, (new Faker(1))->int(-3, -3));
    }

    public function test_bool_element_digits(): void
    {
        $faker = new Faker(3);

        $this->assertFalse($faker->bool(0.0));
        $this->assertTrue($faker->bool(1.0));
        $this->assertContains($faker->element(['a' => 'x', 'b' => 'y']), ['x', 'y']);
        $this->assertMatchesRegularExpression('/^\d{12}$/', $faker->digits(12));
        $this->assertSame('', $faker->digits(0));
    }

    public function test_date_range(): void
    {
        $faker = new Faker(9);
        $from = new DateTimeImmutable('2026-01-30');
        $to = new DateTimeImmutable('2026-02-02');

        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $date = $faker->date($from, $to);
            $this->assertSame('00:00:00', $date->format('H:i:s'));
            $seen[$date->format('Y-m-d')] = true;
        }

        ksort($seen);
        $this->assertSame(['2026-01-30', '2026-01-31', '2026-02-01', '2026-02-02'], array_keys($seen));
        $this->assertSame('1850-03-01', $faker->date(new DateTimeImmutable('1850-03-01'), new DateTimeImmutable('1850-03-01'))->format('Y-m-d'));
    }

    // Identificatori validati cu RoValidator

    public function test_generated_identifiers_are_valid(): void
    {
        $faker = new Faker(2026);
        for ($i = 0; $i < 2000; $i++) {
            $cnp = $faker->cnp();
            $cui = $faker->cui($i % 2 === 0);
            $iban = $faker->iban();
            $mobile = $faker->phone();
            $landline = $faker->phone(false);

            $this->assertTrue(RoValidator::cnp($cnp), "CNP {$cnp}");
            $this->assertTrue(RoValidator::cui($cui), "CUI {$cui}");
            $this->assertTrue(RoValidator::iban($iban), "IBAN {$iban}");
            $this->assertTrue(RoValidator::mobilePhone($mobile), "mobil {$mobile}");
            $this->assertTrue(RoValidator::phone($landline), "fix {$landline}");
            $this->assertFalse(RoValidator::mobilePhone($landline), "fix {$landline}");
        }
    }

    /**
     * @return iterable<string, array{'M'|'F', string, string}>
     */
    public static function cnpProvider(): iterable
    {
        yield 'barbat 1900' => ['M', '1985-07-21', '1'];
        yield 'femeie 1900' => ['F', '1999-12-31', '2'];
        yield 'barbat 1800' => ['M', '1899-01-01', '3'];
        yield 'femeie 2000' => ['F', '2004-02-29', '6'];
        yield 'barbat 2000' => ['M', '2000-01-01', '5'];
    }

    /**
     * @param  'M'|'F'  $sex
     */
    #[DataProvider('cnpProvider')]
    public function test_cnp_matches_sex_and_birth_date(string $sex, string $date, string $first): void
    {
        $cnp = (new Faker(5))->cnp($sex, new DateTimeImmutable($date), '12');
        $info = RoValidator::cnpInfo($cnp);

        $this->assertSame($first, $cnp[0]);
        $this->assertSame($date, $info['birthDate']->format('Y-m-d'));
        $this->assertSame($sex, $info['sex']);
        $this->assertSame('12', $info['county']);
    }

    public function test_person_is_coherent(): void
    {
        $faker = new Faker(11);
        for ($i = 0; $i < 200; $i++) {
            $person = $faker->person();
            $info = RoValidator::cnpInfo($person['cnp']);

            $this->assertSame($person['sex'], $info['sex']);
            $this->assertSame($person['birthDate']->format('Y-m-d'), $info['birthDate']->format('Y-m-d'));
            $this->assertSame($person['firstName'].' '.$person['lastName'], $person['name']);
            $this->assertMatchesRegularExpression('/^[a-z0-9.]+\d+@example\.com$/', $person['email']);
            $this->assertTrue(RoValidator::postalCode($person['address']['postalCode']));
            $this->assertTrue(mb_check_encoding($person['address']['full'], 'UTF-8'));

            $age = $person['birthDate']->diff(new DateTimeImmutable('today'))->y;
            $this->assertGreaterThanOrEqual(18, $age);
            $this->assertLessThanOrEqual(80, $age);
        }
    }

    public function test_names_and_email(): void
    {
        $faker = new Faker(4);

        $this->assertMatchesRegularExpression('/^\S+ \S+$/u', $faker->name('F'));
        $this->assertSame('stefan.serban', explode('@', preg_replace('/\d+@/', '@', $faker->email("\u{0218}tefan \u{0218}erban")) ?? '')[0]);
        $this->assertStringEndsWith('@example.com', $faker->email());
    }

    public function test_data_contains_diacritics(): void
    {
        $faker = new Faker(8);
        $text = '';
        for ($i = 0; $i < 300; $i++) {
            $text .= $faker->name().' '.$faker->address()['full'];
        }

        $this->assertMatchesRegularExpression("/[\u{0103}\u{00E2}\u{00EE}\u{0219}\u{021B}\u{0218}\u{021A}]/u", $text);
    }

    public function test_company(): void
    {
        $company = (new Faker(6))->company();

        $this->assertMatchesRegularExpression('/^\S+ \S+ (SRL|SA)$/u', $company['name']);
        $this->assertTrue(RoValidator::cui($company['cui']));
    }

    public function test_iban_with_bank(): void
    {
        $iban = (new Faker(1))->iban('BTRL');

        $this->assertSame('BTRL', substr($iban, 4, 4));
        $this->assertTrue(RoValidator::iban($iban));
    }

    /**
     * @return iterable<string, array{callable(Faker): mixed, string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'interval inversat' => [static fn (Faker $f): int => $f->int(5, 1), 'Faker::int(): invalid range 5..1'];
        yield 'interval prea mare' => [static fn (Faker $f): int => $f->int(0, PHP_INT_MAX), 'Faker::int(): invalid range'];
        yield 'probabilitate' => [static fn (Faker $f): bool => $f->bool(1.5), 'the probability must be between 0 and 1'];
        yield 'lista goala' => [static fn (Faker $f): mixed => $f->element([]), 'Faker::element(): the list is empty.'];
        yield 'cifre negative' => [static fn (Faker $f): string => $f->digits(-1), 'the length cannot be negative'];
        yield 'date inversate' => [static fn (Faker $f): DateTimeImmutable => $f->date(new DateTimeImmutable('2026-02-01'), new DateTimeImmutable('2026-01-01')), 'the start date is after the end date'];
        yield 'sex invalid' => [static fn (Faker $f): string => $f->cnp('X'), "Faker::cnp(): sex must be 'M' or 'F', got 'X'."];
        yield 'nume sex invalid' => [static fn (Faker $f): string => $f->firstName('m'), "Faker::firstName(): sex must be 'M' or 'F', got 'm'."];
        yield 'cnp in viitor' => [static fn (Faker $f): string => $f->cnp('M', new DateTimeImmutable('+1 day')), 'the birth date must be between 1800 and today'];
        yield 'cnp inainte de 1800' => [static fn (Faker $f): string => $f->cnp('M', new DateTimeImmutable('1799-12-31')), 'the birth date must be between 1800 and today'];
        yield 'judet invalid' => [static fn (Faker $f): string => $f->cnp('M', null, '49'), "invalid county code '49'"];
        yield 'banca invalida' => [static fn (Faker $f): string => $f->iban('btrl'), "invalid bank code 'btrl'"];
    }

    /**
     * @param  callable(Faker): mixed  $callback
     */
    #[DataProvider('invalidProvider')]
    public function test_invalid(callable $callback, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $callback(new Faker(1));
    }
}
