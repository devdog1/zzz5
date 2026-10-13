<?php
// Mailer.php - Framework Outbound Email Delivery & SMTP DSN Tracking Engine
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

class Mailer
{
    private static $instance = null;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Send an outbound email with full database logging and SMTP Delivery Status Notification (DSN) tracking.
     *
     * @param string $to Recipient email address
     * @param string $subject Email title / subject
     * @param string $body Email HTML or plaintext body
     * @param string|null $plugin_slug Optional slug of the invoking plugin
     * @param string|null $custom_from Optional custom From address specified by plugin
     * @param array $extra_headers Additional email headers
     * @return array Status array ['success' => bool, 'smtp_code' => string, 'smtp_message' => string, 'log_id' => int]
     */
    public function send($to, $subject, $body, $plugin_slug = null, $custom_from = null, array $extra_headers = [])
    {
        // 1. Resolve configuration settings
        $default_from = get_setting('email_from_address', 'noreply@example.com');
        $envelope_from = get_setting('email_envelope_from', 'bounces@example.com');
        $from = !empty($custom_from) ? $custom_from : $default_from;

        $host = get_setting('email_smtp_host', 'localhost');
        $port = (int)get_setting('email_smtp_port', '25');
        $encryption = strtolower(get_setting('email_smtp_encryption', 'none'));
        $use_auth = (get_setting('email_smtp_auth', '0') === '1');
        $user = get_setting('email_smtp_user', '');
        $pass = get_setting('email_smtp_pass', '');

        // 2. Initial pending log entry in DB
        $log_id = $this->createEmailLog($from, $envelope_from, $to, $subject, $body, $plugin_slug);

        $smtp_code = '500';
        $smtp_message = 'Failed to connect to SMTP relay server';
        $success = false;

        // 3. Perform SMTP Transmission via Socket Connection
        try {
            $socket_host = ($encryption === 'ssl' || $encryption === 'smtps') ? "ssl://{$host}" : $host;
            $fp = @fsockopen($socket_host, $port, $errno, $errstr, 10);

            if (!$fp) {
                // Fallback to PHP native mail() if local socket open fails
                $native_result = $this->sendViaPhpMail($to, $subject, $body, $from, $envelope_from, $extra_headers);
                $smtp_code = $native_result['smtp_code'];
                $smtp_message = $native_result['smtp_message'];
                $success = $native_result['success'];
            } else {
                stream_set_timeout($fp, 10);

                $response = $this->readSmtpResponse($fp);
                if (!$this->isSmtpSuccess($response)) {
                    $smtp_code = $this->parseSmtpCode($response);
                    $smtp_message = $response;
                    fclose($fp);
                } else {
                    // EHLO / HELO
                    fputs($fp, "EHLO " . gethostname() . "\r\n");
                    $ehlo_res = $this->readSmtpResponse($fp);

                    // STARTTLS if required
                    if ($encryption === 'tls' || $encryption === 'starttls') {
                        fputs($fp, "STARTTLS\r\n");
                        $tls_res = $this->readSmtpResponse($fp);
                        if ($this->isSmtpSuccess($tls_res)) {
                            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                            fputs($fp, "EHLO " . gethostname() . "\r\n");
                            $this->readSmtpResponse($fp);
                        }
                    }

                    // AUTH LOGIN
                    if ($use_auth && !empty($user)) {
                        fputs($fp, "AUTH LOGIN\r\n");
                        $this->readSmtpResponse($fp);
                        fputs($fp, base64_encode($user) . "\r\n");
                        $this->readSmtpResponse($fp);
                        fputs($fp, base64_encode($pass) . "\r\n");
                        $auth_res = $this->readSmtpResponse($fp);
                        if (!$this->isSmtpSuccess($auth_res)) {
                            $smtp_code = $this->parseSmtpCode($auth_res);
                            $smtp_message = "SMTP Auth Failed: " . $auth_res;
                            fclose($fp);
                            $this->updateEmailLog($log_id, 'failed', $smtp_code, $smtp_message);
                            return ['success' => false, 'smtp_code' => $smtp_code, 'smtp_message' => $smtp_message, 'log_id' => $log_id];
                        }
                    }

                    // MAIL FROM (Envelope From)
                    $effective_env_from = !empty($envelope_from) ? $envelope_from : $from;
                    fputs($fp, "MAIL FROM:<{$effective_env_from}>\r\n");
                    $mail_from_res = $this->readSmtpResponse($fp);

                    // RCPT TO
                    fputs($fp, "RCPT TO:<{$to}>\r\n");
                    $rcpt_res = $this->readSmtpResponse($fp);

                    if (!$this->isSmtpSuccess($rcpt_res)) {
                        $smtp_code = $this->parseSmtpCode($rcpt_res);
                        $smtp_message = "Recipient rejected: " . $rcpt_res;
                        fputs($fp, "QUIT\r\n");
                        fclose($fp);
                        $this->updateEmailLog($log_id, 'failed', $smtp_code, $smtp_message);
                        return ['success' => false, 'smtp_code' => $smtp_code, 'smtp_message' => $smtp_message, 'log_id' => $log_id];
                    }

                    // DATA
                    fputs($fp, "DATA\r\n");
                    $this->readSmtpResponse($fp);

                    // Build RFC2822 Message Headers
                    $headers = [];
                    $headers[] = "From: {$from}";
                    $headers[] = "To: {$to}";
                    $headers[] = "Subject: {$subject}";
                    $headers[] = "Date: " . date('r');
                    $headers[] = "MIME-Version: 1.0";
                    $headers[] = str_contains($body, '<') ? "Content-Type: text/html; charset=UTF-8" : "Content-Type: text/plain; charset=UTF-8";
                    if ($plugin_slug) {
                        $headers[] = "X-Plugin-Source: {$plugin_slug}";
                    }
                    foreach ($extra_headers as $h_key => $h_val) {
                        $headers[] = "{$h_key}: {$h_val}";
                    }

                    $raw_data = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n";
                    fputs($fp, $raw_data);
                    $data_res = $this->readSmtpResponse($fp);

                    $smtp_code = $this->parseSmtpCode($data_res);
                    $smtp_message = trim($data_res);
                    $success = $this->isSmtpSuccess($data_res);

                    fputs($fp, "QUIT\r\n");
                    fclose($fp);
                }
            }
        } catch (Throwable $t) {
            $smtp_code = '500';
            $smtp_message = "SMTP Transport Exception: " . $t->getMessage();
            $success = false;
        }

        // 4. Update Email Log in Database
        $status = $success ? 'sent' : 'failed';
        $this->updateEmailLog($log_id, $status, $smtp_code, $smtp_message);

        // Audit Trail Action Logging
        if (function_exists('log_action')) {
            log_action('OUTBOUND_EMAIL_SENT', [
                'to' => $to,
                'from' => $from,
                'subject' => $subject,
                'plugin' => $plugin_slug,
                'status' => $status,
                'smtp_code' => $smtp_code
            ]);
        }

        return [
            'success' => $success,
            'smtp_code' => $smtp_code,
            'smtp_message' => $smtp_message,
            'log_id' => $log_id
        ];
    }

