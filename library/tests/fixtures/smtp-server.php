<?php

declare(strict_types=1);

/*
 * Server SMTP fals pentru MailerTest.
 *
 *   php smtp-server.php <port> <director> [optiuni separate prin virgula] [certificat.pem]
 *
 * Optiuni:
 *   proxy       fiecare conexiune incepe cu "CONNECT host:port HTTP/1.1" (simuleaza un proxy HTTP)
 *   starttls    anunta si accepta STARTTLS (necesita certificat.pem)
 *   tls         TLS implicit de la conectare (necesita certificat.pem)
 *   login-only  anunta doar AUTH LOGIN
 *   no-auth     nu anunta AUTH
 *
 * Utilizator acceptat: ion / parola. Destinatarii care contin "unknown" sunt respinsi cu 550.
 * Fiecare conexiune N scrie in <director>: transcript-N.txt si, daca exista, message-N.eml.
 */

$port = (int) ($argv[1] ?? 0);
$directory = (string) ($argv[2] ?? '.');
$options = explode(',', (string) ($argv[3] ?? ''));
$certificate = $argv[4] ?? null;

$context = stream_context_create(['ssl' => ['local_cert' => (string) $certificate, 'verify_peer' => false, 'allow_self_signed' => true]]);
$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, "cannot listen: {$error}\n");
    exit(1);
}

$connection = 0;
while (($client = @stream_socket_accept($server, -1)) !== false) {
    $connection++;
    $log = [];

    $read = static function () use ($client, &$log): ?string {
        $line = fgets($client);
        if ($line === false) {
            return null;
        }
        $line = rtrim($line, "\r\n");
        $log[] = 'C: '.$line;

        return $line;
    };
    $send = static function (string $reply) use ($client, &$log): void {
        $log[] = 'S: '.$reply;
        fwrite($client, $reply."\r\n");
    };

    if (in_array('proxy', $options, true)) {
        $request = $read();
        while (($header = $read()) !== null && $header !== '') {
            // antetele cererii CONNECT
        }
        if ($request === null || ! str_starts_with($request, 'CONNECT ')) {
            fclose($client);

            continue;
        }
        fwrite($client, "HTTP/1.1 200 Connection established\r\nProxy-Agent: fake\r\n\r\n");
        $log[] = 'PROXY: tunnel established';
    }

    $secure = false;
    if (in_array('tls', $options, true)) {
        $secure = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true;
        $log[] = $secure ? 'TLS: implicit' : 'TLS: handshake failed';
        if (! $secure) {
            file_put_contents("{$directory}/transcript-{$connection}.txt", implode("\n", $log));
            fclose($client);

            continue;
        }
    }

    $send('220 fake.smtp ESMTP');

    while (($command = $read()) !== null) {
        $verb = strtoupper(explode(' ', $command)[0]);

        if ($verb === 'EHLO') {
            $lines = ['fake.smtp'];
            if (in_array('starttls', $options, true) && ! $secure) {
                $lines[] = 'STARTTLS';
            }
            if (! in_array('no-auth', $options, true)) {
                $lines[] = in_array('login-only', $options, true) ? 'AUTH LOGIN' : 'AUTH PLAIN LOGIN';
            }
            $lines[] = 'SIZE 10000000';
            $last = count($lines) - 1;
            foreach ($lines as $index => $text) {
                $send('250'.($index === $last ? ' ' : '-').$text);
            }
        } elseif ($verb === 'STARTTLS' && in_array('starttls', $options, true)) {
            $send('220 2.0.0 Ready to start TLS');
            $secure = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true;
            $log[] = $secure ? 'TLS: started' : 'TLS: handshake failed';
            if (! $secure) {
                break;
            }
        } elseif (str_starts_with(strtoupper($command), 'AUTH PLAIN ')) {
            $ok = base64_decode(substr($command, 11), true) === "\0ion\0parola";
            $log[] = 'AUTH: '.($secure ? 'encrypted' : 'PLAINTEXT');
            $send($ok ? '235 2.7.0 Authentication successful' : '535 5.7.8 Authentication credentials invalid');
        } elseif (strtoupper($command) === 'AUTH LOGIN') {
            $log[] = 'AUTH: '.($secure ? 'encrypted' : 'PLAINTEXT');
            $send('334 VXNlcm5hbWU6');
            $user = base64_decode((string) $read(), true);
            $send('334 UGFzc3dvcmQ6');
            $password = base64_decode((string) $read(), true);
            $ok = $user === 'ion' && $password === 'parola';
            $send($ok ? '235 2.7.0 Authentication successful' : '535 5.7.8 Authentication credentials invalid');
        } elseif ($verb === 'MAIL') {
            $send('250 2.1.0 OK');
        } elseif ($verb === 'RCPT') {
            // ca serverele reale, raspunsul de eroare repeta adresa respinsa
            $send(str_contains($command, 'unknown') ? '550 5.1.1 '.substr($command, 8).': User unknown' : '250 2.1.5 OK');
        } elseif ($verb === 'DATA') {
            $send('354 End data with <CR><LF>.<CR><LF>');
            $data = '';
            while (($dataLine = fgets($client)) !== false && $dataLine !== ".\r\n") {
                $data .= $dataLine;
            }
            file_put_contents("{$directory}/message-{$connection}.eml", $data);
            $log[] = 'C: <DATA '.strlen($data).' bytes>';
            $send('250 2.0.0 Queued');
        } elseif ($verb === 'QUIT') {
            $send('221 2.0.0 Bye');
            break;
        } else {
            $send('502 5.5.2 Command not recognized');
        }
    }

    file_put_contents("{$directory}/transcript-{$connection}.txt", implode("\n", $log));
    fclose($client);
}
