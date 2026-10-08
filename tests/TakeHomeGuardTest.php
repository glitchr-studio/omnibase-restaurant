<?php

namespace Base\Restaurant\Tests;

use Base\Restaurant\Form\TakeHomeOrderType;
use Base\Restaurant\Model\TakeHomeOrder;
use Base\Service\FormGuard;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The take-home order (/traiteur/commander) is a form - Form\TakeHomeOrderType, a root without a name,
 * its fields keeping the page's names - guarded as glitchr/omnibase guards a public form (option
 * `guard`, action "takehome") in place of the page's own `website` trap: a filled trap, an order sent
 * faster than its delay and a missing captcha token are refused on the form; an order placed as a
 * person places it goes through, into Model\TakeHomeOrder. Run by a host application's PHPUnit, its
 * test captcha being omniguard's "fixed" gateway.
 */
final class TakeHomeGuardTest extends KernelTestCase
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
        static::getContainer()->get('request_stack')->push(Request::create('https://localhost/traiteur/commander', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']));
    }

    /** @param array<string, mixed> $guard */
    private function form(array $guard = []): FormInterface
    {
        $options = ['csrf_protection' => false];
        if ($guard) {
            $options['guard'] = $guard + ['action' => 'takehome'];
        }

        return static::getContainer()->get('form.factory')->createNamed('', TakeHomeOrderType::class, new TakeHomeOrder(), $options);
    }

    /** @param array<string, mixed> $overrides */
    private function send(FormInterface $form, array $overrides = []): FormInterface
    {
        $data = [
            'lines' => ['12' => '2', '15' => '0'],
            'mode' => 'pickup',
            'day' => (new \DateTimeImmutable('+3 days'))->format('Y-m-d'),
            'slot' => '18:00-18:30',
            'name' => 'Léa Martin',
            'email' => 'lea.martin@example.org',
            'phone' => '06 11 22 33 44',
            'guard_website' => '',
            'guard_opened' => static::getContainer()->get(FormGuard::class)->stamp(time() - 10),
        ];
        if ($form->has('guard_captcha')) {
            $data['guard_captcha'] = 'omniguard-fixed-token';
        }
        $form->submit(array_filter($overrides + $data, static fn ($value) => null !== $value), false);

        return $form;
    }

    /** @return list<string> */
    private function refusals(FormInterface $form): array
    {
        $found = [];
        foreach ($form->getErrors(true) as $error) {
            $found[] = \is_string($error->getCause()) ? $error->getCause() : (string) $error->getOrigin()?->getName();
        }

        return $found;
    }

    public function testTheOrderIsAGuardedFormKeepingThePagesNames(): void
    {
        $form = $this->form();
        self::assertSame('', $form->getName(), 'email, lines[12], day: the page\'s names');
        self::assertSame('takehome', $form->getConfig()->getOption('guard')['action']);
        self::assertFalse($form->has('website'), 'the page\'s own trap is gone');
        self::assertTrue($form->has(FormGuard::TRAP_FIELD));
        self::assertTrue($form->has(FormGuard::STAMP_FIELD));
        self::assertFalse(property_exists(TakeHomeOrder::class, 'website'));
    }

    public function testAnOrderPlacedAsAPersonPlacesItGoesThrough(): void
    {
        $form = $this->send($this->form());
        self::assertTrue($form->isValid(), implode(', ', $this->refusals($form)));
        $order = $form->getData();
        self::assertInstanceOf(TakeHomeOrder::class, $order);
        self::assertSame([12 => 2], $order->quantities());
        self::assertSame(['pickup', 'lea.martin@example.org', '18:00-18:30'], [$order->mode, $order->email, $order->slot]);
    }

    public function testAFilledTrapIsRefused(): void
    {
        $form = $this->send($this->form(), ['guard_website' => 'https://spam.example']);
        self::assertFalse($form->isValid());
        self::assertContains(FormGuard::TRAPPED, $this->refusals($form));
    }

    public function testAnOrderSentTooFastIsRefused(): void
    {
        $form = $this->send($this->form(['min_delay' => 3]), ['guard_opened' => static::getContainer()->get(FormGuard::class)->stamp(time() - 1)]);
        self::assertFalse($form->isValid());
        self::assertContains(FormGuard::TOO_FAST, $this->refusals($form));
    }

    public function testAnOrderWithoutTheCaptchasTokenIsRefused(): void
    {
        $form = $this->form();
        if (!$form->has('guard_captcha')) {
            self::markTestSkipped('The host application has no captcha (glitchr/omniguard).');
        }
        $this->send($form, ['guard_captcha' => '']);
        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, $form->get('guard_captcha')->getErrors()->count(), 'refused on the captcha');
    }

    public function testAWrongAddressIsRefusedByTheModelsConstraints(): void
    {
        $form = $this->send($this->form(), ['email' => 'pas-une-adresse']);
        self::assertFalse($form->isValid());
        self::assertContains('email', $this->refusals($form));
    }
}
