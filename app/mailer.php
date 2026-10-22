<?php
/**
 * Email: lightweight SMTP client (SSL/465 or STARTTLS/587) with PHP mail() fallback.
 * Configure in config.php → 'mail'. Use a cPanel email account, e.g. noreply@zimgeo.co.zw.
 * Designed by Zimgeo — www.zimgeo.co.zw
 */
declare(strict_types=1);

final class SmtpMailer
{
    private $sock = null;
    private array $c;
    public string $lastError = '';

    public function __construct(array $c)
    {
        $this->c = $c;
    }

    public function connect(): bool
    {
        $host = $this->c['host'];
        $port = (int)($this->c['port'] ?? 465);
        $secure = strtolower((string)($this->c['secure'] ?? ($port === 465 ? 'ssl' : 'tls')));
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => !empty($this->c['verify_ssl']),
            'verify_peer_name' => !empty($this->c['verify_ssl']),
            'allow_self_signed' => empty($this->c['verify_ssl']),
        ]]);
        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $this->sock = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->sock) {
            $this->lastError = "Connect failed: $errstr ($errno)";
            return false;
        }
        stream_set_timeout($this->sock, 30);
        if (!$this->expect(220)) {
            return false;
        }
        $ehloHost = parse_url((string)cfg('base_url', ''), PHP_URL_HOST) ?: 'localhost';
        if (!$this->cmd("EHLO $ehloHost", 250)) {
            return false;
        }
        if ($secure === 'tls') {
            if (!$this->cmd('STARTTLS', 220)) {
                return false;
            }
            if (!stream_socket_enable_crypto($this->sock, true,
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                $this->lastError = 'STARTTLS negotiation failed';
                return false;
            }
            if (!$this->cmd("EHLO $ehloHost", 250)) {
                return false;
            }
        }
        if (!empty($this->c['user'])) {
            if (!$this->cmd('AUTH LOGIN', 334)
                || !$this->cmd(base64_encode((string)$this->c['user']), 334)
                || !$this->cmd(base64_encode((string)$this->c['pass']), 235)) {
                $this->lastError = 'Authentication failed: ' . $this->lastError;
                return false;
            }
        }
        return true;
    }

    public function send(string $to, string $toName, string $subject, string $html, string $text): bool
    {
        if (!$this->sock && !$this->connect()) {
            return false;
        }
        $from = (string)$this->c['from_email'];
        if (!$this->cmd("MAIL FROM:<$from>", 250) || !$this->cmd("RCPT TO:<$to>", [250, 251]) || !$this->cmd('DATA', 354)) {
            $this->cmd('RSET', 250);
            return false;
        }
        $msg = build_mime($to, $toName, $subject, $html, $text);
        $msg = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $msg));
        $msg = str_replace("\n", "\r\n", $msg);
        fwrite($this->sock, $msg . "\r\n.\r\n");
        return $this->expect(250);
    }

    public function close(): void
    {
        if ($this->sock) {
            @fwrite($this->sock, "QUIT\r\n");
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    private function cmd(string $line, $code): bool
    {
        fwrite($this->sock, $line . "\r\n");
        return $this->expect($code);
    }

    private function expect($codes): bool
    {
        $codes = (array)$codes;
        $resp = '';
        while (($line = fgets($this->sock, 1024)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $codes, true)) {
            $this->lastError = trim($resp) ?: 'No response from server';
            return false;
        }
        return true;
    }
}

function encode_header(string $s): string
{
    return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

function build_mime(string $to, string $toName, string $subject, string $html, string $text): string
{
    $fromEmail = (string)cfg('mail.from_email', 'noreply@zimgeo.co.zw');
    $fromName = (string)cfg('mail.from_name', 'TIMB Tobacco Monitoring Portal');
    $boundary = 'b_' . bin2hex(random_bytes(12));
    $domain = substr(strrchr($fromEmail, '@') ?: '@localhost', 1);
    $h = [
        'Date: ' . date('r'),
        'From: ' . encode_header($fromName) . " <$fromEmail>",
        'To: ' . ($toName !== '' ? encode_header($toName) . ' ' : '') . "<$to>",
        'Subject: ' . encode_header($subject),
        'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'X-Mailer: Zimgeo Portal',
        "Content-Type: multipart/alternative; boundary=\"$boundary\"",
    ];
    if ($reply = cfg('mail.reply_to')) {
        $h[] = "Reply-To: <$reply>";
    }
    return implode("\n", $h) . "\n\n"
        . "--$boundary\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: base64\n\n"
        . chunk_split(base64_encode($text), 76, "\n")
        . "--$boundary\nContent-Type: text/html; charset=UTF-8\nContent-Transfer-Encoding: base64\n\n"
        . chunk_split(base64_encode($html), 76, "\n")
        . "--$boundary--";
}

function mail_log(string $line): void
{
    @file_put_contents(STORAGE . '/logs/mail.log', '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND);
}

/** Wrap content in the branded email template. */
function email_template(string $subject, string $content): string
{
    $logo = abs_url('assets/img/timb-logo.png');
    return '<!doctype html><html><body style="margin:0;background:#f3f1ea;font-family:Segoe UI,Arial,sans-serif;color:#1f2937">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f1ea;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e5e1d3">'
        . '<tr><td style="padding:20px 28px;border-bottom:4px solid #c9a227"><img src="' . e($logo) . '" alt="TIMB" height="56" style="display:block"></td></tr>'
        . '<tr><td style="padding:8px 28px 0"><h2 style="font-size:19px;color:#14532d;margin:18px 0 6px">' . e($subject) . '</h2></td></tr>'
        . '<tr><td style="padding:0 28px 24px;font-size:15px;line-height:1.6">' . $content . '</td></tr>'
        . '<tr><td style="padding:14px 28px;background:#14532d;color:#d1e7d6;font-size:12px">' . e(app_name())
        . ' &middot; Designed by <a href="https://www.zimgeo.co.zw" style="color:#f2d675;text-decoration:none">Zimgeo</a></td></tr>'
        . '</table></td></tr></table></body></html>';
}

function email_button(string $href, string $label): string
{
    return '<p style="margin:22px 0"><a href="' . e($href) . '" style="background:#14532d;color:#ffffff;padding:12px 22px;border-radius:6px;text-decoration:none;font-weight:600;display:inline-block">' . e($label) . '</a></p>';
}

function html_to_text(string $html): string
{
    $t = preg_replace('#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html);
    $t = preg_replace('#<(br|/p|/tr|/h\d|/li)[^>]*>#i', "\n", (string)$t);
    return trim(html_entity_decode(strip_tags((string)$t), ENT_QUOTES, 'UTF-8'));
}

/**
 * Send one email. Returns true on success. Failures are logged to storage/logs/mail.log.
 * Pass a shared $mailer to reuse one SMTP connection for bulk sends.
 */
function send_mail(string $to, string $name, string $subject, string $content, ?SmtpMailer $mailer = null): bool
{
    $html = email_template($subject, $content);
    $text = html_to_text($content) . "\n\n— " . app_name() . "\nDesigned by Zimgeo (www.zimgeo.co.zw)";
    $c = cfg('mail', []);
    if (!empty($c['host'])) {
        $own = $mailer === null;
        $mailer = $mailer ?? new SmtpMailer($c);
        $ok = $mailer->send($to, $name, $subject, $html, $text);
        if ($own) {
            $mailer->close();
        }
        mail_log(($ok ? 'SENT ' : 'FAIL ') . "$to | $subject" . ($ok ? '' : ' | ' . $mailer->lastError));
        return $ok;
    }
    // Fallback: PHP mail() via the server's local mailer
    $mime = build_mime($to, $name, $subject, $html, $text);
    [$headers, $body] = explode("\n\n", $mime, 2);
    $headers = preg_replace('/^(To|Subject): .*\n/m', '', $headers . "\n");
    $ok = @mail($to, encode_header($subject), $body, trim((string)$headers), '-f' . cfg('mail.from_email', ''));
    mail_log(($ok ? 'SENT(mail) ' : 'FAIL(mail) ') . "$to | $subject");
    return $ok;
}

/** Email all active users who opted in that a season has been published. */
function notify_season_published(array $season): int
{
    $users = q("SELECT * FROM users WHERE status = 'active' AND notify_new_season = 1")->fetchAll();
    $c = cfg('mail', []);
    $mailer = !empty($c['host']) ? new SmtpMailer($c) : null;
    $sent = 0;
    $link = abs_url('index.php?season=' . $season['id']);
    foreach ($users as $u) {
        $body = '<p>Dear ' . e($u['name']) . ',</p>'
            . '<p>New tobacco monitoring results are now available on the portal for the <strong>' . e($season['title']) . '</strong>.</p>'
            . '<table cellpadding="6" style="border-collapse:collapse;font-size:14px;margin:10px 0">'
            . '<tr><td style="color:#6b7280">Detected tobacco area</td><td><strong>' . fmt_num($season['total_ha'], 1) . ' ha</strong></td></tr>'
            . '<tr><td style="color:#6b7280">Detected fields</td><td><strong>' . fmt_num($season['field_count']) . '</strong></td></tr>'
            . '<tr><td style="color:#6b7280">Estimated yield</td><td><strong>' . fmt_num($season['total_yield_t'], 0) . ' t</strong></td></tr>'
            . '<tr><td style="color:#6b7280">Imagery period</td><td>' . fmt_date($season['start_date']) . ' – ' . fmt_date($season['end_date']) . '</td></tr>'
            . '</table>'
            . email_button($link, 'Open the dashboard')
            . '<p style="color:#6b7280;font-size:13px">You can turn these notifications off under <em>My account</em> on the portal.</p>';
        if (send_mail($u['email'], $u['name'], 'New data: ' . $season['title'], $body, $mailer)) {
            $sent++;
        }
    }
    if ($mailer) {
        $mailer->close();
    }
    return $sent;
}

/** Notify all admins (e.g. a user activated their account). */
function notify_admins(string $subject, string $content): void
{
    foreach (q("SELECT * FROM users WHERE role = 'admin' AND status = 'active' AND notify_admin_events = 1")->fetchAll() as $a) {
        send_mail($a['email'], $a['name'], $subject, $content);
    }
}
