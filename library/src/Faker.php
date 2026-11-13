<?php

declare(strict_types=1);

namespace Foundry;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Faker Class
 *
 * Romanian test data generator: names, CNP, CUI, IBAN, phone numbers, emails, addresses, companies, and calendar dates.
 * Identifiers are valid (accurate check digits) and internally consistent: a person's CNP matches
 * their gender and birth date. Names and addresses include Romanian diacritics to test UTF-8 encoding.
 *
 * Rules:
 *   - without a seed, data varies on each run; with a seed, output is deterministic (tests, demos);
 *   - uses an internal generator: does not affect application-level mt_rand() / rand();
 *   - emails use example.com (domain reserved for testing, never delivers to real recipients);
 *   - data is fictitious, though a validly generated CNP may correspond to a real person: do not use in production.
 *
 * Usage:
 *   require_once 'Faker.php';
 *   use Foundry\Faker;
 *
 *   $faker = new Faker(seed: 42);
 *   $persoana = $faker->person();         // name, sex, birthDate, cnp, email, phone, address
 *   $cnp = $faker->cnp(sex: 'F', birthDate: new DateTimeImmutable('1990-05-17'));
 *   $iban = $faker->iban();               // 'RO..BTRL................'
 *   $firma = $faker->company();           // ['name' => 'Popescu Construct SRL', 'cui' => '...']
 *
 * @category Utilities
 *
 * @version  1.0
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class Faker
{
    private const int MASK = 0xFFFFFFFF;

    private const array MALE_NAMES = [
        'Andrei', 'Alexandru', 'Mihai', 'Ion', 'Gheorghe', 'Constantin', 'Vasile', "\u{0218}tefan", 'Cristian', 'Daniel',
        'Florin', 'Adrian', 'Marius', 'Bogdan', 'Radu', "R\u{0103}zvan", "Ionu\u{021B}", 'Gabriel', "C\u{0103}t\u{0103}lin", 'Sorin',
        'Lucian', 'Dumitru', 'Nicolae', 'Petru', 'Victor',
    ];

    private const array FEMALE_NAMES = [
        'Maria', 'Elena', 'Ioana', 'Ana', 'Andreea', 'Alexandra', 'Mihaela', 'Cristina', 'Gabriela', 'Daniela',
        'Raluca', 'Roxana', 'Simona', 'Laura', 'Monica', 'Diana', 'Irina', 'Corina', "\u{0218}tefania", "M\u{0103}d\u{0103}lina",
        'Larisa', 'Oana', 'Alina', 'Bianca', 'Teodora',
    ];

    private const array LAST_NAMES = [
        'Popescu', 'Ionescu', 'Popa', 'Pop', 'Radu', 'Dumitru', 'Stan', 'Stoica', 'Gheorghe', 'Matei',
        'Ciobanu', 'Rusu', 'Munteanu', 'Constantin', 'Mihai', "Laz\u{0103}r", 'Florea', 'Marin', 'Dinu', 'Tudor',
        'Moldovan', 'Barbu', 'Ene', 'Nistor', "\u{0218}erban", 'Toma', 'Cristea', 'Vasile', 'Dobre', 'Ungureanu',
        'Sandu', 'Neagu', 'Oprea', 'Diaconu', "\u{021A}urcanu",
    ];

    /** @var array<int|string, array{string, string}> County code in CNP => [county, seat] ('10'... become int keys). */
    private const array COUNTIES = [
        '01' => ['Alba', 'Alba Iulia'], '02' => ['Arad', 'Arad'], '03' => ["Arge\u{0219}", "Pite\u{0219}ti"],
        '04' => ["Bac\u{0103}u", "Bac\u{0103}u"], '05' => ['Bihor', 'Oradea'], '06' => ["Bistri\u{021B}a-N\u{0103}s\u{0103}ud", "Bistri\u{021B}a"],
        '07' => ["Boto\u{0219}ani", "Boto\u{0219}ani"], '08' => ["Bra\u{0219}ov", "Bra\u{0219}ov"], '09' => ["Br\u{0103}ila", "Br\u{0103}ila"],
        '10' => ["Buz\u{0103}u", "Buz\u{0103}u"], '11' => ["Cara\u{0219}-Severin", "Re\u{0219}i\u{021B}a"], '12' => ['Cluj', 'Cluj-Napoca'],
        '13' => ["Constan\u{021B}a", "Constan\u{021B}a"], '14' => ['Covasna', "Sf\u{00E2}ntu Gheorghe"], '15' => ["D\u{00E2}mbovi\u{021B}a", "T\u{00E2}rgovi\u{0219}te"],
        '16' => ['Dolj', 'Craiova'], '17' => ["Gala\u{021B}i", "Gala\u{021B}i"], '18' => ['Gorj', "T\u{00E2}rgu Jiu"],
        '19' => ['Harghita', 'Miercurea Ciuc'], '20' => ['Hunedoara', 'Deva'], '21' => ["Ialomi\u{021B}a", 'Slobozia'],
        '22' => ["Ia\u{0219}i", "Ia\u{0219}i"], '23' => ['Ilfov', 'Buftea'], '24' => ["Maramure\u{0219}", 'Baia Mare'],
        '25' => ["Mehedin\u{021B}i", 'Drobeta-Turnu Severin'], '26' => ["Mure\u{0219}", "T\u{00E2}rgu Mure\u{0219}"], '27' => ["Neam\u{021B}", "Piatra Neam\u{021B}"],
        '28' => ['Olt', 'Slatina'], '29' => ['Prahova', "Ploie\u{0219}ti"], '30' => ['Satu Mare', 'Satu Mare'],
        '31' => ["S\u{0103}laj", "Zal\u{0103}u"], '32' => ['Sibiu', 'Sibiu'], '33' => ['Suceava', 'Suceava'],
        '34' => ['Teleorman', 'Alexandria'], '35' => ["Timi\u{0219}", "Timi\u{0219}oara"], '36' => ['Tulcea', 'Tulcea'],
        '37' => ['Vaslui', 'Vaslui'], '38' => ["V\u{00E2}lcea", "R\u{00E2}mnicu V\u{00E2}lcea"], '39' => ['Vrancea', "Foc\u{0219}ani"],
        '40' => ["Bucure\u{0219}ti", "Bucure\u{0219}ti"], '51' => ["C\u{0103}l\u{0103}ra\u{0219}i", "C\u{0103}l\u{0103}ra\u{0219}i"], '52' => ['Giurgiu', 'Giurgiu'],
    ];

    /** @var list<string> County codes accepted by cnp() (including Bucharest sectors and 70). */
    private const array CNP_COUNTIES = [
        '01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19', '20',
        '21', '22', '23', '24', '25', '26', '27', '28', '29', '30', '31', '32', '33', '34', '35', '36', '37', '38', '39', '40',
        '41', '42', '43', '44', '45', '46', '47', '48', '51', '52', '70',
    ];

    private const array STREETS = [
        'Mihai Eminescu', "\u{0218}tefan cel Mare", 'Unirii', 'Republicii', "Libert\u{0103}\u{021B}ii", 'Victoriei', 'Florilor', 'Teilor',
        'Morii', "G\u{0103}rii", "Independen\u{021B}ei", 'Avram Iancu', "Nicolae B\u{0103}lcescu", '1 Decembrie 1918', 'Mihai Viteazul',
    ];

    private const array STREET_TYPES = ['Strada', 'Strada', 'Strada', 'Bulevardul', 'Calea', 'Aleea'];

    /** @var list<string> Real BIC codes (BT, BCR, BRD, ING, Raiffeisen, CEC, UniCredit, OTP). */
    private const array BANKS = ['BTRL', 'RNCB', 'BRDE', 'INGB', 'RZBR', 'CECE', 'BACX', 'OTPV'];

    private const array COMPANY_WORDS = ['Construct', 'Impex', 'Trans', 'Agro', 'Consult', 'Soft', 'Invest', 'Med', 'Logistic', 'Service'];

    private int $state;

    /**
     * @param  int|null  $seed  Same seed = identical data; null = different data on each run.
     */
    public function __construct(?int $seed = null)
    {
        $state = ($seed ?? random_int(1, self::MASK)) & self::MASK;
        $this->state = ($state ^ 0x9E3779B9) & self::MASK ?: 1;

        // Primele valori dupa un seed mic sunt slab amestecate.
        for ($i = 0; $i < 8; $i++) {
            $this->next();
        }
    }

    // Valori de baza

    /**
     * Integer between $min and $max (inclusive); the range spans at most 2^32 values.
     *
     * @throws InvalidArgumentException If $min > $max or the range is too large.
     */
    public function int(int $min, int $max): int
    {
        if ($min > $max || $max - $min > self::MASK) {
            throw new InvalidArgumentException("Faker::int(): invalid range {$min}..{$max} (min <= max, at most 2^32 values).");
        }

        $range = $max - $min + 1;
        // Respingere: fara favorizarea valorilor mici cand 2^32 nu se imparte exact la interval.
        $limit = intdiv(self::MASK + 1, $range) * $range;
        do {
            $value = $this->next();
        } while ($value >= $limit);

        return $min + $value % $range;
    }

    /**
     * Returns true with the given probability (0..1).
     *
     * @throws InvalidArgumentException If the probability is not between 0 and 1.
     */
    public function bool(float $probability = 0.5): bool
    {
        if ($probability < 0 || $probability > 1) {
            throw new InvalidArgumentException('Faker::bool(): the probability must be between 0 and 1.');
        }

        return $this->next() / (self::MASK + 1) < $probability;
    }

    /**
     * Picks an element from the given array.
     *
     * @template T
     *
     * @param  array<array-key, T>  $items
     * @return T
     *
     * @throws InvalidArgumentException If the list is empty.
     */
    public function element(array $items): mixed
    {
        if ($items === []) {
            throw new InvalidArgumentException('Faker::element(): the list is empty.');
        }

        $values = array_values($items);

        return $values[$this->int(0, count($values) - 1)];
    }

    /**
     * String of digits of the given length (may start with 0).
     *
     * @throws InvalidArgumentException If the length is negative.
     */
    public function digits(int $length): string
    {
        if ($length < 0) {
            throw new InvalidArgumentException('Faker::digits(): the length cannot be negative.');
        }

        $digits = '';
        for ($i = 0; $i < $length; $i++) {
            $digits .= (string) $this->int(0, 9);
        }

        return $digits;
    }

    /**
     * Date between two dates (inclusive), at 00:00:00.
     *
     * @throws InvalidArgumentException If $from is after $to.
     */
    public function date(DateTimeInterface $from, DateTimeInterface $to): DateTimeImmutable
    {
        // Zilele calendaristice ale celor doua date, indiferent de ora si de fusul orar.
        $start = intdiv((int) strtotime($from->format('Y-m-d').' UTC'), 86400);
        $end = intdiv((int) strtotime($to->format('Y-m-d').' UTC'), 86400);
        if ($start > $end) {
            throw new InvalidArgumentException('Faker::date(): the start date is after the end date.');
        }

        // Ziua in fusul orar implicit al aplicatiei, la 00:00.
        return new DateTimeImmutable(gmdate('Y-m-d', $this->int($start, $end) * 86400));
    }

    // Persoane

    /**
     * @param  string|null  $sex  'M' or 'F'; null = random.
     *
     * @throws InvalidArgumentException If the sex is not M or F.
     */
    public function firstName(?string $sex = null): string
    {
        return $this->element($this->sex('firstName', $sex ?? $this->sexValue()) === 'M' ? self::MALE_NAMES : self::FEMALE_NAMES);
    }

    public function lastName(): string
    {
        return $this->element(self::LAST_NAMES);
    }

    /**
     * First and last name ('Andreea Popescu').
     *
     * @param  string|null  $sex  'M' or 'F'; null = random.
     *
     * @throws InvalidArgumentException If the sex is not M or F.
     */
    public function name(?string $sex = null): string
    {
        return $this->firstName($sex).' '.$this->lastName();
    }

    /**
     * Generates a complete and consistent person profile (CNP matching sex and birth date; email derived from name).
     *
     * @return array{firstName: string, lastName: string, name: string, sex: 'M'|'F', birthDate: DateTimeImmutable, cnp: string, email: string, phone: string, address: array{street: string, number: string, city: string, county: string, postalCode: string, full: string}}
     */
    public function person(): array
    {
        $sex = $this->sexValue();
        $firstName = $this->firstName($sex);
        $lastName = $this->lastName();
        $today = new DateTimeImmutable('today');
        $birthDate = $this->date($today->modify('-80 years'), $today->modify('-18 years'));
        [$county, $address] = $this->pickAddress();

        return [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'name' => "{$firstName} {$lastName}",
            'sex' => $sex,
            'birthDate' => $birthDate,
            'cnp' => $this->cnp($sex, $birthDate, $county),
            'email' => $this->email("{$firstName} {$lastName}"),
            'phone' => $this->phone(),
            'address' => $address,
        ];
    }

    // Identificatori

    /**
     * Valid CNP. Without arguments: random sex, birth date (18-80 years old), and county.
     *
     * @param  string|null  $sex  'M' or 'F'; null = random.
     * @param  string|null  $county  County code in CNP ('12' = Cluj, '40' = Bucharest, '70', etc.).
     *
     * @throws InvalidArgumentException If sex, date (in future or before 1800), or county are invalid.
     */
    public function cnp(?string $sex = null, ?DateTimeInterface $birthDate = null, ?string $county = null): string
    {
        $sex = $this->sex('cnp', $sex ?? $this->sexValue());

        if (! $birthDate instanceof DateTimeInterface) {
            $today = new DateTimeImmutable('today');
            $birthDate = $this->date($today->modify('-80 years'), $today->modify('-18 years'));
        }

        $year = (int) $birthDate->format('Y');
        if ($year < 1800 || $year > 2099 || $birthDate > new DateTimeImmutable('today')) {
            throw new InvalidArgumentException('Faker::cnp(): the birth date must be between 1800 and today.');
        }

        if ($county !== null && ! in_array($county, self::CNP_COUNTIES, true)) {
            throw new InvalidArgumentException("Faker::cnp(): invalid county code '{$county}'.");
        }

        $century = intdiv($year, 100);
        $first = match ($century) {
            18 => 3,
            19 => 1,
            default => 5,
        } + ($sex === 'F' ? 1 : 0);

        $body = $first.$birthDate->format('ymd').($county ?? $this->element(self::CNP_COUNTIES)).sprintf('%03d', $this->int(1, 999));

        $sum = 0;
        foreach (str_split($body) as $index => $digit) {
            $sum += (int) $digit * (int) '279146358279'[$index];
        }

        return $body.($sum % 11 === 10 ? '1' : (string) ($sum % 11));
    }

    /**
     * Valid CUI (6-9 digits plus check digit), optionally with the 'RO' VAT prefix.
     */
    public function cui(bool $vatPrefix = false): string
    {
        $body = $this->int(1, 9).$this->digits($this->int(5, 8));
        $padded = str_pad($body, 9, '0', STR_PAD_LEFT);

        $sum = 0;
        foreach (str_split($padded) as $index => $digit) {
            $sum += (int) $digit * (int) '753217532'[$index];
        }

        return ($vatPrefix ? 'RO' : '').$body.($sum * 10 % 11 % 10);
    }

    /**
     * Valid Romanian IBAN (mod 97) at a common bank or the specified bank (4-letter BIC code).
     *
     * @throws InvalidArgumentException If the bank code is not 4 uppercase letters.
     */
    public function iban(?string $bank = null): string
    {
        $bank ??= $this->element(self::BANKS);
        if (preg_match('/^[A-Z]{4}$/', $bank) !== 1) {
            throw new InvalidArgumentException("Faker::iban(): invalid bank code '{$bank}' (expected 4 uppercase letters).");
        }

        $account = $this->digits(16);
        $numeric = '';
        foreach (str_split($bank.$account.'RO00') as $char) {
            $numeric .= ctype_digit($char) ? $char : (string) (ord($char) - 55);
        }

        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return 'RO'.sprintf('%02d', 98 - $remainder).$bank.$account;
    }

    // Contact si adresa

    /**
     * Romanian phone number in national format ('0721234567'): mobile (07...) or landline (02.../03...).
     */
    public function phone(bool $mobile = true): string
    {
        if ($mobile) {
            return '07'.$this->int(2, 9).$this->digits(7);
        }

        $prefix = $this->element(['21', '31', '264', '256', '232', '268', '241', '251', '269', '257']);

        return '0'.$prefix.$this->digits(9 - strlen($prefix));
    }

    /**
     * Email on example.com (reserved for testing): derived from the given name or a random name.
     */
    public function email(?string $name = null): string
    {
        $name ??= $this->name();
        $ascii = strtr($name, [
            "\u{0103}" => 'a', "\u{00E2}" => 'a', "\u{00EE}" => 'i', "\u{0219}" => 's', "\u{021B}" => 't', "\u{015F}" => 's', "\u{0163}" => 't',
            "\u{0102}" => 'A', "\u{00C2}" => 'A', "\u{00CE}" => 'I', "\u{0218}" => 'S', "\u{021A}" => 'T', "\u{015E}" => 'S', "\u{0162}" => 'T',
        ]);
        $local = trim(preg_replace('/[^a-z0-9]+/', '.', strtolower($ascii)) ?? '', '.');

        return ($local === '' ? 'user' : $local).$this->int(1, 99).'@example.com';
    }

    /**
     * Address: street, number, city (county seat), county, postal code, and full formatted string.
     *
     * @return array{street: string, number: string, city: string, county: string, postalCode: string, full: string}
     */
    public function address(): array
    {
        return $this->pickAddress()[1];
    }

    /**
     * Company: name and valid CUI.
     *
     * @return array{name: string, cui: string}
     */
    public function company(): array
    {
        return [
            'name' => $this->lastName().' '.$this->element(self::COMPANY_WORDS).' '.$this->element(['SRL', 'SRL', 'SRL', 'SA']),
            'cui' => $this->cui(),
        ];
    }

    // Intern

    /**
     * Address and its county code from CNP (for person()).
     *
     * @return array{string, array{street: string, number: string, city: string, county: string, postalCode: string, full: string}}
     */
    private function pickAddress(): array
    {
        $code = $this->element(array_map(strval(...), array_keys(self::COUNTIES)));
        [$county, $city] = self::COUNTIES[$code];
        $street = $this->element(self::STREET_TYPES).' '.$this->element(self::STREETS);
        $number = (string) $this->int(1, 150);
        $postalCode = $this->int(1, 9).$this->digits(5);

        return [$code, [
            'street' => $street,
            'number' => $number,
            'city' => $city,
            'county' => $county,
            'postalCode' => $postalCode,
            'full' => "{$street} nr. {$number}, {$city}, jude\u{021B}ul {$county}, {$postalCode}",
        ]];
    }

    /**
     * xorshift32: 32-bit shift and XOR operations only (no int overflow, identical results on any 64-bit platform).
     */
    private function next(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & self::MASK;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & self::MASK;
        $this->state = $x & self::MASK;

        return $this->state;
    }

    /**
     * @return 'M'|'F'
     */
    private function sexValue(): string
    {
        return $this->bool() ? 'M' : 'F';
    }

    /**
     * @return 'M'|'F'
     *
     * @throws InvalidArgumentException
     */
    private function sex(string $method, string $sex): string
    {
        if ($sex !== 'M' && $sex !== 'F') {
            throw new InvalidArgumentException("Faker::{$method}(): sex must be 'M' or 'F', got '{$sex}'.");
        }

        return $sex;
    }
}
