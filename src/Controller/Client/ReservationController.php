<?php

namespace Base\Restaurant\Controller\Client;

use Base\Restaurant\Exception\RestaurantException;
use Base\Restaurant\Form\BookingType;
use Base\Restaurant\Model\Booking;
use Base\Restaurant\Model\Slot;
use Base\Restaurant\Repository\ReservationRepository;
use Base\Restaurant\Service\Availability;
use Base\Restaurant\Service\Reservations;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Booking a table on the site, and the guest's own page for it: the form
 * (a day, how many, a time among those free, who), then the reservation
 * through its link - see it, move it, cancel it, add it to a calendar.
 */
class ReservationController extends AbstractController
{
    public function __construct(
        private readonly Availability $availability,
        private readonly Reservations $book,
        private readonly ReservationRepository $reservations,
        private readonly \Symfony\Contracts\Translation\TranslatorInterface $translator,
        #[Autowire('%restaurant.layout%')] private readonly string $layout = 'layout1.html.twig',
        #[Autowire('%restaurant.reservation.min_delay%')] private readonly int $minDelay = 3,
    ) {
    }

    #[Route('/reserver', name: 'restaurant_book', methods: ['GET', 'POST'])]
    public function book(Request $request): Response
    {
        $booking = new Booking();
        $booking->day = $request->query->get('jour') ?: ($this->availability->openDays(2, null, 1)[0] ?? $this->availability->today()->format('Y-m-d'));
        $booking->covers = max(1, min($this->availability->maxCovers(), $request->query->getInt('couverts', 2)));
        $booking->startedAt = time();
        $form = $this->createForm(BookingType::class, $booking, [
            'min' => $this->availability->today()->format('Y-m-d'),
            'max' => $this->availability->lastDay()->format('Y-m-d'),
            'max_covers' => $this->availability->maxCovers(),
        ]);
        $form->handleRequest($request);
        $error = null;

        if ($form->isSubmitted() && $form->isValid()) {
            if ($booking->isRobot($this->minDelay)) {
                // Thanked, nothing kept.
                return $this->render('@Restaurant/client/booked.html.twig', ['layout' => $this->layout, 'reservation' => null]);
            }
            try {
                $reservation = $this->book->request($booking, $request->getLocale());

                return $this->redirectToRoute('restaurant_manage', ['token' => $reservation->getToken(), 'nouvelle' => 1]);
            } catch (RestaurantException $e) {
                $error = $this->translator->trans($e->getMessage(), [], 'restaurant');
                $booking->time = null;
            }
        }

        $day = $this->day($booking->day);

        return $this->render('@Restaurant/client/book.html.twig', [
            'layout' => $this->layout,
            'form' => $form,
            'slots' => $day ? $this->availability->slotsByService($day, (int) $booking->covers) : [],
            'days' => $this->availability->openDays((int) $booking->covers ?: 2, null, 10),
            'max_covers' => $this->availability->maxCovers(),
            'error' => $error,
        ], new Response(null, $error || ($form->isSubmitted() && !$form->isValid()) ? 422 : 200));
    }

    /** The times free that day for that many: the form asks as the guest picks. */
    #[Route('/reserver/creneaux', name: 'restaurant_book_slots', methods: ['GET'])]
    public function slots(#[MapQueryParameter] string $jour = '', #[MapQueryParameter] int $couverts = 2): JsonResponse
    {
        $day = $this->day($jour);
        $services = [];
        foreach ($day ? $this->availability->slotsByService($day, max(1, $couverts)) : [] as $name => $slots) {
            $services[] = ['name' => $name, 'times' => array_map(fn (Slot $s) => $s->time, $slots)];
        }

        return $this->json(['day' => $day?->format('Y-m-d'), 'covers' => $couverts, 'services' => $services, 'too_many' => $couverts > $this->availability->maxCovers()]);
    }

    #[Route('/reservation/{token}', name: 'restaurant_manage', requirements: ['token' => '[A-Za-z0-9_\-]{20,64}'], methods: ['GET', 'POST'])]
    public function manage(Request $request, string $token): Response
    {
        $reservation = $this->reservations->findOneByToken($token) ?? throw $this->createNotFoundException();
        $changeable = $this->book->changeable($reservation);
        $error = null;

        if ($request->isMethod('POST') && $changeable) {
            if (!$this->isCsrfTokenValid('restaurant-manage-'.$token, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            try {
                if ('cancel' === $request->request->get('action')) {
                    $this->book->cancel($reservation);
                    $this->addFlash('success', '@restaurant.manage.cancelled');
                } else {
                    $this->book->change($reservation, (string) $request->request->get('day'), (string) $request->request->get('time'), $request->request->getInt('covers', $reservation->getCovers()));
                    $this->addFlash('success', '@restaurant.manage.changed');
                }

                return $this->redirectToRoute('restaurant_manage', ['token' => $token]);
            } catch (RestaurantException $e) {
                $error = $e->getMessage();
            }
        }

        $day = $this->day($request->query->get('jour')) ?? new \DateTimeImmutable($reservation->getDay());
        $covers = max(1, $request->query->getInt('couverts', $reservation->getCovers()));

        return $this->render('@Restaurant/client/manage.html.twig', [
            'layout' => $this->layout,
            'reservation' => $reservation,
            'changeable' => $changeable,
            'fresh' => $request->query->getBoolean('nouvelle'),
            'error' => $error,
            'day' => $day->format('Y-m-d'),
            'covers' => $covers,
            'slots' => $changeable ? $this->availability->slotsByService($day, $covers) : [],
            'min' => $this->availability->today()->format('Y-m-d'),
            'max' => $this->availability->lastDay()->format('Y-m-d'),
            'max_covers' => $this->availability->maxCovers(),
        ], new Response(null, $error ? 422 : 200));
    }

    #[Route('/reservation/{token}/agenda.ics', name: 'restaurant_manage_ics', requirements: ['token' => '[A-Za-z0-9_\-]{20,64}'], methods: ['GET'])]
    public function ics(string $token): Response
    {
        $reservation = $this->reservations->findOneByToken($token) ?? throw $this->createNotFoundException();
        $title = $this->translator->trans('mail.calendar_title', [], 'restaurant');

        return new Response($this->book->ics($reservation, $title), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="reservation.ics"',
        ]);
    }

    private function day(?string $day): ?\DateTimeImmutable
    {
        if (!$day || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        return $date ?: null;
    }
}
