<?php

namespace Base\Restaurant\Tests;

use Base\Restaurant\Form\BookingType;
use Base\Restaurant\Model\Booking;
use Base\Service\FormGuard;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The reservation form is guarded as glitchr/omnibase guards a form (option `guard`, action "book"), in
 * place of its own `website` trap and `startedAt` time: a filled trap, a reservation sent faster than
 * its delay and a missing captcha token are refused on the form; a reservation made as a person makes
 * it goes through. Without a captcha (no glitchr/omniguard: `challenge: false`), the trap and the time
 * alone. Run by a host application's PHPUnit, its test captcha being omniguard's "fixed" gateway.
 */
final class BookingGuardTest extends KernelTestCase
{
    protected function setUp(): void
    {
        $_SERVER['KERNEL_CLASS'] ??= $_ENV['KERNEL_CLASS'] ?? 'App\\Kernel';
        if (!class_exists($_SERVER['KERNEL_CLASS'])) {
            self::markTestSkipped('Needs a host application (its kernel).');
        }
        if (!class_exists(FormGuard::class)) {
            self::markTestSkipped('Needs a glitchr/omnibase with the forms\' guard.');
        }
        self::bootKernel();
        static::getContainer()->get('request_stack')->push(Request::create('https://localhost/reserver', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']));
    }

    /** @param array<string, mixed> $guard */
    private function form(array $guard = []): FormInterface
    {
        $options = ['csrf_protection' => false];
        if ($guard) {
            $options['guard'] = $guard + ['action' => 'book'];
        }

        return static::getContainer()->get('form.factory')->create(BookingType::class, new Booking(), $options);
    }

    /** @param array<string, ?string> $overrides */
    private function send(FormInterface $form, array $overrides = []): FormInterface
    {
        $data = [
            'day' => (new \DateTimeImmutable('+3 days'))->format('Y-m-d'),
            'covers' => '2',
            'time' => '20:00',
            'name' => 'Camille Weber',
            'email' => 'camille@example.org',
            'phone' => '06 12 34 56 78',
            'guard_website' => '',
            'guard_opened' => static::getContainer()->get(FormGuard::class)->stamp(time() - 10),
        ];
        if ($form->has('guard_captcha')) {
            $data['guard_captcha'] = 'omniguard-fixed-token';
        }
        $data = array_filter($overrides + $data, static fn ($value) => null !== $value);
        $form->submit(array_intersect_key($data, iterator_to_array($form)), false);

        return $form;
    }

    /** @return list<string> what refused the form: the guard's reasons, or the field with an error */
    private function refusals(FormInterface $form): array
    {
        $found = [];
        foreach ($form->getErrors(true) as $error) {
            $found[] = \is_string($error->getCause()) ? $error->getCause() : (string) $error->getOrigin()?->getName();
        }

        return $found;
    }

    public function testTheFormIsGuardedInPlaceOfItsOwnTrap(): void
    {
        $form = $this->form();
        self::assertSame('book', $form->getConfig()->getOption('guard')['action']);
        self::assertFalse($form->has('website'), 'the form\'s own trap is gone');
        self::assertFalse($form->has('startedAt'), 'and its own time');
        self::assertTrue($form->has(FormGuard::TRAP_FIELD));
        self::assertTrue($form->has(FormGuard::STAMP_FIELD));
    }

    public function testAReservationMadeAsAPersonMakesItGoesThrough(): void
    {
        $form = $this->send($this->form());
        self::assertTrue($form->isValid(), implode(', ', $this->refusals($form)));
    }

    public function testAFilledTrapIsRefused(): void
    {
        $form = $this->send($this->form(), ['guard_website' => 'https://spam.example']);
        self::assertFalse($form->isValid());
        self::assertContains(FormGuard::TRAPPED, $this->refusals($form));
    }

    public function testAReservationSentTooFastIsRefused(): void
    {
        $form = $this->send($this->form(['min_delay' => 3]), ['guard_opened' => static::getContainer()->get(FormGuard::class)->stamp(time() - 1)]);
        self::assertFalse($form->isValid());
        self::assertContains(FormGuard::TOO_FAST, $this->refusals($form));
    }

    public function testAReservationWithoutTheCaptchasTokenIsRefused(): void
    {
        $form = $this->form();
        if (!$form->has('guard_captcha')) {
            self::markTestSkipped('The host application has no captcha (glitchr/omniguard).');
        }
        $this->send($form, ['guard_captcha' => '']);
        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, $form->get('guard_captcha')->getErrors()->count(), 'refused on the captcha');
    }

    public function testWithoutACaptchaTheTrapAndTheTimeAlone(): void
    {
        $form = $this->send($this->form(['challenge' => false]));
        self::assertFalse($form->has('guard_captcha'));
        self::assertTrue($form->isValid(), implode(', ', $this->refusals($form)));

        self::assertFalse($this->send($this->form(['challenge' => false]), ['guard_website' => 'x'])->isValid(), 'the trap still holds');
        self::assertFalse($this->send($this->form(['challenge' => false, 'min_delay' => 3]), ['guard_opened' => static::getContainer()->get(FormGuard::class)->stamp(time())])->isValid(), 'the time still holds');
    }
}
