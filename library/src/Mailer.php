<?php

declare(strict_types=1);

namespace Foundry;

use Closure;
use finfo;
use InvalidArgumentException;
use RuntimeException;

/**
 * Mailer Class
 *
 * SMTP email delivery without external libraries: implicit TLS / STARTTLS, PLAIN / LOGIN
 * authentication, HTTP proxy (CONNECT), plain text + HTML, attachments, CC/BCC, priority, UTF-8.
 *
 * Unlike mail(), which merely returns false, any unexpected SMTP response throws an exception
 * containing the command and server reply (e.g., "RCPT TO (recipient #2): 550 5.1.1 User unknown").
 * Email addresses and usernames never appear in messages: addresses echoed by the server are masked.
 *
 * Safety guidelines:
 *   - Addresses, headers, and attachments are validated before connecting;
 *   - CR/LF in subject, names, or addresses (header injection) -> exception;
 *   - Credentials are never sent over an unencrypted connection ('none' + password -> exception);
 *   - Server certificates are verified (verifySsl: false only explicitly, e.g., in local development);
 *   - Error messages never expose passwords or email body contents.
 *
 * Encryption modes:
 *   - 'starttls' (default, port 587): plaintext connection upgraded to mandatory STARTTLS;
 *   - 'tls' (port 465): TLS from the start;
 *   - 'none' (port 25, internal relay without authentication).
 *
 * Usage:
 *   require_once 'Mailer.php';
 *   use Foundry\Mailer;
 *
 *   $mailer = new Mailer(
 *       host: Env::string('SMTP_HOST'),
 *       port: Env::int('SMTP_PORT', 587),
 *       username: Env::string('SMTP_USERNAME'),
 *       password: Env::string('SMTP_PASSWORD'),
 *       proxy: Env::nullable('SMTP_PROXY'),            // e.g., 'proxy.company.com:8080'
 *   );
 *
 *   $eml = $mailer->send(
 *       from: 'noreply@example.com',
 *       to: ['client@example.com'],
 *       subject: 'Your insurance policy',
 *       html: '<p>Hello,</p><p>Please find your policy attached.</p>',   // plain text is generated automatically
 *       attachments: ['Policy.pdf' => $pdfPath],
 *       bcc: ['archive@example.com'],
 *   );
 *   File::write($archiveDir.'/message.eml', $eml);                 // sent MIME message (without Bcc)
 *
 *   $preview = Mailer::compose(from: ..., to: ..., subject: ..., text: ...);   // without sending
 *
 * @category Utilities
 *
 * @version  1.2
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class Mailer
{
    /** @var list<string> Accepted encryption types. */
    private const array ENCRYPTIONS = ['starttls', 'tls', 'none'];

    /** @var array<int, string> Priority => Importance header value. */
    private const array IMPORTANCE = [1 => 'High', 2 => 'High', 3 => 'Normal', 4 => 'Low', 5 => 'Low'];

    /** @var resource|null */
    private $socket;

    /**
     * @param  string  $host  The SMTP server.
     * @param  int  $port  Port number (587 starttls, 465 tls, 25 none).
     * @param  string|null  $username  Username; null = no authentication.
     * @param  string|null  $password  Password.
     * @param  string  $encryption  'starttls', 'tls', or 'none'.
     * @param  float  $timeout  Maximum timeout per operation in seconds.
     * @param  string|null  $proxy  HTTP proxy for CONNECT tunneling as "host:port".
     * @param  bool  $verifySsl  Whether to verify the server certificate (default true).
     * @param  string|null  $heloName  Name sent with EHLO; null = machine hostname.
     *
     * @throws InvalidArgumentException On invalid parameters or unsafe configurations.
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port = 587,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly string $encryption = 'starttls',
        private readonly float $timeout = 30.0,
        private readonly ?string $proxy = null,
        private readonly bool $verifySsl = true,
        private readonly ?string $heloName = null,
    ) {
        if (trim($host) === '' || preg_match('/[\s\/]/', $host) === 1) {
            throw new InvalidArgumentException('Mailer: invalid SMTP host.');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Mailer: invalid SMTP port.');
        }

        if (! in_array($encryption, self::ENCRYPTIONS, true)) {
            throw new InvalidArgumentException('Mailer: encryption must be one of: '.implode(', ', self::ENCRYPTIONS).'.');
        }

        if (($username === null) !== ($password === null)) {
            throw new InvalidArgumentException('Mailer: username and password must be given together.');
        }

        if ($username !== null && $encryption === 'none') {
            throw new InvalidArgumentException("Mailer: refusing to send credentials over an unencrypted connection; use 'starttls' or 'tls'.");
        }

        if ($timeout <= 0) {
            throw new InvalidArgumentException('Mailer: timeout must be greater than 0.');
        }

        if ($proxy !== null && preg_match('/^[\w.-]+:\d{1,5}$/', $proxy) !== 1) {
            throw new InvalidArgumentException("Mailer: proxy must be 'host:port'.");
        }
    }

    /**
     * Composes and sends the email.
     *
     * @param  string|list<string>  $to  Recipient(s).
     * @param  string|null  $text  Plain text body; null = auto-generated from $html.
     * @param  string|null  $html  HTML body (optional).
     * @param  string|list<string>  $cc
     * @param  string|list<string>  $bcc  Blind carbon copy recipient(s) (omitted from headers).
     * @param  array<int|string, string>  $attachments  File paths; string key = display filename.
     * @param  int  $priority  1 (highest) to 5 (lowest); 3 = normal.
     * @return string The sent MIME message (.eml), without Bcc header.
     *
     * @throws InvalidArgumentException On invalid data (addresses, attachments, headers).
     * @throws RuntimeException On connection errors or unexpected SMTP responses.
     */
    public function send(
        string $from,
        string|array $to,
        string $subject,
        ?string $text = null,
        ?string $html = null,
        string|array $cc = [],
        string|array $bcc = [],
        ?string $replyTo = null,
        array $attachments = [],
        int $priority = 3,
        ?string $fromName = null,
    ): string {
        $mime = self::compose($from, $to, $subject, $text, $html, $cc, $replyTo, $attachments, $priority, $fromName);
        $recipients = array_values(array_unique([...self::addresses('to', $to), ...self::addresses('cc', $cc), ...self::addresses('bcc', $bcc)]));

        try {
            $this->connect();
            $this->transmit($from, $recipients, $mime);
        } finally {
            $this->disconnect();
        }

        return $mime;
    }

    /**
     * Composes the MIME message without sending it (preview, archiving, testing).
     *
     * @param  string|list<string>  $to
     * @param  string|list<string>  $cc
     * @param  array<int|string, string>  $attachments
     *
     * @throws InvalidArgumentException On invalid data.
     */
    public static function compose(
        string $from,
        string|array $to,
        string $subject,
        ?string $text = null,
        ?string $html = null,
        string|array $cc = [],
        ?string $replyTo = null,
        array $attachments = [],
        int $priority = 3,
        ?string $fromName = null,
    ): string {
        $from = self::address('from', $from);
        $toList = self::addresses('to', $to);
        $ccList = self::addresses('cc', $cc);

        if ($toList === []) {
            throw new InvalidArgumentException('Mailer: at least one recipient (to) is required.');
        }

        if ($text === null && $html === null) {
            throw new InvalidArgumentException('Mailer: text or html content is required.');
        }

        if (! isset(self::IMPORTANCE[$priority])) {
            throw new InvalidArgumentException('Mailer: priority must be between 1 and 5.');
        }

        self::assertHeaderValue('subject', $subject);
        if ($fromName !== null) {
            self::assertHeaderValue('fromName', $fromName);
        }

        $files = self::attachments($attachments);
        $text ??= self::htmlToText((string) $html);
        $domain = substr((string) strrchr($from, '@'), 1);

        $headers = [
            'Date' => date('r'),
            'From' => $fromName === null ? $from : self::displayName($fromName).' <'.$from.'>',
            'To' => implode(', ', $toList),
        ];
        if ($ccList !== []) {
            $headers['Cc'] = implode(', ', $ccList);
        }
        if ($replyTo !== null) {
            $headers['Reply-To'] = self::address('replyTo', $replyTo);
        }
        $headers += [
            'Subject' => self::encodeHeader($subject),
            'Message-ID' => '<'.bin2hex(random_bytes(16)).'@'.$domain.'>',
            'MIME-Version' => '1.0',
            'X-Priority' => (string) $priority,
            'Importance' => self::IMPORTANCE[$priority],
        ];

        $body = self::textPart('text/plain', $text);
        if ($html !== null) {
            $body = self::multipart('alternative', [$body, self::textPart('text/html', $html)]);
        }
        if ($files !== []) {
            $parts = [$body];
            foreach ($files as $name => $path) {
                $parts[] = self::attachmentPart($name, $path);
            }
            $body = self::multipart('mixed', $parts);
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }

        return implode("\r\n", $lines)."\r\n".$body;
    }

    // SMTP

    /**
     * @throws RuntimeException If connection, proxy tunnel, or TLS negotiation fails.
     */
    private function connect(): void
    {
        $target = $this->proxy ?? "{$this->host}:{$this->port}";
        $context = stream_context_create(['ssl' => [
            'verify_peer' => $this->verifySsl,
            'verify_peer_name' => $this->verifySsl,
            'peer_name' => $this->host,
            'SNI_enabled' => true,
        ]]);

        $errno = 0;
        $error = '';
        [$socket, $warning] = $this->capture(function () use ($target, $context, &$errno, &$error) {
            return stream_socket_client('tcp://'.$target, $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        });

        if (! is_resource($socket)) {
            $reason = $error !== '' ? $error : ($warning ?? 'unknown error');

            throw new RuntimeException("Mailer: cannot connect to {$target}: {$reason}.");
        }

        $this->socket = $socket;
        stream_set_timeout($socket, (int) ceil($this->timeout));

        if ($this->proxy !== null) {
            $this->write("CONNECT {$this->host}:{$this->port} HTTP/1.1\r\nHost: {$this->host}:{$this->port}\r\n\r\n");
            $status = $this->readLine('proxy CONNECT');
            while (($line = $this->readLine('proxy CONNECT')) !== '') {
                // antetele raspunsului proxy sunt ignorate
            }
            if (preg_match('#^HTTP/1\.[01] 200\b#', $status) !== 1) {
                throw new RuntimeException("Mailer: proxy {$this->proxy} refused the tunnel: {$status}.");
            }
        }

        if ($this->encryption === 'tls') {
            $this->enableTls();
        }
    }

    /**
     * @param  list<string>  $recipients
     *
     * @throws RuntimeException On unexpected SMTP responses.
     */
    private function transmit(string $from, array $recipients, string $mime): void
    {
        $this->expect('greeting', [220]);
        $capabilities = $this->ehlo();

        if ($this->encryption === 'starttls') {
            if (! isset($capabilities['STARTTLS'])) {
                throw new RuntimeException("Mailer: the server {$this->host} does not support STARTTLS.");
            }
            $this->command('STARTTLS', [220]);
            $this->enableTls();
            $capabilities = $this->ehlo();
        }

        if ($this->username !== null && $this->password !== null) {
            $this->authenticate($capabilities['AUTH'] ?? '', $this->username, $this->password);
        }

        // Labels without the address: email addresses are personal data and stay out of exception messages.
        $this->command("MAIL FROM:<{$from}>", [250], 'MAIL FROM');
        foreach ($recipients as $index => $recipient) {
            $this->command("RCPT TO:<{$recipient}>", [250, 251], 'RCPT TO (recipient #'.($index + 1).')');
        }
        $this->command('DATA', [354]);

        // Dot-stuffing: o linie care incepe cu "." este trimisa cu ".." (RFC 5321).
        $data = (string) preg_replace('/^\./m', '..', $mime);
        $this->write($data."\r\n.\r\n");
        $this->expect('end of DATA', [250]);

        $this->command('QUIT', [221]);
    }

    /**
     * @return array<string, string> Server capabilities (e.g., ['STARTTLS' => '', 'AUTH' => 'PLAIN LOGIN']).
     */
    private function ehlo(): array
    {
        $lines = $this->command('EHLO '.($this->heloName ?? (gethostname() ?: 'localhost')), [250]);

        $capabilities = [];
        foreach (array_slice($lines, 1) as $line) {
            $parts = explode(' ', strtoupper(trim($line)), 2);
            $capabilities[$parts[0]] = $parts[1] ?? '';
        }

        return $capabilities;
    }

    /**
     * @throws RuntimeException If authentication fails or no common auth mechanism exists.
     */
    private function authenticate(string $methods, string $username, string $password): void
    {
        $methods = explode(' ', $methods);

        try {
            if (in_array('PLAIN', $methods, true)) {
                $this->command('AUTH PLAIN '.base64_encode("\0{$username}\0{$password}"), [235], 'AUTH PLAIN');
            } elseif (in_array('LOGIN', $methods, true)) {
                $this->command('AUTH LOGIN', [334]);
                $this->command(base64_encode($username), [334], 'AUTH LOGIN username');
                $this->command(base64_encode($password), [235], 'AUTH LOGIN password');
            } else {
                throw new RuntimeException("Mailer: the server {$this->host} supports no authentication method known by Mailer (PLAIN, LOGIN).");
            }
        } catch (RuntimeException $e) {
            throw new RuntimeException('Mailer: authentication failed: '.preg_replace('/^Mailer: /', '', $e->getMessage()), 0, $e);
        }
    }

    /**
     * Sends a command and validates the response status code.
     *
     * @param  list<int>  $expected  Accepted response codes.
     * @param  string|null  $label  Label used in error messages (defaults to command; used to conceal passwords).
     * @return list<string> Response lines.
     */
    private function command(string $command, array $expected, ?string $label = null): array
    {
        $this->write($command."\r\n");

        return $this->expect($label ?? $command, $expected);
    }

    /**
     * Reads an SMTP response (including multi-line responses: "250-...", "250 ...").
     *
     * @param  list<int>  $expected
     * @return list<string> Response lines without status codes.
     *
     * @throws RuntimeException If the status code is unexpected.
     */
    private function expect(string $label, array $expected): array
    {
        $lines = [];
        do {
            $line = $this->readLine($label);
            $code = (int) substr($line, 0, 3);
            $lines[] = substr($line, 4);
        } while (($line[3] ?? ' ') === '-');

        if (! in_array($code, $expected, true)) {
            // Servers often echo the address back ("550 5.1.1 <ion@example.com>: user unknown"): mask it.
            $reply = (string) preg_replace('/[^\s<>"\'@]+@[^\s<>"\']+/', '[address]', trim(implode(' ', $lines)));
            $reply = mb_strimwidth($reply, 0, 200, '...');

            throw new RuntimeException("Mailer: unexpected SMTP reply to {$label}: {$code} {$reply}");
        }

        return $lines;
    }

    /**
     * @throws RuntimeException On closed connection or timeout.
     */
    private function readLine(string $label): string
    {
        $socket = $this->requireSocket();
        $line = fgets($socket, 4096);

        if ($line === false) {
            $timedOut = stream_get_meta_data($socket)['timed_out'];

            throw new RuntimeException('Mailer: '.($timedOut ? 'timeout' : 'connection closed').' while waiting for the reply to '.$label.'.');
        }

        return rtrim($line, "\r\n");
    }

    /**
     * @throws RuntimeException If writing fails.
     */
    private function write(string $data): void
    {
        $socket = $this->requireSocket();
        $total = strlen($data);
        $offset = 0;

        while ($offset < $total) {
            [$written] = $this->capture(static fn (): int|false => fwrite($socket, substr($data, $offset)));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Mailer: connection lost while sending data.');
            }
            $offset += $written;
        }
    }

    /**
     * @throws RuntimeException If TLS negotiation fails.
     */
    private function enableTls(): void
    {
        $socket = $this->requireSocket();

        [$enabled, $warning] = $this->capture(static fn (): bool|int => stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT));

        if ($enabled !== true) {
            throw new RuntimeException("Mailer: TLS negotiation with {$this->host} failed".($warning === null ? '.' : ': '.$warning.'.'));
        }
    }

    /**
     * @return resource
     */
    private function requireSocket()
    {
        if (! is_resource($this->socket)) {
            throw new RuntimeException('Mailer: not connected.');
        }

        return $this->socket;
    }

    private function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    // MIME

    /**
     * @param  string|list<string>  $addresses
     * @return list<string>
     */
    private static function addresses(string $field, string|array $addresses): array
    {
        $list = is_string($addresses) ? ($addresses === '' ? [] : [$addresses]) : $addresses;

        return array_map(static fn (string $address): string => self::address($field, $address), $list);
    }

    /**
     * @throws InvalidArgumentException If the email address is invalid.
     */
    private static function address(string $field, string $address): string
    {
        $address = trim($address);

        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n<>]/', $address) === 1) {
            throw new InvalidArgumentException("Mailer: invalid email address in '{$field}'.");
        }

        return $address;
    }

    /**
     * @throws InvalidArgumentException If the value contains CR or LF characters.
     */
    private static function assertHeaderValue(string $field, string $value): void
    {
        if (preg_match('/[\r\n]/', $value) === 1) {
            throw new InvalidArgumentException("Mailer: '{$field}' cannot contain line breaks.");
        }
    }

    /**
     * @param  array<int|string, string>  $attachments
     * @return array<string, string> Display filename => file path.
     *
     * @throws InvalidArgumentException If a file does not exist or the filename is invalid.
     */
    private static function attachments(array $attachments): array
    {
        $files = [];
        foreach ($attachments as $name => $path) {
            if (! is_file($path) || ! is_readable($path)) {
                throw new InvalidArgumentException("Mailer: attachment '{$path}' does not exist or is not readable.");
            }

            $name = is_string($name) ? $name : basename($path);
            self::assertHeaderValue('attachment name', $name);
            if (trim($name) === '' || str_contains($name, '"')) {
                throw new InvalidArgumentException("Mailer: invalid attachment name '{$name}'.");
            }

            $files[$name] = $path;
        }

        return $files;
    }

    private static function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value) === 1
            ? mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n")
            : $value;
    }

    /**
     * Display name of an address. Always a quoted-string (RFC 5322) or an encoded-word: a plain name such as
     * 'Support <ceo@bank.ro>,' would otherwise turn the From header into a list showing a forged address.
     */
    private static function displayName(string $name): string
    {
        return preg_match('/[^\x20-\x7E]/', $name) === 1
            ? mb_encode_mimeheader($name, 'UTF-8', 'B', "\r\n")
            : '"'.addcslashes($name, '"\\').'"';
    }

    private static function textPart(string $type, string $content): string
    {
        return "Content-Type: {$type}; charset=UTF-8\r\n"
            ."Content-Transfer-Encoding: base64\r\n\r\n"
            .chunk_split(base64_encode($content), 76, "\r\n");
    }

    /**
     * @throws InvalidArgumentException If the file cannot be read.
     */
    private static function attachmentPart(string $name, string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new InvalidArgumentException("Mailer: cannot read attachment '{$path}'.");
        }

        $type = class_exists(finfo::class) ? ((new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream') : 'application/octet-stream';
        $ascii = preg_match('/^[\x20-\x7E]+$/', $name) === 1;
        $disposition = $ascii
            ? 'attachment; filename="'.$name.'"'
            : "attachment; filename*=UTF-8''".rawurlencode($name); // RFC 2231

        return "Content-Type: {$type}; name=\"".($ascii ? $name : self::encodeHeader($name))."\"\r\n"
            ."Content-Transfer-Encoding: base64\r\n"
            ."Content-Disposition: {$disposition}\r\n\r\n"
            .chunk_split(base64_encode($content), 76, "\r\n");
    }

    /**
     * @param  list<string>  $parts  Parts, each with its own headers.
     */
    private static function multipart(string $subtype, array $parts): string
    {
        $boundary = '=_'.bin2hex(random_bytes(12));
        $body = "Content-Type: multipart/{$subtype}; boundary=\"{$boundary}\"\r\n\r\n";

        foreach ($parts as $part) {
            $body .= "--{$boundary}\r\n{$part}\r\n";
        }

        return $body."--{$boundary}--\r\n";
    }

    /**
     * Converts HTML to plain text: converts <br> and block tags to newlines, strips remaining tags.
     */
    private static function htmlToText(string $html): string
    {
        $text = (string) preg_replace(['#<br\s*/?>#i', '#</(p|div|h[1-6]|li|tr)>#i'], ["\n", "\n"], $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /**
     * Executes a native function and captures any emitted warning instead of displaying it.
     *
     * @template T
     *
     * @param  Closure(): T  $operation  The operation to execute.
     * @return array{0: T, 1: string|null} [result, warning message or null]
     */
    private function capture(Closure $operation): array
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            return [$operation(), $warning];
        } finally {
            restore_error_handler();
        }
    }
}
