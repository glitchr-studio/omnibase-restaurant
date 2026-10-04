<?php

namespace Base\Restaurant\Model;

use Symfony\Component\Validator\Constraints as Assert;

/** What a phone sends to pay its table's bill (#[MapRequestPayload]): where the receipt goes. */
final class BillPayment
{
    #[Assert\NotBlank, Assert\Email, Assert\Length(max: 180)]
    public string $email = '';
}
