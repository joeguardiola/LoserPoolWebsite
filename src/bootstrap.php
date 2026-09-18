<?php

/*
 * Shared per-request wiring.
 *
 * The handlers previously each constructed their own SqlAccessController, so
 * a single page could open three connections to do one job. One store per
 * request is enough, and passing an override is how tests substitute an
 * in-memory database.
 */

require_once __DIR__ . '/autoload.php';

use LoserPool\Mail\Mailer;
use LoserPool\Mail\ResendMailer;
use LoserPool\Storage\PoolStore;
use LoserPool\Storage\StoreFactory;

function lp_store(?PoolStore $override = null): PoolStore
{
    static $store = null;

    if ($override !== null) {
        $store = $override;
    }

    if ($store === null) {
        $store = StoreFactory::fromEnvironment();
    }

    return $store;
}

/*
 * The mailer, or null when the environment carries no credentials.
 *
 * Null rather than a throw: the web app never sends mail, so a missing key
 * must not be able to take the site down. Only bin/remind.php asks for one,
 * and it reports "not configured" and stops.
 */
function lp_mailer(?Mailer $override = null): ?Mailer
{
    static $mailer = null;
    static $resolved = false;

    if ($override !== null) {
        $mailer = $override;
        $resolved = true;
    }

    if (!$resolved) {
        $mailer = ResendMailer::fromEnvironment();
        $resolved = true;
    }

    return $mailer;
}
