<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder\Registry;

use BSP\ExperienceBuilder\Contract\ModuleTypeInterface;
use InvalidArgumentException;

final class ModuleRegistry
{
    /** @var array<string,ModuleTypeInterface> */
    private array $types = array();

    public function register(ModuleTypeInterface $moduleType): void
    {
        $type = sanitize_key($moduleType->type());
        if ($type === '') {
            throw new InvalidArgumentException('A module type requires a non-empty key.');
        }
        if (isset($this->types[$type])) {
            throw new InvalidArgumentException(sprintf('Module type "%s" is already registered.', $type));
        }

        $this->types[$type] = $moduleType;
    }

    public function has(string $type): bool
    {
        return isset($this->types[sanitize_key($type)]);
    }

    public function get(string $type): ?ModuleTypeInterface
    {
        return $this->types[sanitize_key($type)] ?? null;
    }

    /** @return array<string,array<string,mixed>> */
    public function definitions(): array
    {
        $definitions = array();
        foreach ($this->types as $type => $moduleType) {
            $definitions[$type] = $moduleType->definition();
        }

        return $definitions;
    }
}