    private function sendViaPhpMail($to, $subject, $body, $from, $envelope_from, array $extra_headers)
    {
        $headers = [];
        $headers[] = "From: {$from}";
        $headers[] = "MIME-Version: 1.0";
        $headers[] = str_contains($body, '<') ? "Content-Type: text/html; charset=UTF-8" : "Content-Type: text/plain; charset=UTF-8";
        foreach ($extra_headers as $k => $v) {
            $headers[] = "{$k}: {$v}";
        }

        $header_str = implode("\r\n", $headers);
        $params = !empty($envelope_from) ? "-f{$envelope_from}" : "";

        $sent = @mail($to, $subject, $body, $header_str, $params);

        return [
            'success' => $sent,
            'smtp_code' => $sent ? '250 2.0.0' : '554 5.0.0',
            'smtp_message' => $sent ? '250 2.0.0 Message accepted for delivery via PHP mail()' : '554 5.0.0 Delivery failed via PHP mail()'
        ];
    }

    private function readSmtpResponse($fp)
    {
        $response = "";
        while ($line = fgets($fp, 512)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return trim($response);
    }

    private function isSmtpSuccess($response)
    {
        $code = (int)substr($response, 0, 3);
        return ($code >= 200 && $code < 350);
    }

    private function parseSmtpCode($response)
    {
        if (preg_match('/^(\d{3}(?:\s+\d\.\d\.\d)?)/', $response, $matches)) {
            return $matches[1];
        }
        return substr($response, 0, 3) ?: '250 2.0.0';
    }

    private function createEmailLog($from, $envelope_from, $to, $subject, $body, $plugin_slug)
    {
        try {
            $db = get_db_connection();
            $stmt = $db->prepare("
                INSERT INTO email_logs (from_address, envelope_from, to_address, subject, body, plugin_slug, status, sent_at)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([$from, $envelope_from, $to, $subject, $body, $plugin_slug]);
            return (int)$db->lastInsertId();
        } catch (Exception $e) {
            error_log("Failed to insert email log: " . $e->getMessage());
            return 0;
        }
    }

    private function updateEmailLog($log_id, $status, $smtp_code, $smtp_message)
    {
        if (!$log_id) return;
        try {
            $db = get_db_connection();
            $stmt = $db->prepare("
                UPDATE email_logs
                SET status = ?, smtp_code = ?, smtp_message = ?
                WHERE id = ?
            ");
            $stmt->execute([$status, $smtp_code, $smtp_message, $log_id]);
        } catch (Exception $e) {
            error_log("Failed to update email log: " . $e->getMessage());
        }
    }
}

/**
 * Global helper function to send outbound emails and track logs/SMTP Delivery Status Notification codes.
 */
function send_email($to, $subject, $message, $plugin_slug = null, $custom_from = null, array $extra_headers = [])
{
    return Mailer::getInstance()->send($to, $subject, $message, $plugin_slug, $custom_from, $extra_headers);
}
