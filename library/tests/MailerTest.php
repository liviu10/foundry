<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\File;
use Foundry\Mailer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Testele pornesc serverul SMTP fals din tests/fixtures/smtp-server.php.
 * Criptarea ('starttls'/'tls') necesita certificate reale; aici se testeaza cu 'none'
 * si se verifica separat ca STARTTLS este cerut.
 */
final class MailerTest extends TestCase
{
    private string $dir;

    /** @var list<resource> */
    private array $servers = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/utilities-mailer-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            proc_terminate($server);
            proc_close($server);
        }
        File::deleteDir($this->dir);
    }

    /**
     * Certificat autosemnat (certificat + cheie in acelasi fisier PEM) pentru TLS in serverul fals.
     * Testul este sarit daca OpenSSL nu poate genera certificate pe aceasta masina.
     */
    private function certificate(): string
    {
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $cnf = getenv('OPENSSL_CONF') ?: dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        if (is_file($cnf)) {
            $config['config'] = $cnf;
        }

        $key = openssl_pkey_new($config);
        if ($key === false) {
            $this->markTestSkipped('OpenSSL cannot generate a test key here: '.openssl_error_string());
        }

        $csrKey = $key; // openssl_csr_new() primeste cheia prin referinta
        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $csrKey, $config);
        $cert = $csr instanceof \OpenSSLCertificateSigningRequest ? openssl_csr_sign($csr, null, $key, 1, $config) : false;
        $certPem = '';
        $keyPem = '';

        if ($cert === false || ! openssl_x509_export($cert, $certPem) || ! openssl_pkey_export($key, $keyPem, null, $config)
            || ! is_string($certPem) || ! is_string($keyPem)) {
            $this->markTestSkipped('OpenSSL cannot generate a test certificate here: '.openssl_error_string());
        }

        $path = $this->dir.'/server.pem';
        file_put_contents($path, $certPem.$keyPem);

        return $path;
    }

    /**
     * Porneste serverul fals si returneaza portul.
     */
    private function server(string $options = '', ?string $certificate = null): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open(
            [PHP_BINARY, __DIR__.'/fixtures/smtp-server.php', (string) $port, $this->dir, $options, (string) $certificate],
            [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        $this->servers[] = $process;

        for ($i = 0; $i < 100; $i++) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($connection !== false) {
                fclose($connection);
                // Conexiunea de test a fost #1; asteptam ca serverul sa o inchida.
                usleep(50_000);

                return $port;
            }
            usleep(50_000);
        }

        $this->fail('The fake SMTP server did not start.');
    }

    private function transcript(int $connection = 2): string
    {
        $path = "{$this->dir}/transcript-{$connection}.txt";
        for ($i = 0; $i < 50 && ! is_file($path); $i++) {
            usleep(20_000);
        }

        return (string) file_get_contents($path);
    }

    private function mailer(int $port, string $encryption = 'none', ?string $proxy = null): Mailer
    {
        return new Mailer(host: '127.0.0.1', port: $port, encryption: $encryption, timeout: 5, proxy: $proxy);
    }

    // compose

    public function test_compose_plain_text(): void
    {
        $mime = Mailer::compose(from: 'noreply@example.com', to: 'client@exemplu.ro', subject: 'Test', text: "Buna ziua,\n.linie cu punct");

        $this->assertStringContainsString("From: noreply@example.com\r\n", $mime);
        $this->assertStringContainsString("To: client@exemplu.ro\r\n", $mime);
        $this->assertStringContainsString("Subject: Test\r\n", $mime);
        $this->assertMatchesRegularExpression('/Message-ID: <[0-9a-f]{32}@example\.com>/', $mime);
        $this->assertStringContainsString("X-Priority: 3\r\nImportance: Normal\r\n", $mime);
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $mime);
        $this->assertStringContainsString(chunk_split(base64_encode("Buna ziua,\n.linie cu punct"), 76, "\r\n"), $mime);
        $this->assertStringNotContainsString("\n\n", str_replace("\r\n", '', $mime), 'doar CRLF');
    }

    public function test_compose_utf8_headers(): void
    {
        $mime = Mailer::compose(from: 'a@b.ro', to: 'c@d.ro', subject: "Poli\u{021B}a dumneavoastr\u{0103}", text: 'x', fromName: "Foundry Asigur\u{0103}ri");

        $header = static fn (string $name): string => mb_decode_mimeheader((string) preg_replace('/^.*?\r?\n?'.$name.': (.*?)\r\n(?! ).*$/s', '$1', "\r\n".$mime));

        $this->assertStringContainsString('Subject: =?UTF-8?B?', $mime);
        $this->assertSame("Poli\u{021B}a dumneavoastr\u{0103}", $header('Subject'));
        $this->assertSame("Foundry Asigur\u{0103}ri <a@b.ro>", $header('From'));
    }

    public function test_from_name_cannot_forge_a_second_address(): void
    {
        $mime = Mailer::compose(from: 'noreply@x.ro', to: 'c@d.ro', subject: 's', text: 't', fromName: 'Bank Support <ceo@bank.ro>, "Sef"');

        $this->assertStringContainsString("\r\nFrom: \"Bank Support <ceo@bank.ro>, \\\"Sef\\\"\" <noreply@x.ro>\r\n", "\r\n".$mime);
    }

    public function test_compose_html_with_generated_text_and_attachments(): void
    {
        file_put_contents($this->dir.'/polita.pdf', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n");

        $mime = Mailer::compose(
            from: 'a@b.ro',
            to: ['c@d.ro', 'e@f.ro'],
            subject: 'Polita',
            html: '<p>Buna ziua,<br>Gasiti <b>polita</b> atasata &amp; semnata.</p>',
            cc: 'g@h.ro',
            replyTo: 'suport@b.ro',
            attachments: ["Poli\u{021B}a RCA.pdf" => $this->dir.'/polita.pdf', $this->dir.'/polita.pdf'],
            priority: 1,
        );

        $this->assertStringContainsString("To: c@d.ro, e@f.ro\r\nCc: g@h.ro\r\nReply-To: suport@b.ro\r\n", $mime);
        $this->assertStringContainsString("X-Priority: 1\r\nImportance: High\r\n", $mime);
        $this->assertStringContainsString('multipart/mixed', $mime);
        $this->assertStringContainsString('multipart/alternative', $mime);
        $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $mime);
        $this->assertStringContainsString(chunk_split(base64_encode("Buna ziua,\nGasiti polita atasata & semnata."), 76, "\r\n"), $mime, 'text generat din HTML');
        $this->assertStringContainsString("Content-Disposition: attachment; filename*=UTF-8''Poli%C8%9Ba%20RCA.pdf", $mime);
        $this->assertStringContainsString('Content-Disposition: attachment; filename="polita.pdf"', $mime);
        $this->assertStringContainsString('Content-Type: application/pdf', $mime);
    }

    public function test_compose_validation(): void
    {
        $cases = [
            'adresa invalida' => static fn (): string => Mailer::compose(from: 'nu-e-adresa', to: 'c@d.ro', subject: 's', text: 't'),
            'fara destinatari' => static fn (): string => Mailer::compose(from: 'a@b.ro', to: [], subject: 's', text: 't'),
            'fara continut' => static fn (): string => Mailer::compose(from: 'a@b.ro', to: 'c@d.ro', subject: 's'),
            'header injection subiect' => static fn (): string => Mailer::compose(from: 'a@b.ro', to: 'c@d.ro', subject: "s\r\nBcc: victima@x.ro", text: 't'),
            'header injection nume' => static fn (): string => Mailer::compose(from: 'a@b.ro', to: 'c@d.ro', subject: 's', text: 't', fromName: "x\nBcc: y@z.ro"),
            'header injection adresa' => static fn (): string => Mailer::compose(from: 'a@b.ro', to: "c@d.ro\r\nBcc: y@z.ro", subject: 's', text: 't'),
            'atasament lipsa' => static fn (): string => Mailer::compose(from: 'a@b.ro', to: 'c@d.ro', subject: 's', text: 't', attachments: ['/nu/exista.pdf']),
            'prioritate' => static fn (): string => Mailer::compose(from: 'a@b.ro', to: 'c@d.ro', subject: 's', text: 't', priority: 9),
        ];

        foreach ($cases as $name => $case) {
            try {
                $case();
                $this->fail("Expected InvalidArgumentException: {$name}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // constructor

    public function test_constructor_validation(): void
    {
        $cases = [
            'credentiale fara criptare' => static fn (): Mailer => new Mailer('smtp.x.ro', 25, 'ion', 'parola', 'none'),
            'doar utilizator' => static fn (): Mailer => new Mailer('smtp.x.ro', 587, 'ion'),
            'criptare necunoscuta' => static fn (): Mailer => new Mailer('smtp.x.ro', 587, encryption: 'ssl3'),
            'port' => static fn (): Mailer => new Mailer('smtp.x.ro', 0),
            'host' => static fn (): Mailer => new Mailer(''),
            'proxy' => static fn (): Mailer => new Mailer('smtp.x.ro', proxy: 'http://proxy:8080'),
        ];

        foreach ($cases as $name => $case) {
            try {
                $case();
                $this->fail("Expected InvalidArgumentException: {$name}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // send

    public function test_send_delivers_to_all_recipients_and_hides_bcc(): void
    {
        $port = $this->server('no-auth');

        $eml = $this->mailer($port)->send(
            from: 'noreply@example.com',
            to: ['a@x.ro', 'b@x.ro'],
            subject: 'Test',
            text: "linia 1\n.linie care incepe cu punct",
            cc: 'c@x.ro',
            bcc: ['archive@example.com', 'a@x.ro'],
        );

        $transcript = $this->transcript();
        $this->assertStringContainsString('C: MAIL FROM:<noreply@example.com>', $transcript);
        foreach (['a@x.ro', 'b@x.ro', 'c@x.ro', 'archive@example.com'] as $recipient) {
            $this->assertSame(1, substr_count($transcript, "C: RCPT TO:<{$recipient}>"), $recipient);
        }
        $this->assertStringContainsString('S: 221', $transcript);

        $received = (string) file_get_contents($this->dir.'/message-2.eml');
        $this->assertSame($eml."\r\n", $received);
        $this->assertStringNotContainsString('archive@example.com', $eml, 'Bcc nu apare in mesaj');
    }

    public function test_credentials_are_never_sent_without_starttls(): void
    {
        $port = $this->server();
        $mailer = new Mailer('127.0.0.1', $port, 'ion', 'parola', encryption: 'starttls', timeout: 5);

        // Serverul fals nu anunta STARTTLS: Mailer refuza sa trimita parola necriptat.
        try {
            $mailer->send(from: 'a@b.ro', to: 'c@d.ro', subject: 's', text: 't');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not support STARTTLS', $e->getMessage());
        }

        $this->assertStringNotContainsString('C: AUTH', $this->transcript(), 'parola nu a fost trimisa');
    }

    public function test_starttls_and_auth_plain(): void
    {
        $port = $this->server('starttls', $this->certificate());
        $mailer = new Mailer('127.0.0.1', $port, 'ion', 'parola', 'starttls', timeout: 5, verifySsl: false);

        $mailer->send(from: 'a@b.ro', to: 'c@d.ro', subject: 's', text: 't');

        $transcript = $this->transcript();
        $this->assertStringContainsString('TLS: started', $transcript);
        $this->assertStringContainsString('AUTH: encrypted', $transcript);
        $this->assertStringContainsString('S: 235', $transcript);
        $this->assertStringContainsString('S: 221', $transcript);
    }

    public function test_implicit_tls_and_auth_login(): void
    {
        $port = $this->server('tls,login-only', $this->certificate());
        $mailer = new Mailer('127.0.0.1', $port, 'ion', 'parola', 'tls', timeout: 5, verifySsl: false);

        $mailer->send(from: 'a@b.ro', to: 'c@d.ro', subject: 's', text: 't');

        $transcript = $this->transcript();
        $this->assertStringContainsString('TLS: implicit', $transcript);
        $this->assertStringContainsString('C: AUTH LOGIN', $transcript);
        $this->assertStringContainsString('AUTH: encrypted', $transcript);
        $this->assertStringContainsString('S: 221', $transcript);
    }

    public function test_wrong_password_does_not_leak_it(): void
    {
        $port = $this->server('starttls', $this->certificate());
        $mailer = new Mailer('127.0.0.1', $port, 'ion', 'parola-gresita', 'starttls', timeout: 5, verifySsl: false);

        try {
            $mailer->send(from: 'a@b.ro', to: 'c@d.ro', subject: 's', text: 't');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('authentication failed: unexpected SMTP reply to AUTH PLAIN', $e->getMessage());
            $this->assertStringNotContainsString("'ion'", $e->getMessage(), 'username-ul (de obicei o adresa de email) nu apare in mesaj');
            $this->assertStringContainsString('535', $e->getMessage());
            $this->assertStringNotContainsString('parola-gresita', $e->getMessage());
            $this->assertStringNotContainsString(base64_encode("\0ion\0parola-gresita"), $e->getMessage());
        }
    }

    public function test_self_signed_certificate_is_rejected_when_verification_is_on(): void
    {
        $port = $this->server('starttls', $this->certificate());
        $mailer = new Mailer('127.0.0.1', $port, 'ion', 'parola', 'starttls', timeout: 5);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TLS negotiation with 127.0.0.1 failed');

        $mailer->send(from: 'a@b.ro', to: 'c@d.ro', subject: 's', text: 't');
    }

    public function test_recipient_rejected(): void
    {
        $port = $this->server('no-auth');

        try {
            $this->mailer($port)->send(from: 'a@b.ro', to: ['ok@x.ro', 'unknown@x.ro'], subject: 's', text: 't');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('Mailer: unexpected SMTP reply to RCPT TO (recipient #2): 550 5.1.1 <[address]>: User unknown', $e->getMessage());
            $this->assertStringNotContainsString('unknown@x.ro', $e->getMessage(), 'adresele de email nu apar in mesaje');
        }
    }

    public function test_send_through_http_proxy(): void
    {
        $port = $this->server('proxy,no-auth');

        $this->mailer(25, proxy: "127.0.0.1:{$port}")->send(from: 'a@b.ro', to: 'c@d.ro', subject: 's', text: 't');

        $transcript = $this->transcript();
        $this->assertStringContainsString('C: CONNECT 127.0.0.1:25 HTTP/1.1', $transcript);
        $this->assertStringContainsString('PROXY: tunnel established', $transcript);
        $this->assertStringContainsString('S: 221', $transcript);
    }

    public function test_connection_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot connect to 127.0.0.1:1');

        (new Mailer('127.0.0.1', 1, encryption: 'none', timeout: 1))->send(from: 'a@b.ro', to: 'c@d.ro', subject: 's', text: 't');
    }

    public function test_send_validates_before_connecting(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Mailer('127.0.0.1', 1, encryption: 'none', timeout: 1))->send(from: 'gresit', to: 'c@d.ro', subject: 's', text: 't');
    }
}
