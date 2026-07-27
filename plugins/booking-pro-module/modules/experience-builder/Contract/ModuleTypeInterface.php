<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder\Contract;

interface ModuleTypeInterface
{
    public function type(): string;

    /** @return array<string,mixed> */
    public function definition(): array;

    /** @param array<string,mixed> $module @return array<string,mixed> */
    public function normalize(array $module): array;

    /** @param array<string,mixed> $module @return array<int,array<string,string>> */
    public function validate(array $module): array;
}
