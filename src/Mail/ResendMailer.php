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

    /** @var int */
    private $timeout;

    /** @var string|null */
    private $lastError = null;

    public function __construct(string $apiKey, string $from, int $timeout = 10)
    {
        $this->apiKey = $apiKey;
        $this->from = $from;
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

        if (!is_string($key) || $key === '' || !is_string($from) || $from === '') {
            return null;
        }

        return new self($key, $from);
    }

    public function send(string $to, string $subject, string $body): bool
    {
        $this->lastError = null;

        $payload = json_encode([
            'from' => $this->from,
            'to' => [$to],
            'subject' => $subject,
            'text' => $body,
        ]);

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
}
