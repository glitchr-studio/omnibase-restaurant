<?php

namespace Base\Restaurant\Exception;

/** No table for that many at that time any more: someone booked it meanwhile, or the time is not offered. The message is a key of the "restaurant" translations. */
class UnavailableException extends RestaurantException
{
}
