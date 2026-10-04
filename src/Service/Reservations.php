<?php

namespace Base\Restaurant\Service;

use Base\Restaurant\Entity\Reservation;
use Base\Restaurant\Enum\ReservationStatus;
use Base\Restaurant\Event\ReservationChangedEvent;
use Base\Restaurant\Exception\TransitionException;
use Base\Restaurant\Exception\UnavailableException;
use Base\Restaurant\Model\Booking;
use Base\Service\Calendar\CalendarEntry;
use Base\Service\Calendar\Ics;
use Base\Service\OpeningHours;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The reservation book's actions: a guest's request from the site, the
 * staff's from the phone, the guest's change and cancellation through the
 * management link, the evening's steps on the pass. Each one dispatched
 * (ReservationChangedEvent) and, for the guest, mailed: the confirmation
 * carries the management link and the calendar file (glitchr/omnibase's Ics).
 */
class Reservations
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Availability $availability,
        private readonly Ics $ics,
        private readonly OpeningHours $hours,
        private readonly UrlGeneratorInterface $urls,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly TranslatorInterface $translator,
        private readonly ?MailerInterface $mailer = null,
        private readonly ?LoggerInterface $logger = null,
        #[Autowire('%restaurant.from_email%')] private readonly ?string $from = null,
        #[Autowire('%restaurant.notify_email%')] private readonly ?string $notify = null,
        #[Autowire('%restaurant.reservation.cancel_until%')] private readonly int $cancelUntil = 120,
    ) {
    }

    /** @throws UnavailableException */
    public function request(Booking $booking, ?string $locale = null): Reservation
    {
        $reservation = (new Reservation(null, (int) $booking->covers, (string) $booking->name))
            ->setEmail($booking->email)->setPhone($booking->phone)
            ->setNotes($booking->notes)->setAllergies($booking->allergies)
            ->setLocale($locale)->setSource(Reservation::SOURCE_SITE);
        $this->availability->book($reservation, (string) $booking->day, (string) $booking->time);
        $this->changed($reservation, null);
        $this->mail($reservation, ReservationStatus::CONFIRMED === $reservation->getStatus() ? 'confirmed' : 'requested');
        if (ReservationStatus::REQUESTED === $reservation->getStatus()) {
            $this->tellRestaurant($reservation);
        }

        return $reservation;
    }

    /** Taken by the staff (the phone, a walk-in): confirmed, at any free table - or none, when they say so. */
    public function take(Reservation $reservation, string $day, string $time, string $source = Reservation::SOURCE_PHONE): Reservation
    {
        $reservation->setSource($source);
        $this->availability->book($reservation, $day, $time, false);
        $this->changed($reservation, null);
        if ($reservation->getEmail()) {
            $this->mail($reservation, 'confirmed');
        }

        return $reservation;
    }

    /** The guest moves it (another day, time or size), through the management link. @throws UnavailableException|TransitionException */
    public function change(Reservation $reservation, string $day, string $time, int $covers): Reservation
    {
        if (!$this->changeable($reservation)) {
            throw new TransitionException('manage.error.too_late');
        }
        $previous = $reservation->getStatus();
        $was = [$reservation->getStartsAt(), $reservation->getEndsAt(), $reservation->getCovers()];
        $reservation->setCovers($covers);
        try {
            $this->availability->book($reservation, $day, $time);
        } catch (UnavailableException $e) {
            $reservation->setCovers($was[2]);
            $this->entityManager->refresh($reservation);

            throw $e;
        }
        $this->changed($reservation, $previous);
        $this->mail($reservation, 'changed');

        return $reservation;
    }

    /** Cancelled by the guest (the link) or by the staff. */
    public function cancel(Reservation $reservation, bool $byGuest = true): void
    {
        if ($byGuest && !$this->changeable($reservation)) {
            throw new TransitionException('manage.error.too_late');
        }
        $this->move($reservation, ReservationStatus::CANCELLED);
        if ($byGuest) {
            $this->tellRestaurant($reservation, 'cancelled');
        }
    }

    /**
     * The pass's steps: confirm or refuse a request, seat, finish, absent.
     *
     * @throws TransitionException
     */
    public function move(Reservation $reservation, ReservationStatus $to, bool $fromPlatform = false): void
    {
        $previous = $reservation->getStatus();
        if (!$fromPlatform && !$previous->canBecome($to)) {
            throw new TransitionException(sprintf('A reservation %s cannot become %s.', $previous->value, $to->value));
        }
        $reservation->setStatus($to);
        $this->entityManager->flush();
        $this->changed($reservation, $previous, $fromPlatform);
        if (!$fromPlatform && \in_array($to, [ReservationStatus::CONFIRMED, ReservationStatus::REFUSED, ReservationStatus::CANCELLED], true)) {
            $this->mail($reservation, $to->value);
        }
    }

    /** Still time for the guest to change or cancel it online. */
    public function changeable(Reservation $reservation): bool
    {
        if (!\in_array($reservation->getStatus(), [ReservationStatus::REQUESTED, ReservationStatus::CONFIRMED], true)) {
            return false;
        }

        return $reservation->getStartsAt()->format('Y-m-d H:i') > $this->availability->now()->modify(sprintf('+%d minutes', $this->cancelUntil))->format('Y-m-d H:i');
    }

    public function calendarEntry(Reservation $reservation, string $title, ?string $location = null): CalendarEntry
    {
        $zone = $this->hours->timezone();

        return new CalendarEntry(
            uid: 'reservation-'.$reservation->getToken().'@restaurant',
            title: $title,
            start: new \DateTimeImmutable($reservation->getStartsAt()->format('Y-m-d H:i:s'), $zone),
            end: new \DateTimeImmutable($reservation->getEndsAt()->format('Y-m-d H:i:s'), $zone),
            description: (string) $reservation->getNotes(),
            location: $location,
            url: $this->urls->generate('restaurant_manage', ['token' => $reservation->getToken()], UrlGeneratorInterface::ABSOLUTE_URL),
            cancelled: ReservationStatus::CANCELLED === $reservation->getStatus(),
        );
    }

    public function ics(Reservation $reservation, string $title, ?string $location = null): string
    {
        return $this->ics->calendar([$this->calendarEntry($reservation, $title, $location)], $title);
    }

    private function changed(Reservation $reservation, ?ReservationStatus $previous, bool $fromPlatform = false): void
    {
        $this->dispatcher->dispatch(new ReservationChangedEvent($reservation, $previous, $fromPlatform));
    }

    /** The guest's mail: what happened, the link to manage it, the calendar file. A mail that fails is logged, not thrown: the table is booked. */
    private function mail(Reservation $reservation, string $what): void
    {
        if (!$this->mailer || !$reservation->getEmail()) {
            return;
        }
        try {
            $email = (new TemplatedEmail())
                ->to(new Address($reservation->getEmail(), $reservation->getName()))
                ->subject($this->translator->trans('mail.'.$what.'.subject', ['date' => $reservation->getStartsAt()->format('d/m'), 'time' => $reservation->getTime()], 'restaurant', $reservation->getLocale() ?? 'fr'))
                ->htmlTemplate('@Restaurant/email/reservation.html.twig')
                ->locale($reservation->getLocale() ?? 'fr')
                ->context(['reservation' => $reservation, 'what' => $what, 'manage_url' => $this->urls->generate('restaurant_manage', ['token' => $reservation->getToken()], UrlGeneratorInterface::ABSOLUTE_URL)]);
            if ($this->from) {
                $email->from($this->from);
            }
            if (\in_array($what, ['confirmed', 'changed'], true)) {
                $email->attach($this->ics($reservation, $this->translator->trans('mail.calendar_title', [], 'restaurant', $reservation->getLocale() ?? 'fr')), 'reservation.ics', 'text/calendar; charset=utf-8; method=PUBLISH');
            }
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger?->error('restaurant: the reservation mail could not be sent', ['reservation' => $reservation->getId(), 'exception' => $e]);
        }
    }

    private function tellRestaurant(Reservation $reservation, string $what = 'requested'): void
    {
        if (!$this->mailer || !$this->notify) {
            return;
        }
        try {
            $email = (new TemplatedEmail())->to($this->notify)
                ->subject($this->translator->trans('mail.staff.'.$what.'.subject', ['date' => $reservation->getStartsAt()->format('d/m'), 'time' => $reservation->getTime(), 'covers' => $reservation->getCovers(), 'name' => $reservation->getName()], 'restaurant', 'fr'))
                ->htmlTemplate('@Restaurant/email/reservation_staff.html.twig')
                ->context(['reservation' => $reservation, 'what' => $what]);
            if ($this->from) {
                $email->from($this->from);
            }
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger?->error('restaurant: the staff could not be told of a reservation', ['reservation' => $reservation->getId(), 'exception' => $e]);
        }
    }
}
