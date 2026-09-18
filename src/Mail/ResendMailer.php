<?php

namespace LoserPool\Mail;

/*
 * Sends through Resend's HTTP API.
 *
 * A plain curl POST rather than a client library, for the reason the
 * autoloader is hand-rolled: Composer is a dev-only tool here, and the
 * deployed container must never need `composer install`.
 *
 * The API key and the From address come from the environment, so neither is
 * in the repository -- this one is public.
 */
final class ResendMailer implements Mailer
{
    private const ENDPOINT = 'https://api.resend.com/emails';

    /** @var string */
    private $apiKey;

    /** @var string */
    private $from;

    /** @var string|null */
    private $replyTo;

    /** @var int */
    private $timeout;

    /** @var string|null */
    private $lastError = null;

    public function __construct(string $apiKey, string $from, ?string $replyTo = null, int $timeout = 10)
    {
        $this->apiKey = $apiKey;
        $this->from = $from;
        $this->replyTo = $replyTo;
        $this->timeout = $timeout;
    }

    /*
     * Returns null when the environment does not carry both settings, so the
     * caller can say "not configured" rather than send nothing and report
     * success.
     */
    public static function fromEnvironment(): ?self
    {
        $key = getenv('LP_RESEND_API_KEY');
        $from = getenv('LP_MAIL_FROM');
        $replyTo = getenv('LP_MAIL_REPLY_TO');

        if (!is_string($key) || $key === '' || !is_string($from) || $from === '') {
            return null;
        }

        return new self($key, $from, is_string($replyTo) && $replyTo !== '' ? $replyTo : null);
    }

    public function send(string $to, string $subject, string $body): bool
    {
        $this->lastError = null;

        $message = [
            'from' => $this->from,
            'to' => [$to],
            'subject' => $subject,
            'text' => $body,
            /*
             * An HTML part alongside the text. A text-only message from an
             * unknown domain is a shape bulk spam has, and the reminder is
             * three lines either way -- there is nothing to lose by sending
             * both.
             */
            'html' => self::asHtml($body),
        ];

        if ($this->replyTo !== null) {
            /*
             * A reply that reaches a person. Mail nobody can answer is both
             * worse to receive and a mild spam signal.
             */
            $message['reply_to'] = $this->replyTo;

            /*
             * Gmail wants an unsubscribe route on bulk mail, and its absence
             * counts against a sender. The list is a pool of people who asked
             * to be in it, so the route is a mail to the commissioner rather
             * than a preference centre.
             */
            $message['headers'] = [
                'List-Unsubscribe' => '<mailto:' . self::addressOf($this->replyTo) . '?subject=unsubscribe>',
            ];
        }

        $payload = json_encode($message);

        if ($payload === false) {
            $this->lastError = 'could not encode the message';
            return false;
        }

        $curl = curl_init(self::ENDPOINT);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $transport = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            $this->lastError = 'transport: ' . $transport;
            return false;
        }

        if ($status < 200 || $status >= 300) {
            /*
             * The body carries the reason -- an unverified domain, a bad key.
             * It is quoted here because the alternative is a job that reports
             * "3 failed" and leaves no way to find out why.
             */
            $this->lastError = 'HTTP ' . $status . ': ' . substr((string) $response, 0, 200);
            return false;
        }

        return true;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /* "Name <a@b>" -> "a@b"; a bare address is returned unchanged. */
    public static function addressOf(string $mailbox): string
    {
        if (preg_match('/<([^>]+)>/', $mailbox, $m) === 1) {
            return trim($m[1]);
        }

        return trim($mailbox);
    }

    /* The plain text as simple HTML: paragraphs, and links left as text. */
    public static function asHtml(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $paragraphs = preg_split('/\n{2,}/', trim($escaped)) ?: [];

        $html = '';
        foreach ($paragraphs as $paragraph) {
            $html .= '<p>' . nl2br($paragraph) . '</p>';
        }

        return '<div style="font-family:system-ui,sans-serif;font-size:15px;line-height:1.5">'
            . $html . '</div>';
    }
}
