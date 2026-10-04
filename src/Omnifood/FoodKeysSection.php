<?php

namespace Base\Restaurant\Omnifood;

use Base\Admin\Settings\SettingsSectionInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The platforms' keys on omnibase/admin's API keys page, one group per
 * platform the site configures (omnifood.platforms): typed there
 * (api.food.<platform>.<option>), they win over the configuration -
 * Platforms builds each platform with them.
 */
#[AsTaggedItem(priority: 40)]
final class FoodKeysSection implements SettingsSectionInterface
{
    private const LABELS = [
        'ubereats' => 'Uber Eats', 'deliveroo' => 'Deliveroo', 'justeat' => 'Just Eat', 'thefork' => 'TheFork', 'zenchef' => 'Zenchef',
    ];

    public function __construct(private readonly Platforms $platforms)
    {
    }

    public function getPage(): string
    {
        return self::API_KEYS;
    }

    public function getFields(): array
    {
        $fields = [];
        foreach ($this->platforms->names() as $name) {
            $factory = $this->platforms->factoryOf($name);
            foreach (Platforms::KEYS[$factory] ?? [] as $option) {
                $fields[Platforms::SETTINGS.'.'.$name.'.'.$option] = [
                    'required' => false,
                    'label' => sprintf('%s — %s', self::LABELS[$factory] ?? $name, $option),
                ];
            }
        }

        return $fields;
    }
}
