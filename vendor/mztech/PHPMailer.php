<?php
/**
 * MZ Tech – Eigenständiger, abhängigkeitsfreier SMTP-Mailer
 * ----------------------------------------------------------------------
 * Bietet eine API-kompatible Ersatzimplementierung der Klassen
 * PHPMailer\PHPMailer\PHPMailer, PHPMailer\PHPMailer\SMTP und
 * PHPMailer\PHPMailer\Exception, exakt in dem Umfang, der von
 * private/mailer.php genutzt wird:
 *   isSMTP(), Host, SMTPAuth, Username, Password, SMTPSecure
 *   (Konstanten ENCRYPTION_SMTPS / ENCRYPTION_STARTTLS), Port,
 *   CharSet, Encoding, setFrom(), addAddress(), addReplyTo(),
 *   isHTML(), Subject, Body, AltBody, send(), ErrorInfo.
 *
 * Kommunikation erfolgt über PHP-Streams (fsockopen / stream_socket_client)
 * mit STARTTLS bzw. direktem TLS (SMTPS) – keine externen Pakete nötig.
 *
 * Wird nur geladen, wenn die echten, per Composer installierten
 * PHPMailer-Klassen nicht bereits vorhanden sind.
 */

namespace PHPMailer\PHPMailer;

if (!class_exists(__NAMESPACE__ . '\\Exception', false)) {

    class Exception extends \Exception {
        public function errorMessage() {
            return $this->getMessage();
        }
    }

    class SMTP {
        const VERSION = '6.9.0-mztech-compat';
    }

    class PHPMailer {
        const ENCRYPTION_STARTTLS = 'tls';
        const ENCRYPTION_SMTPS    = 'ssl';

        public $Host = '';
        public $Port = 587;
        public $SMTPAuth = true;
        public $Username = '';
        public $Password = '';
        public $SMTPSecure = self::ENCRYPTION_STARTTLS;
        public $CharSet = 'UTF-8';
        public $Encoding = 'base64';
        public $Subject = '';
        public $Body = '';
        public $AltBody = '';
        public $ErrorInfo = '';
        public $Timeout = 15;
        public $SMTPDebug = 0;
        public $SMTPKeepAlive = false;
        public $SMTPAutoTLS = true;
        public $SMTPOptions = [];
        public $XMailer = '';

        private $fromEmail = '';
        private $fromName = '';
        private $replyTo = [];
        private $recipients = [];
        private $isHtml = true;
        private $exceptions = false;
        private $attachments = [];

        public function __construct($exceptions = false) {
            $this->exceptions = (bool)$exceptions;
        }

        public function isSMTP() {
            // Marker-Methode für API-Kompatibilität – Transport ist ohnehin
            // immer SMTP in dieser Implementierung.
            return true;
        }

        public function isHTML($isHtml = true) {
            $this->isHtml = (bool)$isHtml;
        }

        public function setFrom($address, $name = '', $auto = true) {
            $this->fromEmail = $address;
            $this->fromName  = $name;
            return true;
        }

        public function addAddress($address, $name = '') {
            $this->recipients[] = ['email' => $address, 'name' => $name];
            return true;
        }

        public function addReplyTo($address, $name = '') {
            $this->replyTo[] = ['email' => $address, 'name' => $name];
            return true;
        }

        public function addAttachment($path, $name = '') {
            if (is_file($path)) {
                $this->attachments[] = ['path' => $path, 'name' => $name ?: basename($path)];
            }
            return true;
        }

        private function fail(string $message) {
            $this->ErrorInfo = $message;
            if ($this->exceptions) {
                throw new Exception($message);
            }
            return false;
        }

        private function encodeHeader(string $value): string {
            // MIME "encoded word" für Nicht-ASCII-Header (Betreff, Namen)
            if (preg_match('/[\x80-\xFF]/', $value)) {
                return '=?UTF-8?B?' . base64_encode($value) . '?=';
            }
            return $value;
        }

        private function addressHeader(string $email, string $name): string {
            $email = trim($email);
            if ($name !== '') {
                return $this->encodeHeader($name) . ' <' . $email . '>';
            }
            return $email;
        }

        private function randomBoundary(): string {
            return 'mztech-' . bin2hex(random_bytes(16));
        }

        /**
         * Baut die komplette RFC-5322-Nachricht (Header + Multipart-Body).
         */
        private function buildMessage(string $boundary, string $altBoundary): string {
            $eol = "\r\n";
            $lines = [];

            $lines[] = 'Date: ' . date('r');
            $lines[] = 'From: ' . $this->addressHeader($this->fromEmail, $this->fromName);

            $toParts = [];
            foreach ($this->recipients as $r) {
                $toParts[] = $this->addressHeader($r['email'], $r['name']);
            }
            $lines[] = 'To: ' . implode(', ', $toParts);

            if (!empty($this->replyTo)) {
                $replyParts = [];
                foreach ($this->replyTo as $r) {
                    $replyParts[] = $this->addressHeader($r['email'], $r['name']);
                }
                $lines[] = 'Reply-To: ' . implode(', ', $replyParts);
            }

            $lines[] = 'Subject: ' . $this->encodeHeader($this->Subject);
            $lines[] = 'MIME-Version: 1.0';
            $lines[] = 'X-Mailer: MZ-Tech-Mailer';
            $messageId = '<' . bin2hex(random_bytes(16)) . '@' . (parse_url($this->fromEmail, PHP_URL_HOST) ?: 'mztech-it.de') . '>';
            $lines[] = 'Message-ID: ' . $messageId;

            $hasAttachments = !empty($this->attachments);

            if ($this->isHtml) {
                if ($hasAttachments) {
                    $lines[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
                } else {
                    $lines[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
                }
            } else {
                $lines[] = 'Content-Type: text/plain; charset=' . $this->CharSet;
                $lines[] = 'Content-Transfer-Encoding: ' . ($this->Encoding ?: 'base64');
            }

            $header = implode($eol, $lines) . $eol . $eol;

            if (!$this->isHtml) {
                return $header . $this->encodeBody($this->Body);
            }

            $body = '';

            if ($hasAttachments) {
                $body .= '--' . $boundary . $eol;
                $body .= 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"' . $eol . $eol;
            }

            $altBoundaryUsed = $hasAttachments ? $altBoundary : $boundary;

            $body .= '--' . $altBoundaryUsed . $eol;
            $body .= 'Content-Type: text/plain; charset=' . $this->CharSet . $eol;
            $body .= 'Content-Transfer-Encoding: base64' . $eol . $eol;
            $body .= $this->encodeBody($this->AltBody ?: strip_tags($this->Body));
            $body .= $eol;

            $body .= '--' . $altBoundaryUsed . $eol;
            $body .= 'Content-Type: text/html; charset=' . $this->CharSet . $eol;
            $body .= 'Content-Transfer-Encoding: base64' . $eol . $eol;
            $body .= $this->encodeBody($this->Body);
            $body .= $eol;

            if ($hasAttachments) {
                $body .= '--' . $altBoundaryUsed . '--' . $eol . $eol;

                foreach ($this->attachments as $att) {
                    $content = @file_get_contents($att['path']);
                    if ($content === false) continue;
                    $body .= '--' . $boundary . $eol;
                    $body .= 'Content-Type: application/octet-stream; name="' . $att['name'] . '"' . $eol;
                    $body .= 'Content-Transfer-Encoding: base64' . $eol;
                    $body .= 'Content-Disposition: attachment; filename="' . $att['name'] . '"' . $eol . $eol;
                    $body .= chunk_split(base64_encode($content));
                }
                $body .= '--' . $boundary . '--' . $eol;
            } else {
                $body .= '--' . $altBoundaryUsed . '--' . $eol;
            }

            return $header . $body;
        }

        private function encodeBody(string $text): string {
            $text = str_replace(["\r\n", "\r"], "\n", $text);
            return chunk_split(base64_encode($text));
        }

        /**
         * Öffnet eine Socket-Verbindung, führt EHLO/STARTTLS/AUTH/DATA aus.
         */
        public function send() {
            $this->ErrorInfo = '';

            if (!$this->Host || empty($this->recipients) || !$this->fromEmail) {
                return $this->fail('SMTP-Konfiguration unvollständig (Host/Empfänger/Absender fehlt).');
            }

            $useImplicitTls = ($this->SMTPSecure === self::ENCRYPTION_SMTPS) || $this->Port === 465;

            $remote = ($useImplicitTls ? 'ssl://' : 'tcp://') . $this->Host . ':' . $this->Port;

            $context = stream_context_create(array_merge([
                'ssl' => [
                    'verify_peer'       => true,
                    'verify_peer_name'  => true,
                    'allow_self_signed' => false,
                ],
            ], $this->SMTPOptions));

            $errno = 0; $errstr = '';
            $socket = @stream_socket_client($remote, $errno, $errstr, $this->Timeout, STREAM_CLIENT_CONNECT, $context);

            if (!$socket) {
                return $this->fail("Verbindung zu {$this->Host}:{$this->Port} fehlgeschlagen: {$errstr} ({$errno})");
            }

            stream_set_timeout($socket, $this->Timeout);

            try {
                $this->readResponse($socket, 220);

                $ehloHost = gethostname() ?: 'localhost';
                $this->command($socket, "EHLO {$ehloHost}", 250);

                if (!$useImplicitTls && ($this->SMTPSecure === self::ENCRYPTION_STARTTLS || $this->SMTPAutoTLS)) {
                    $this->command($socket, 'STARTTLS', 220);
                    $cryptoOk = @stream_socket_enable_crypto(
                        $socket,
                        true,
                        STREAM_CRYPTO_METHOD_TLS_CLIENT
                            | (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT : 0)
                    );
                    if (!$cryptoOk) {
                        throw new Exception('TLS-Handshake (STARTTLS) fehlgeschlagen.');
                    }
                    // Nach STARTTLS erneut EHLO senden (RFC 3207)
                    $this->command($socket, "EHLO {$ehloHost}", 250);
                }

                if ($this->SMTPAuth) {
                    $this->command($socket, 'AUTH LOGIN', 334);
                    $this->command($socket, base64_encode($this->Username), 334);
                    $this->command($socket, base64_encode($this->Password), 235);
                }

                $this->command($socket, 'MAIL FROM:<' . $this->fromEmail . '>', 250);

                foreach ($this->recipients as $r) {
                    $this->command($socket, 'RCPT TO:<' . $r['email'] . '>', [250, 251]);
                }

                $this->command($socket, 'DATA', 354);

                $boundary    = $this->randomBoundary();
                $altBoundary = $this->randomBoundary();
                $message     = $this->buildMessage($boundary, $altBoundary);

                // Punkt-Stuffing: Zeilen, die mit "." beginnen, verdoppeln
                $message = preg_replace('/^\./m', '..', $message);

                fwrite($socket, $message . "\r\n.\r\n");
                $this->readResponse($socket, 250);

                $this->command($socket, 'QUIT', 221);
                fclose($socket);

                return true;
            } catch (Exception $e) {
                if (is_resource($socket)) fclose($socket);
                $this->ErrorInfo = $e->getMessage();
                if ($this->exceptions) throw $e;
                return false;
            }
        }

        private function command($socket, string $cmd, $expect) {
            fwrite($socket, $cmd . "\r\n");
            return $this->readResponse($socket, $expect);
        }

        private function readResponse($socket, $expect) {
            $expectedCodes = is_array($expect) ? $expect : [$expect];
            $fullResponse = '';
            $code = 0;

            while (!feof($socket)) {
                $line = fgets($socket, 515);
                if ($line === false) break;
                $fullResponse .= $line;
                $code = (int)substr($line, 0, 3);
                // Mehrzeilige Antworten haben "-" an Position 4 (z.B. "250-")
                if (isset($line[3]) && $line[3] === ' ') break;
                if (!isset($line[3])) break;
            }

            if (!in_array($code, $expectedCodes, true)) {
                throw new Exception("SMTP-Fehler (erwartet " . implode('/', $expectedCodes) . ", erhalten {$code}): " . trim($fullResponse));
            }

            return $fullResponse;
        }
    }
}
