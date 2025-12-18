<?php
/**
 * Lightweight mail alert helper for scrape runs.
 *
 * Supports two modes:
 *   1) SMTP (recommended) via env: MAIL_MAILER=smtp, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_ENCRYPTION (tls/ssl/none)
 *   2) mail() fallback if SMTP env not provided (requires system mailer)
 *
 * Recipients:
 *   SCRAPER_ALERT_TO (comma-separated) or MAIL_TO_ADDRESS (comma-separated) must be set.
 * Sender:
 *   SCRAPER_ALERT_FROM or MAIL_FROM_ADDRESS (with optional MAIL_FROM_NAME).
 */

function send_scrape_alert(int $vendorId, array $stats, bool $success, string $note = ''): void
{
    // Defaults (requested)
    $defaults = [
        'mailer'      => 'smtp',
        'host'        => 'smtp.gmail.com',
        'port'        => 587,
        'username'    => 'delwerhossain006@gmail.com',
        'password'    => 'lbtebnztuepfiuvr', // Gmail app password (no spaces)
        'encryption'  => 'tls',
        'from_email'  => 'delwerhossain006@gmail.com',
        'from_name'   => 'CarVendors',
        'to'          => 'delwer.dev@gmail.com,delwerhossain006@gmail.com',
    ];

    // Allow env overrides but fall back to defaults above
    $toEnv = getenv('SCRAPER_ALERT_TO') ?: getenv('MAIL_TO_ADDRESS') ?: $defaults['to'];
    $toList = array_filter(array_map('trim', explode(',', $toEnv)));
    if (empty($toList)) {
        echo "Alert not sent (no valid recipients)\n";
        return;
    }

    $fromEmail = getenv('SCRAPER_ALERT_FROM') ?: getenv('MAIL_FROM_ADDRESS') ?: $defaults['from_email'];
    $fromName  = getenv('MAIL_FROM_NAME') ?: $defaults['from_name'];
    $from      = "{$fromName} <{$fromEmail}>";

    $inserted = (int)($stats['inserted'] ?? 0);
    $updated  = (int)($stats['updated'] ?? 0);
    $errors   = (int)($stats['errors'] ?? 0);
    $found    = (int)($stats['found'] ?? 0);
    $skipped  = (int)($stats['skipped'] ?? 0);
    $failures = $errors; // errors are counted per failed vehicle (e.g., invalid VRM or fetch failures)

    $statusText = $success ? 'SUCCESS' : 'FAILURE';
    $subject = "[CarSafari] Vendor {$vendorId} scrape {$statusText} - ok: {$inserted}, fail: {$failures}";

    $bodyLines = [
        "Vendor: {$vendorId}",
        "Status: {$statusText}",
        "Found: {$found}",
        "Inserted: {$inserted}",
        "Updated: {$updated}",
        "Skipped: {$skipped}",
        "Failures: {$failures}",
        "Timestamp: " . date('Y-m-d H:i:s'),
    ];

    if (!empty($note)) {
        $bodyLines[] = "";
        $bodyLines[] = "Note:";
        $bodyLines[] = $note;
    }

    $body = implode("\n", $bodyLines);

    $mailer = strtolower(getenv('MAIL_MAILER') ?: $defaults['mailer']);
    $host = getenv('MAIL_HOST') ?: $defaults['host'];
    $port = (int)(getenv('MAIL_PORT') ?: $defaults['port']);
    // Support Gmail app password overrides
    $gmailUser = getenv('GMAIL_USER');
    $gmailPass = getenv('GMAIL_APP_PASSWORD');
    $user = $gmailUser ?: (getenv('MAIL_USERNAME') ?: $defaults['username']);
    $pass = $gmailPass ?: (getenv('MAIL_PASSWORD') ?: $defaults['password']);
    $encryption = strtolower(getenv('MAIL_ENCRYPTION') ?: $defaults['encryption']); // tls|ssl|none

    $sent = false;
    if ($mailer === 'smtp' && $host && $user && $pass) {
        $sent = smtp_send($host, $port, $user, $pass, $encryption, $from, $toList, $subject, $body);
        if (!$sent) {
            echo "SMTP send failed, falling back to mail()\n";
        }
    }

    if (!$sent) {
        $headers = "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8";
        $sent = @mail(implode(',', $toList), $subject, $body, $headers);
    }

    if ($sent) {
        echo "Alert sent to " . implode(', ', $toList) . "\n";
    } else {
        echo "Failed to send alert (SMTP/mail fallback both failed)\n";
    }
}

