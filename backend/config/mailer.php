<?php
declare(strict_types=1);
require_once __DIR__ . '/load_env.php';

function sendApplicationEmail(string $recipient, string $subject, string $body): bool
{
    $host = trim((string) (getenv('SMTP_HOST') ?: ''));
    if ($host === '') {
        $from = trim((string) (getenv('MAIL_FROM') ?: ''));
        $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $headers .= 'From: Pemon Barbershop <' . $from . ">\r\n";
        }
        return mail($recipient, $subject, $body, $headers);
    }

    $port = (int) (getenv('SMTP_PORT') ?: 587);
    $encryption = strtolower(trim((string) (getenv('SMTP_ENCRYPTION') ?: 'tls')));
    $username = (string) (getenv('SMTP_USERNAME') ?: '');
    $password = (string) (getenv('SMTP_PASSWORD') ?: '');
    $from = trim((string) (getenv('MAIL_FROM') ?: $username));
    $fromName = trim((string) (getenv('MAIL_FROM_NAME') ?: 'Pemon Barbershop'));
    if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !in_array($encryption, ['tls', 'ssl', 'none'], true)) {
        error_log('SMTP email configuration is invalid.');
        return false;
    }

    $connectHost = $encryption === 'ssl' ? 'ssl://' . $host : $host;
    $socket = @stream_socket_client($connectHost . ':' . $port, $errorCode, $errorMessage, 15, STREAM_CLIENT_CONNECT);
    if (!is_resource($socket)) {
        error_log('SMTP connection failed: ' . $errorMessage . ' (' . $errorCode . ')');
        return false;
    }
    stream_set_timeout($socket, 15);

    try {
        smtpExpect($socket, [220]);
        smtpCommand($socket, 'EHLO localhost', [250]);
        if ($encryption === 'tls') {
            smtpCommand($socket, 'STARTTLS', [220]);
            if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new RuntimeException('Could not establish SMTP TLS encryption.');
            }
            smtpCommand($socket, 'EHLO localhost', [250]);
        }
        if ($username !== '') {
            smtpCommand($socket, 'AUTH LOGIN', [334]);
            smtpCommand($socket, base64_encode($username), [334]);
            smtpCommand($socket, base64_encode($password), [235]);
        }
        smtpCommand($socket, 'MAIL FROM:<' . $from . '>', [250]);
        smtpCommand($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        smtpCommand($socket, 'DATA', [354]);

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $message = implode("\r\n", [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $encodedName . ' <' . $from . '>',
            'To: <' . $recipient . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            str_replace(["\r\n", "\r"], "\n", $body),
        ]);
        $message = preg_replace('/^\./m', '..', str_replace("\n", "\r\n", $message));
        fwrite($socket, $message . "\r\n.\r\n");
        smtpExpect($socket, [250]);
        smtpCommand($socket, 'QUIT', [221]);
        return true;
    } catch (Throwable $error) {
        error_log('SMTP email delivery failed: ' . $error->getMessage());
        return false;
    } finally {
        fclose($socket);
    }
}

function smtpCommand($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");
    return smtpExpect($socket, $expectedCodes);
}

function smtpExpect($socket, array $expectedCodes): string
{
    $response = '';
    do {
        $line = fgets($socket, 515);
        if ($line === false) {
            throw new RuntimeException('SMTP server closed the connection unexpectedly.');
        }
        $response .= $line;
    } while (isset($line[3]) && $line[3] === '-');

    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP server returned status ' . $code . '.');
    }
    return $response;
}
