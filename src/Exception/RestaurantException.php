<?php

namespace Base\Restaurant\Exception;

/** Something the guest or the staff should read: the message is a key of the "restaurant" translations, with its parameters. */
class RestaurantException extends \RuntimeException
{
    /** @param array<string, string|int|float> $parameters */
    public function __construct(string $message = '', public readonly array $parameters = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