/**
 * Minimal SMTP client (STARTTLS if encryption=tls, implicit SSL if encryption=ssl).
 */
function smtp_send(string $host, int $port, string $username, string $password, string $encryption, string $from, array $to, string $subject, string $body): bool
{
    $scheme = ($encryption === 'ssl') ? 'ssl://' : 'tcp://';
    $timeout = 15;
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($scheme . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        echo "SMTP connect failed: $errstr\n";
        return false;
    }

    $read = function() use ($fp) {
        return fgets($fp, 512);
    };
    $write = function($cmd) use ($fp) {
        fwrite($fp, $cmd . "\r\n");
    };

    $read(); // banner
    $write("EHLO localhost");
    // Consume all EHLO response lines (multi-line 250-... ending with 250 <text>)
    $line = '';
    do {
        $line = $read();
        if ($line === false) break;
    } while (substr($line, 3, 1) === '-');

    // STARTTLS if needed
    if ($encryption === 'tls') {
        $write("STARTTLS");
        $resp = $read();
        if (stripos($resp, '220') !== 0) {
            echo "SMTP STARTTLS failed: $resp\n";
            fclose($fp);
            return false;
        }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            echo "SMTP TLS negotiation failed\n";
            fclose($fp);
            return false;
        }
        $write("EHLO localhost");
        // Consume all EHLO response lines after STARTTLS
        do {
            $line = $read();
            if ($line === false) break;
        } while (substr($line, 3, 1) === '-');
    }

    // AUTH LOGIN
    $write("AUTH LOGIN");
    $read();
    $write(base64_encode($username));
    $read();
    $write(base64_encode($password));
    $authResp = $read();
    if (stripos($authResp, '235') !== 0) {
        echo "SMTP auth failed: $authResp\n";
        fclose($fp);
        return false;
    }

    // MAIL FROM
    $fromEmail = extract_email($from);
    $write("MAIL FROM:<{$fromEmail}>");
    $mailResp = $read();
    if (stripos($mailResp, '250') !== 0) {
        echo "SMTP MAIL FROM failed: $mailResp\n";
        fclose($fp);
        return false;
    }

    // RCPT TO
    foreach ($to as $rcpt) {
        $rcptEmail = extract_email($rcpt);
        $write("RCPT TO:<{$rcptEmail}>");
        $rcptResp = $read();
        if (stripos($rcptResp, '250') !== 0 && stripos($rcptResp, '251') !== 0) {
            echo "SMTP RCPT failed for {$rcptEmail}: $rcptResp\n";
            fclose($fp);
            return false;
        }
    }

    // DATA
    $write("DATA");
    $dataResp = $read();
    if (stripos($dataResp, '354') !== 0) {
        echo "SMTP DATA failed: $dataResp\n";
        fclose($fp);
        return false;
    }

    $headers = [];
    $headers[] = "From: {$from}";
    $headers[] = "To: " . implode(', ', $to);
    $headers[] = "Subject: {$subject}";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: text/plain; charset=UTF-8";
    $headers[] = "Content-Transfer-Encoding: 8bit";
    $message = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n";

    $write($message);
    $sendResp = $read();
    $write("QUIT");
    fclose($fp);

    if (stripos($sendResp, '250') === 0) {
        return true;
    }
    echo "SMTP send failed: $sendResp\n";
    return false;
}

/**
 * Extract email address from "Name <email>" or raw email.
 */
function extract_email(string $address): string
{
    if (preg_match('/<([^>]+)>/', $address, $m)) {
        return trim($m[1]);
    }
    return trim($address);
}
