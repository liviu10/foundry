<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\Flash;
use Foundry\Tests\Support\WebServer;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Mesajele stau in sesiune, deci testele ruleaza prin serverul integrat PHP (tests/fixtures/web-router.php).
 */
final class FlashTest extends TestCase
{
    public function test_requires_session_in_cli_process(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Flash::peek(): the session is not started; start it before using flash messages.');

        Flash::peek();
    }

    public function test_messages_survive_redirect_and_are_shown_once(): void
    {
        $cookie = $this->cookie(WebServer::request('GET', '/flash/add'));

        $peek = $this->json(WebServer::request('GET', '/flash/peek', ['Cookie' => $cookie])['body']);
        $expected = [
            ['type' => 'success', 'title' => null, 'message' => 'Polita a fost salvata.', 'meta' => []],
            ['type' => 'error', 'title' => 'Eroare', 'message' => '<b>Emailul</b> nu a fost trimis.', 'meta' => ['delay' => 10000, 'actiuni' => ['reincearca' => '/x']]],
        ];
        $this->assertSame($expected, $peek['peek']);
        $this->assertTrue($peek['has']);
        $this->assertFalse($peek['has_warning']);
        $this->assertTrue($peek['has_error']);

        $pulled = $this->json(WebServer::request('GET', '/flash/pull', ['Cookie' => $cookie])['body']);
        $this->assertSame($expected, $pulled['pull']);
        $this->assertSame([], $pulled['after']);

        $again = $this->json(WebServer::request('GET', '/flash/pull', ['Cookie' => $cookie])['body']);
        $this->assertSame([], $again['pull']);
    }

    public function test_clear(): void
    {
        $data = $this->json(WebServer::request('GET', '/flash/clear')['body']);

        $this->assertSame([], $data['after']);
    }

    public function test_invalid_usage(): void
    {
        $data = $this->json(WebServer::request('GET', '/flash/invalid')['body']);

        $this->assertSame('LogicException: Flash::add(): the session is not started; start it before using flash messages.', $data['no_session']);
        $this->assertSame("InvalidArgumentException: Flash::add(): unknown type 'danger' (expected success, error, warning, info).", $data['type']);
        $this->assertSame('InvalidArgumentException: Flash::add(): the message is empty or not valid UTF-8.', $data['empty']);
        $this->assertSame('InvalidArgumentException: Flash::add(): the message is empty or not valid UTF-8.', $data['utf8']);
        $this->assertSame('InvalidArgumentException: Flash::add(): the title is empty or not valid UTF-8 (use null for no title).', $data['title']);
        $this->assertSame('InvalidArgumentException: Flash::add(): meta.a.b must be a string, number, bool, null or array, got stdClass.', $data['meta']);
        $this->assertSame([], $data['stored']);
    }

    public function test_corrupt_session_data(): void
    {
        $body = WebServer::request('GET', '/flash/corrupt')['body'];

        $this->assertSame("UnexpectedValueException: Flash::pull(): the flash data in the session ('__flash_messages') is corrupt.", $body);
    }

    /**
     * @param  array{status: int, headers: array<string, list<string>>, body: string}  $response
     */
    private function cookie(array $response): string
    {
        $cookie = explode(';', $response['headers']['set-cookie'][0] ?? '', 2)[0];
        $this->assertNotSame('', $cookie);

        return $cookie;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $body): array
    {
        $data = json_decode($body, true);
        $this->assertIsArray($data, $body);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
