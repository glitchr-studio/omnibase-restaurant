<?php

namespace Base\Restaurant\Model;

/**
 * A moment kept in UTC. omnibase sets PHP's time zone per request from the
 * visitor's (a guest's phone abroad, the pass in the restaurant), and a
 * `datetime_immutable` column is written and read as the wall clock of
 * whatever zone PHP is in: the same instant would be stored at different
 * hours by two requests. So the bundle's moments (a ticket's arrival, a
 * platform's deadline) are written in UTC and read back as UTC, whatever
 * the request's zone. A reservation's time is not a moment but the
 * restaurant's wall clock, kept as written: it does not go through here.
 */
final class Instant
{
    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** Any date, as the same moment in UTC: what an entity stores. */
    public static function from(?\DateTimeInterface $moment): ?\DateTimeImmutable
    {
        return $moment ? \DateTimeImmutable::createFromInterface($moment)->setTimezone(new \DateTimeZone('UTC')) : null;
    }

    /** What Doctrine hydrated (UTC's wall clock, labelled with the request's zone), as the moment it is. */
    public static function read(?\DateTimeInterface $stored): ?\DateTimeImmutable
    {
        return $stored ? new \DateTimeImmutable($stored->format('Y-m-d H:i:s'), new \DateTimeZone('UTC')) : null;
    }
}
