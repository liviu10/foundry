<?php

declare(strict_types=1);

namespace Foundry;

use InvalidArgumentException;
use SensitiveParameter;
use UnexpectedValueException;

/**
 * BotDetection Class
 *
 * Protection for public forms (contact, registration, password reset) against automated submissions:
 *   - honeypot: a hidden field that a human does not see or fill out, but a bot does;
 *   - minimum submission time: a human cannot fill and submit a form in less than a few seconds;
 *   - maximum time: a page left open for too long (or a saved and reused token) is rejected.
 * The render timestamp is HMAC-signed and bound to the form name, preventing tampering
 * (unlike a plain timestamp submitted in a hidden input).
 *
 * Rules:
 *   - secret must be at least 32 bytes; there is no default secret;
 *   - check() throws an UnexpectedValueException with code 403; reason() returns only the reason string (for logging);
 *   - stable reasons: honeypot, missing, invalid, too_fast, expired;
 *   - request rate limiting per IP should be handled using RateLimiter.
 *
 * Usage:
 *   require_once 'BotDetection.php';
 *   use Foundry\BotDetection;
 *
 *   $bot = new BotDetection($_ENV['BOT_SECRET'], minSeconds: 3);
 *
 *   <form method="post"><?= $bot->fields('contact') ?> ... </form>
 *
 *   $bot->check($_POST, 'contact');              // throws an exception (code 403) on automated submissions
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
final readonly class BotDetection
{
    private const int MIN_SECRET_LENGTH = 32;

    /** @var int Allowed seconds for a render timestamp in the future (to account for clock skew between servers). */
    private const int CLOCK_SKEW = 60;

    private const array MESSAGES = [
        'honeypot' => 'the honeypot field was filled',
        'missing' => 'the bot detection fields are missing',
        'invalid' => 'the form token is invalid',
        'too_fast' => 'the form was submitted too fast',
        'expired' => 'the form token has expired',
    ];

    /**
     * @param  string  $secret  Minimum 32 bytes (e.g. from .env).
     * @param  int  $minSeconds  Minimum elapsed time in seconds between form rendering and submission.
     * @param  int  $maxSeconds  Maximum elapsed time in seconds between form rendering and submission.
     * @param  string  $honeypot  Name of the honeypot field (a common field name attracts bots: website, url, phone2).
     * @param  string  $timeField  Name of the hidden field containing the signed timestamp.
     *
     * @throws InvalidArgumentException If the secret is too short or configuration settings are invalid.
     */
    public function __construct(
        #[SensitiveParameter]
        private string $secret,
        private int $minSeconds = 3,
        private int $maxSeconds = 7200,
        private string $honeypot = 'website',
        private string $timeField = '_form_time',
    ) {
        if (strlen($secret) < self::MIN_SECRET_LENGTH) {
            throw new InvalidArgumentException('BotDetection: the secret must have at least '.self::MIN_SECRET_LENGTH.' bytes.');
        }

        if ($minSeconds < 0 || $maxSeconds <= $minSeconds) {
            throw new InvalidArgumentException("BotDetection: expected 0 <= minSeconds < maxSeconds, got {$minSeconds} and {$maxSeconds}.");
        }

        foreach (['honeypot' => $honeypot, 'timeField' => $timeField] as $option => $name) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/', $name) !== 1) {
                throw new InvalidArgumentException("BotDetection: invalid field name for {$option}: '{$name}'.");
            }
        }

        if ($honeypot === $timeField) {
            throw new InvalidArgumentException('BotDetection: the honeypot and the time field must have different names.');
        }
    }

    /**
     * Generates form fields: the visually hidden honeypot (not type="hidden", which bots recognize)
     * and the signed render timestamp.
     *
     * @throws InvalidArgumentException If the form name is invalid.
     */
    public function fields(string $form = 'default', ?int $now = null): string
    {
        $token = $this->token($form, $now);

        return '<div style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden" aria-hidden="true">'
            .'<label>Website <input type="text" name="'.$this->honeypot.'" value="" tabindex="-1" autocomplete="off"></label>'
            .'</div>'
            .'<input type="hidden" name="'.$this->timeField.'" value="'.$token.'">';
    }

    /**
     * Returns the signed render timestamp ('1767225600.signature') for client-rendered JavaScript forms.
     *
     * @throws InvalidArgumentException If the form name is invalid.
     */
    public function token(string $form = 'default', ?int $now = null): string
    {
        $this->assertForm('token', $form);
        $time = (string) ($now ?? time());

        return $time.'.'.$this->sign($form, $time);
    }

    /**
     * Returns the reason why the submission appears automated, or null if it appears human.
     *
     * @param  array<array-key, mixed>  $data  Form submission data ($_POST).
     * @return 'honeypot'|'missing'|'invalid'|'too_fast'|'expired'|null
     *
     * @throws InvalidArgumentException If the form name is invalid.
     */
    public function reason(array $data, string $form = 'default', ?int $now = null): ?string
    {
        $this->assertForm('reason', $form);
        $now ??= time();

        if (! array_key_exists($this->honeypot, $data) || ! array_key_exists($this->timeField, $data)) {
            return 'missing';
        }

        if ($data[$this->honeypot] !== '') {
            return 'honeypot';
        }

        $token = $data[$this->timeField];
        if (! is_string($token) || preg_match('/^(\d{1,12})\.([A-Za-z0-9_-]{43})$/', $token, $match) !== 1
            || ! hash_equals($this->sign($form, $match[1]), $match[2])) {
            return 'invalid';
        }

        $elapsed = $now - (int) $match[1];
        if ($elapsed < -self::CLOCK_SKEW) {
            return 'invalid';
        }

        if ($elapsed < $this->minSeconds) {
            return 'too_fast';
        }

        return $elapsed > $this->maxSeconds ? 'expired' : null;
    }

    /**
     * Throws an exception if the submission appears automated.
     *
     * @param  array<array-key, mixed>  $data  Form submission data ($_POST).
     *
     * @throws UnexpectedValueException With code 403 and the failure reason in the message.
     * @throws InvalidArgumentException If the form name is invalid.
     */
    public function check(array $data, string $form = 'default', ?int $now = null): void
    {
        $reason = $this->reason($data, $form, $now);
        if ($reason !== null) {
            throw new UnexpectedValueException("BotDetection::check(): form '{$form}' rejected ({$reason}): ".self::MESSAGES[$reason].'.', 403);
        }
    }

    private function sign(string $form, string $time): string
    {
        $mac = hash_hmac('sha256', "bot-detection|{$form}|{$time}", $this->secret, true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertForm(string $method, string $form): void
    {
        if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $form) !== 1) {
            throw new InvalidArgumentException("BotDetection::{$method}(): invalid form name '{$form}'.");
        }
    }
}
