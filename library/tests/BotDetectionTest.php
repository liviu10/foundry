<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\BotDetection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class BotDetectionTest extends TestCase
{
    private const string SECRET = 'secret-de-test-de-cel-putin-32-bytes!!';

    private const int NOW = 1_767_225_600;

    private BotDetection $bot;

    protected function setUp(): void
    {
        $this->bot = new BotDetection(self::SECRET, minSeconds: 3, maxSeconds: 3600);
    }

    public function test_fields(): void
    {
        $html = $this->bot->fields('contact', self::NOW);
        $token = $this->bot->token('contact', self::NOW);

        $this->assertStringContainsString('<input type="text" name="website" value="" tabindex="-1" autocomplete="off">', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('<input type="hidden" name="_form_time" value="'.$token.'">', $html);
        $this->assertMatchesRegularExpression('/^'.self::NOW.'\.[A-Za-z0-9_-]{43}$/', $token);
    }

    public function test_human_submission_passes(): void
    {
        $data = $this->submission('contact', self::NOW);

        $this->assertNull($this->bot->reason($data, 'contact', self::NOW + 10));
        $this->bot->check($data, 'contact', self::NOW + 10);
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int, string}>
     */
    public static function rejectedProvider(): iterable
    {
        $bot = new BotDetection(self::SECRET, minSeconds: 3, maxSeconds: 3600);
        $token = $bot->token('contact', self::NOW);

        yield 'honeypot completat' => [['website' => 'http://spam.ro', '_form_time' => $token], self::NOW + 10, 'honeypot'];
        yield 'honeypot array' => [['website' => ['x'], '_form_time' => $token], self::NOW + 10, 'honeypot'];
        yield 'honeypot spatiu' => [['website' => ' ', '_form_time' => $token], self::NOW + 10, 'honeypot'];
        yield 'fara honeypot' => [['_form_time' => $token], self::NOW + 10, 'missing'];
        yield 'fara timp' => [['website' => ''], self::NOW + 10, 'missing'];
        yield 'prea repede' => [['website' => '', '_form_time' => $token], self::NOW + 2, 'too_fast'];
        yield 'expirat' => [['website' => '', '_form_time' => $token], self::NOW + 3601, 'expired'];
        yield 'timestamp nesemnat' => [['website' => '', '_form_time' => (string) self::NOW], self::NOW + 10, 'invalid'];
        yield 'timp falsificat' => [['website' => '', '_form_time' => (self::NOW - 100).substr($token, 10)], self::NOW + 10, 'invalid'];
        yield 'semnatura alterata' => [['website' => '', '_form_time' => substr($token, 0, -1).'A'], self::NOW + 10, 'invalid'];
        yield 'token array' => [['website' => '', '_form_time' => [$token]], self::NOW + 10, 'invalid'];
        yield 'din viitor' => [['website' => '', '_form_time' => $token], self::NOW - 120, 'invalid'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('rejectedProvider')]
    public function test_rejected(array $data, int $now, string $reason): void
    {
        $this->assertSame($reason, $this->bot->reason($data, 'contact', $now));
    }

    public function test_token_is_bound_to_the_form(): void
    {
        $data = $this->submission('contact', self::NOW);

        $this->assertSame('invalid', $this->bot->reason($data, 'inregistrare', self::NOW + 10));
    }

    public function test_token_is_bound_to_the_secret(): void
    {
        $other = new BotDetection(str_repeat('x', 32));

        $this->assertSame('invalid', $other->reason($this->submission('contact', self::NOW), 'contact', self::NOW + 10));
    }

    public function test_small_clock_skew_is_tolerated_as_too_fast(): void
    {
        $this->assertSame('too_fast', $this->bot->reason($this->submission('contact', self::NOW), 'contact', self::NOW - 30));
    }

    public function test_limits_are_inclusive(): void
    {
        $data = $this->submission('contact', self::NOW);

        $this->assertNull($this->bot->reason($data, 'contact', self::NOW + 3));
        $this->assertNull($this->bot->reason($data, 'contact', self::NOW + 3600));
    }

    public function test_check_throws_with_reason_and_code(): void
    {
        $data = $this->submission('contact', self::NOW);
        $data['website'] = 'spam';

        try {
            $this->bot->check($data, 'contact', self::NOW + 10);
            $this->fail('Expected exception.');
        } catch (UnexpectedValueException $e) {
            $this->assertSame("BotDetection::check(): form 'contact' rejected (honeypot): the honeypot field was filled.", $e->getMessage());
            $this->assertSame(403, $e->getCode());
            $this->assertStringNotContainsString('spam', $e->getMessage());
        }
    }

    public function test_custom_field_names(): void
    {
        $bot = new BotDetection(self::SECRET, minSeconds: 0, honeypot: 'url', timeField: 'ts');
        $data = ['url' => '', 'ts' => $bot->token('f', self::NOW)];

        $this->assertStringContainsString('name="url"', $bot->fields('f', self::NOW));
        $this->assertNull($bot->reason($data, 'f', self::NOW));
    }

    public function test_default_time_is_now(): void
    {
        $bot = new BotDetection(self::SECRET, minSeconds: 0);
        $data = ['website' => '', '_form_time' => $bot->token()];

        $this->assertNull($bot->reason($data));
    }

    /**
     * @return iterable<string, array{callable(): mixed, string}>
     */
    public static function invalidSetupProvider(): iterable
    {
        yield 'secret scurt' => [static fn (): BotDetection => new BotDetection('scurt'), 'the secret must have at least 32 bytes'];
        yield 'min negativ' => [static fn (): BotDetection => new BotDetection(self::SECRET, minSeconds: -1), 'expected 0 <= minSeconds < maxSeconds, got -1 and 7200'];
        yield 'max <= min' => [static fn (): BotDetection => new BotDetection(self::SECRET, minSeconds: 10, maxSeconds: 10), 'expected 0 <= minSeconds < maxSeconds'];
        yield 'honeypot invalid' => [static fn (): BotDetection => new BotDetection(self::SECRET, honeypot: 'a"b'), "invalid field name for honeypot: 'a\"b'"];
        yield 'campuri identice' => [static fn (): BotDetection => new BotDetection(self::SECRET, honeypot: 'x', timeField: 'x'), 'must have different names'];
        yield 'formular invalid' => [static fn (): string => (new BotDetection(self::SECRET))->fields('a b'), "BotDetection::token(): invalid form name 'a b'"];
        yield 'formular invalid la verificare' => [static fn (): ?string => (new BotDetection(self::SECRET))->reason([], ''), "BotDetection::reason(): invalid form name ''"];
    }

    /**
     * @param  callable(): mixed  $callback
     */
    #[DataProvider('invalidSetupProvider')]
    public function test_invalid_setup(callable $callback, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $callback();
    }

    public function test_secret_is_not_exposed_in_trace(): void
    {
        $reflection = new \ReflectionParameter([BotDetection::class, '__construct'], 'secret');

        $this->assertNotEmpty($reflection->getAttributes(\SensitiveParameter::class));
    }

    /**
     * @return array<string, string>
     */
    private function submission(string $form, int $time): array
    {
        return ['website' => '', '_form_time' => $this->bot->token($form, $time), 'nume' => 'Ion'];
    }
}
