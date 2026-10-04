<?php

namespace Base\Restaurant\Service;

use Base\Marketplace\Entity\Order\Method\ShippingMethod;
use Base\Marketplace\Service\Shipping;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Who carries the chilled parcels: the shop's shipping method named by
 * restaurant.parcel.shipping_method (its slug), whose carrier is a
 * glitchr/omnibus gateway - omnibus/chronopost with the Chronofresh product.
 * Without the method, without the package or without the carrier's account,
 * there is no chilled parcel and the mode is not offered.
 */
class ParcelCarrier
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Shipping $shipping,
        #[Autowire('%restaurant.parcel.shipping_method%')] private readonly ?string $slug = null,
    ) {
    }

    public function shippingMethod(): ?ShippingMethod
    {
        return $this->slug ? $this->entityManager->getRepository(ShippingMethod::class)->findOneBy(['slug' => $this->slug]) : null;
    }

    public function isConfigured(): bool
    {
        $method = $this->shippingMethod();
        if (!$method?->hasCarrier()) {
            return false;
        }
        try {
            // A gateway named but without its account does not build.
            return null !== $this->shipping->carrierFor($method);
        } catch (\Throwable) {
            return false;
        }
    }
}
