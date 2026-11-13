# Mailer

> Native SMTP transport (TLS, proxy support), multipart HTML/plain-text, attachments.

Canonical: https://foundry.insightdevhub.ro/docs/mailer/  
Namespace: `Foundry\Mailer` · File: `Mailer.php` · Version 1.2 · PHP 8.3+ · Extensions: mbstring, fileinfo, openssl

SMTP email delivery without external libraries: implicit TLS / STARTTLS, PLAIN / LOGIN authentication, HTTP proxy (CONNECT), plain text + HTML, attachments, CC/BCC, priority, UTF-8.

Unlike mail(), which merely returns false, any unexpected SMTP response throws an exception containing the command and server reply (e.g., "RCPT TO (recipient #2): 550 5.1.1 User unknown"). Email addresses and usernames never appear in messages: addresses echoed by the server are masked.

## Safety guidelines

- Addresses, headers, and attachments are validated before connecting
- CR/LF in subject, names, or addresses (header injection) -> exception
- Credentials are never sent over an unencrypted connection ('none' + password -> exception)
- Server certificates are verified (verifySsl: false only explicitly, e.g., in local development)
- Error messages never expose passwords or email body contents.

## Encryption modes

- 'starttls' (default, port 587): plaintext connection upgraded to mandatory STARTTLS
- 'tls' (port 465): TLS from the start
- 'none' (port 25, internal relay without authentication).

## Usage

```php
require_once 'Mailer.php';
use Foundry\Mailer;

$mailer = new Mailer(
    host: Env::string('SMTP_HOST'),
    port: Env::int('SMTP_PORT', 587),
    username: Env::string('SMTP_USERNAME'),
    password: Env::string('SMTP_PASSWORD'),
    proxy: Env::nullable('SMTP_PROXY'),            // e.g., 'proxy.company.com:8080'
);

$eml = $mailer->send(
    from: 'noreply@example.com',
    to: ['client@example.com'],
    subject: 'Your insurance policy',
    html: '<p>Hello,</p><p>Please find your policy attached.</p>',   // plain text is generated automatically
    attachments: ['Policy.pdf' => $pdfPath],
    bcc: ['archive@example.com'],
);
File::write($archiveDir.'/message.eml', $eml);                 // sent MIME message (without Bcc)

$preview = Mailer::compose(from: ..., to: ..., subject: ..., text: ...);   // without sending
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Mailer.php';

use Foundry\Mailer;
```

## Methods

### __construct()

```php
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
)
```

Parameters:
- `$host` (`string`, required): The SMTP server.
- `$port` (`int`, default `587`): Port number (587 starttls, 465 tls, 25 none).
- `$username` (`string|null`, default `null`): Username; null = no authentication.
- `$password` (`string|null`, default `null`): Password.
- `$encryption` (`string`, default `'starttls'`): 'starttls', 'tls', or 'none'.
- `$timeout` (`float`, default `30.0`): Maximum timeout per operation in seconds.
- `$proxy` (`string|null`, default `null`): HTTP proxy for CONNECT tunneling as "host:port".
- `$verifySsl` (`bool`, default `true`): Whether to verify the server certificate (default true).
- `$heloName` (`string|null`, default `null`): Name sent with EHLO; null = machine hostname.

Throws:
- `InvalidArgumentException`: On invalid parameters or unsafe configurations.

### send()

```php
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
): string
```

Composes and sends the email.

Parameters:
- `$from` (`string`, required)
- `$to` (`string|list<string>`, required): Recipient(s).
- `$subject` (`string`, required)
- `$text` (`string|null`, default `null`): Plain text body; null = auto-generated from $html.
- `$html` (`string|null`, default `null`): HTML body (optional).
- `$cc` (`string|list<string>`, default `[]`)
- `$bcc` (`string|list<string>`, default `[]`): Blind carbon copy recipient(s) (omitted from headers).
- `$replyTo` (`?string`, default `null`)
- `$attachments` (`array<int|string, string>`, default `[]`): File paths; string key = display filename.
- `$priority` (`int`, default `3`): 1 (highest) to 5 (lowest); 3 = normal.
- `$fromName` (`?string`, default `null`)

Returns `string`: The sent MIME message (.eml), without Bcc header.

Throws:
- `InvalidArgumentException`: On invalid data (addresses, attachments, headers).
- `RuntimeException`: On connection errors or unexpected SMTP responses.

### compose()

```php
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
): string
```

Composes the MIME message without sending it (preview, archiving, testing).

Parameters:
- `$from` (`string`, required)
- `$to` (`string|list<string>`, required)
- `$subject` (`string`, required)
- `$text` (`?string`, default `null`)
- `$html` (`?string`, default `null`)
- `$cc` (`string|list<string>`, default `[]`)
- `$replyTo` (`?string`, default `null`)
- `$attachments` (`array<int|string, string>`, default `[]`)
- `$priority` (`int`, default `3`)
- `$fromName` (`?string`, default `null`)

Returns `string`.

Throws:
- `InvalidArgumentException`: On invalid data.

