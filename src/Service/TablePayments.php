<?php

namespace Base\Restaurant\Service;

use Base\Entity\User;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Model\QuickPayment;
use Base\Marketplace\Service\CartException;
use Base\Marketplace\Service\Pricing;
use Base\Marketplace\Service\QuickOrder;
use Base\Restaurant\Entity\Order\Session;
use Base\Restaurant\Exception\RestaurantException;
use Base\Restaurant\Repository\SessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A table's bill paid from a phone (restaurant.table.pay_online): the
 * billed rounds become the lines of an omnibase/marketplace order
 * - no account: an e-mail address for the receipt -, paid through the
 * shop's gateways (QuickOrder::buy()); paid, the bill is settled "online"
 * and the table is free, as at the till (Pass::settle()).
 *
 * A quick order is priced by the shop, from the dishes: a bill that the shop
 * would not price the same - a round with paid options, a platform's price,
 * a dish since removed - is settled at the till.
 */
class TablePayments
{
    public function __construct(
        private readonly QuickOrder $quickOrder,
        private readonly Pass $pass,
        private readonly SessionRepository $sessions,
        private readonly Pricing $pricing,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%restaurant.table.pay_online%')] private readonly bool $enabled = false,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->quickOrder->isEnabled();
    }

    /** Whether this bill can be paid from a phone now. */
    public function isOffered(Session $session): bool
    {
        return $this->isEnabled() && $session->isOpen() && null !== $this->lines($session);
    }

    /**
     * The bill as lines of an order: the dishes of its rounds (those refused
     * or cancelled left out), their quantities added up. Null when the shop would not charge
     * what the bill says.
     *
     * @return list<array{product: \Base\Restaurant\Entity\Menu\Dish, quantity: int}>|null
     */
    public function lines(Session $session): ?array
    {
        $lines = [];
        $billed = 0;
        foreach ($session->getBilledTickets() as $ticket) {
            foreach ($ticket->getLines() as $line) {
                $dish = $line->getDish();
                if (!$dish || !$dish->isForSell() || !$dish->getStore()) {
                    return null;
                }
                $lines[$dish->getId()] ??= ['product' => $dish, 'quantity' => 0];
                $lines[$dish->getId()]['quantity'] += $line->getQuantity();
                $billed += $line->getTotal();
            }
        }
        if (!$lines) {
            return null;
        }
        // What the order will cost: each line's VAT on its total, as the shop counts it.
        $charged = 0;
        foreach ($lines as $line) {
            $net = (int) $line['product']->getUnitPrice() * $line['quantity'];
            $charged += $net + (int) round($net * $this->pricing->vatRateFor($line['product']));
        }
        // A cent a line: the bill rounds each dish, the order each line.
        if (abs($charged - $billed) > \count($lines)) {
            return null;
        }

        return array_values($lines);
    }

    /**
     * @throws RestaurantException table.error.pay_till, or "@marketplace.quick.error.…"
     */
    public function pay(Session $session, string $email, ?User $signedIn = null): QuickPayment
    {
        if (!$this->isEnabled() || !$session->isOpen()) {
            throw new RestaurantException('table.error.pay_till');
        }
        $lines = $this->lines($session) ?? throw new RestaurantException('table.error.pay_till');

        try {
            $payment = $this->quickOrder->buy($lines, mb_strtolower(trim($email)), $signedIn);
        } catch (CartException $e) {
            throw new RestaurantException('@marketplace.'.$e->getMessage(), $e->getParameters(), $e);
        }

        // The bill is asked for - no more rounds from the phones - and tied to its order.
        $session->requestBill();
        $session->setOrderReference((string) $payment->order->getReference());
        $this->entityManager->flush();
        if ($payment->order->isPaid()) {
            $this->settle($payment->order);
        }

        return $payment;
    }

    /** An order is paid: the bill it pays, if it pays one, is settled - once. */
    public function settle(Order $order): ?Session
    {
        $reference = (string) $order->getReference();
        $session = '' === $reference ? null : $this->sessions->findOneBy(['orderReference' => $reference]);
        if (!$session || !$session->isOpen()) {
            return $session;
        }
        $this->pass->settle($session, 'online', (int) $order->getNetPrice());

        return $session;
    }
}
