<?php

namespace Base\Restaurant\Omnifood;

use Base\Service\SettingBagInterface;
use Omnifood\MenuInterface;
use Omnifood\NotifiableInterface;
use Omnifood\OrdersInterface;
use Omnifood\PlatformInterface;
use Omnifood\Registry;
use Omnifood\ReservationsInterface;
use Omnifood\StoreInterface;

/**
 * The restaurant's platforms (glitchr/omnifood's Registry), each built with
 * the keys typed in the back office over the configured ones - settings
 * api.food.<platform>.<option> (Settings\FoodKeysSection declares the
 * fields), as omnibase/marketplace does for its payment gateways.
 */
class Platforms
{
    public const SETTINGS = 'api.food';

    /** The options a back office types, by platform factory: the rest stays in the configuration. */
    public const KEYS = [
        'ubereats' => ['client_id', 'client_secret', 'store_id'],
        'deliveroo' => ['client_id', 'client_secret', 'brand_id', 'site_id', 'webhook_secret'],
        'justeat' => ['api_key', 'restaurant', 'webhook_secret'],
        'thefork' => ['client_id', 'client_secret', 'restaurant_id', 'webhook_token'],
        'zenchef' => ['token', 'restaurant_id'],
    ];

    /** @var array<string, PlatformInterface> */
    private array $built = [];

    public function __construct(
        private readonly Registry $registry,
        private readonly ?SettingBagInterface $settings = null,
    ) {
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->registry->names();
    }

    public function has(string $name): bool
    {
        return $this->registry->has($name);
    }

    public function get(string $name): PlatformInterface
    {
        return $this->built[$name] ??= $this->registry->create($name, $this->typed($name));
    }

    /** The platform's factory name (its config: "ubereats", "thefork"...). */
    public function factoryOf(string $name): ?string
    {
        foreach (self::KEYS as $factory => $keys) {
            if ($name === $factory || str_starts_with($name, $factory)) {
                return $factory;
            }
        }

        return null;
    }

    /**
     * @template T of PlatformInterface
     *
     * @param class-string<T> $capability
     *
     * @return array<string, T> name => platform, those that do it
     */
    public function having(string $capability): array
    {
        $found = [];
        foreach ($this->names() as $name) {
            try {
                $platform = $this->get($name);
            } catch (\Throwable) {
                continue; // a platform without its keys yet
            }
            if ($platform instanceof $capability) {
                $found[$name] = $platform;
            }
        }

        return $found;
    }

    public function orders(string $name): ?OrdersInterface
    {
        return $this->capable($name, OrdersInterface::class);
    }

    public function reservations(string $name): ?ReservationsInterface
    {
        return $this->capable($name, ReservationsInterface::class);
    }

    public function notifiable(string $name): ?NotifiableInterface
    {
        return $this->capable($name, NotifiableInterface::class);
    }

    public function store(string $name): ?StoreInterface
    {
        return $this->capable($name, StoreInterface::class);
    }

    public function menu(string $name): ?MenuInterface
    {
        return $this->capable($name, MenuInterface::class);
    }

    /**
     * The options typed for this platform, the empty ones left out.
     *
     * @return array<string, string>
     */
    public function typed(string $name): array
    {
        if (null === $this->settings) {
            return [];
        }
        try {
            $tree = $this->settings->get(self::SETTINGS.'.'.$name);
        } catch (\Throwable) {
            return [];
        }
        $typed = [];
        foreach ((array) $tree as $option => $node) {
            $value = \is_array($node) ? ($node['_self'] ?? null) : $node;
            if ('_self' !== $option && \is_scalar($value) && '' !== trim((string) $value)) {
                $typed[(string) $option] = trim((string) $value);
            }
        }

        return $typed;
    }

    /**
     * @template T
     *
     * @param class-string<T> $capability
     *
     * @return T|null
     */
    private function capable(string $name, string $capability): ?object
    {
        if (!$this->has($name)) {
            return null;
        }
        $platform = $this->get($name);

        return $platform instanceof $capability ? $platform : null;
    }
}
