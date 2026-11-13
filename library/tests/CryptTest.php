<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\Crypt;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class CryptTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        $this->key = Crypt::generateKey();
    }

    public function test_generate_key_format(): void
    {
        $this->assertMatchesRegularExpression('#^base64:[A-Za-z0-9+/]{43}=$#', $this->key);
        $this->assertNotSame($this->key, Crypt::generateKey());
    }

    public function test_payload_with_a_different_spelling_is_rejected(): void
    {
        // 1 + 12 + 16 + 2 = 31 de octeti: ultimul caracter base64url are 4 biti nefolositi
        $encrypted = Crypt::encrypt('ab', $this->key);
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $last = strpos($alphabet, $encrypted[-1]);
        $this->assertIsInt($last);
        $variant = substr($encrypted, 0, -1).$alphabet[$last ^ 1];
        $this->assertSame(base64_decode(strtr($encrypted, '-_', '+/'), true), base64_decode(strtr($variant, '-_', '+/'), true));

        $this->expectException(UnexpectedValueException::class);

        Crypt::decrypt($variant, $this->key);
    }

    public function test_round_trip(): void
    {
        foreach (['', 'RO49AAAA1B31007593840000', "Ia\u{0219}i", random_bytes(1000)] as $plaintext) {
            $this->assertSame($plaintext, Crypt::decrypt(Crypt::encrypt($plaintext, $this->key), $this->key));
        }
    }

    public function test_output_is_url_safe_and_randomized(): void
    {
        $first = Crypt::encrypt('acelasi text', $this->key);
        $second = Crypt::encrypt('acelasi text', $this->key);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $first);
        $this->assertNotSame($first, $second, 'IV aleator: acelasi text produce rezultate diferite');
    }

    public function test_key_without_prefix_is_accepted(): void
    {
        $encrypted = Crypt::encrypt('x', $this->key);

        $this->assertSame('x', Crypt::decrypt($encrypted, substr($this->key, 7)));
    }

    public function test_context_must_match(): void
    {
        $encrypted = Crypt::encrypt('RO49AAAA1B31007593840000', $this->key, context: 'client.iban');

        $this->assertSame('RO49AAAA1B31007593840000', Crypt::decrypt($encrypted, $this->key, 'client.iban'));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('decryption failed');

        Crypt::decrypt($encrypted, $this->key, 'client.cnp');
    }

    public function test_wrong_key_fails(): void
    {
        $encrypted = Crypt::encrypt('secret', $this->key);

        $this->expectException(UnexpectedValueException::class);

        Crypt::decrypt($encrypted, Crypt::generateKey());
    }

    public function test_tampering_is_detected(): void
    {
        $encrypted = Crypt::encrypt('suma=100', $this->key);
        $raw = (string) base64_decode(strtr($encrypted, '-_', '+/'), true);

        foreach ([strlen($raw) - 1, 1, 1 + 12] as $position) { // text criptat, IV, tag
            $tampered = $raw;
            $tampered[$position] = chr(ord($tampered[$position]) ^ 1);

            try {
                Crypt::decrypt(rtrim(strtr(base64_encode($tampered), '+/', '-_'), '='), $this->key);
                $this->fail('Expected UnexpectedValueException');
            } catch (UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_key_rotation(): void
    {
        $oldKey = Crypt::generateKey();
        $encrypted = Crypt::encrypt('date vechi', $oldKey);

        $this->assertSame('date vechi', Crypt::decrypt($encrypted, [$this->key, $oldKey]));

        $this->expectException(UnexpectedValueException::class);

        Crypt::decrypt($encrypted, [$this->key]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPayloadProvider(): iterable
    {
        yield 'gol' => [''];
        yield 'text oarecare' => ['nu este criptat'];
        yield 'prea scurt' => ['AQID'];
        yield 'versiune necunoscuta' => [rtrim(strtr(base64_encode("\x02".str_repeat('a', 40)), '+/', '-_'), '=')];
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payloads(string $payload): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('not valid encrypted data');

        Crypt::decrypt($payload, $this->key);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeyProvider(): iterable
    {
        yield 'gol' => [''];
        yield 'text simplu' => ['aegis-secret-key-32-chars-length!'];
        yield 'prea scurta' => ['base64:'.base64_encode(random_bytes(16))];
        yield 'prea lunga' => ['base64:'.base64_encode(random_bytes(64))];
    }

    #[DataProvider('invalidKeyProvider')]
    public function test_invalid_keys(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('use Crypt::generateKey()');

        Crypt::encrypt('x', $key);
    }

    public function test_decrypt_requires_at_least_one_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Crypt::decrypt(Crypt::encrypt('x', $this->key), []);
    }

    public function test_hmac(): void
    {
        $secret = str_repeat('s', 32);
        $mac = Crypt::hmac('payload', $secret);

        $this->assertSame(hash_hmac('sha256', 'payload', $secret), $mac);
        $this->assertTrue(Crypt::verifyHmac('payload', $mac, $secret));
        $this->assertTrue(Crypt::verifyHmac('payload', strtoupper($mac), $secret));
        $this->assertFalse(Crypt::verifyHmac('payload modificat', $mac, $secret));
        $this->assertFalse(Crypt::verifyHmac('payload', 'abc', $secret));
    }

    public function test_hmac_rejects_short_secret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 32 bytes');

        Crypt::hmac('x', 'scurt');
    }
}
