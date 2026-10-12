<?php

namespace watrlabs;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

// sends email through an smtp server (zoho, or anything else that takes a login).
// with no MAIL_HOST set, emails get written to storage/mail.log instead, so you can
// click the links while testing locally
class mail {

    static function configured(): bool {
        return ($_ENV['MAIL_HOST'] ?? '') !== '';
    }

    // renders views/emails/<template>.twig and sends it. false if it didn't go
    static function send(string $to, string $subject, string $template, array $data = []): bool {
        global $twig;

        $data += [
            'subject' => $subject,
            'app_name' => $_ENV['APP_NAME'] ?? 'watr.lol',
            'app_url' => rtrim($_ENV['APP_URL'] ?? '', '/'),
        ];

        $view = $twig->load("emails/$template.twig");
        $html = $view->render($data);
        // twig escapes for html, the plain text version wants the real characters back
        $text = html_entity_decode(trim($view->renderBlock('text', $data)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (!self::configured()) {
            $log = date('c') . "\nTo: $to\nSubject: $subject\n\n$text\n\n" . str_repeat('-', 60) . "\n";
            file_put_contents(ROOT . '/storage/mail.log', $log, FILE_APPEND);
            return true;
        }

        $mailer = new PHPMailer(true);

        try {
            $mailer->isSMTP();
            $mailer->Host = $_ENV['MAIL_HOST'];
            $mailer->Port = (int) ($_ENV['MAIL_PORT'] ?? 465);
            $mailer->SMTPAuth = true;
            $mailer->Username = $_ENV['MAIL_USERNAME'] ?? '';
            $mailer->Password = $_ENV['MAIL_PASSWORD'] ?? '';
            $mailer->Timeout = 15;
            $mailer->CharSet = PHPMailer::CHARSET_UTF8;

            // 465 talks tls from the first byte, 587 starts plain and upgrades
            $encryption = strtolower($_ENV['MAIL_ENCRYPTION'] ?? ($mailer->Port === 465 ? 'ssl' : 'tls'));
            $mailer->SMTPSecure = $encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;

            // zoho only sends as the mailbox you logged in as (or an alias of it)
            $mailer->setFrom(($_ENV['MAIL_FROM'] ?? '') ?: $mailer->Username, ($_ENV['MAIL_FROM_NAME'] ?? '') ?: $data['app_name']);
            $mailer->addAddress($to);

            $mailer->isHTML(true);
            $mailer->Subject = $subject;
            $mailer->Body = $html;
            $mailer->AltBody = $text;

            return $mailer->send();
        } catch (MailException $e) {
            $line = date('c') . " mail to $to failed: " . $mailer->ErrorInfo . "\n\n";
            @file_put_contents(ROOT . '/storage/errorlog.log', $line, FILE_APPEND);
            return false;
        }
    }

    static function valid(string $email): bool {
        return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

}
