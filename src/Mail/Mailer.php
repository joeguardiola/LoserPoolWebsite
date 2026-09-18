<?php

namespace LoserPool\Mail;

/*
 * Sending one message.
 *
 * An interface so the reminder job can be played through in a test without a
 * network or an account: the same seam as the schedule source and the store.
 * Nothing here knows about the pool -- it takes an address and some text.
 */
interface Mailer
{
    /* True if the provider accepted it. Implementations must not throw. */
    public function send(string $to, string $subject, string $body): bool;

    /* Why the last send failed, for a log line. Null after a success. */
    public function lastError(): ?string;
}
