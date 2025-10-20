<?php

declare(strict_types=1);

namespace BSP\Planner\Bundles;

final class BundleRegistry
{
    /**
     * @var array<string, BundleDefinition>
     */
    private array $bundles = array();

    public function register(BundleDefinition $bundle): void
    {
        $this->bundles[$bundle->getId()] = $bundle;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function registerFromArray(array $config): BundleDefinition
    {
        $bundle = BundleDefinition::fromArray($config);
        $this->register($bundle);

        return $bundle;
    }

    public function has(string $id): bool
    {
        return isset($this->bundles[$id]);
    }

    public function find(string $id): ?BundleDefinition
    {
        return $this->bundles[$id] ?? null;
    }

    /**
     * @return BundleDefinition[]
     */
    public function all(): array
    {
        return array_values($this->bundles);
    }

    public function clear(): void
    {
        $this->bundles = array();
    }
}
