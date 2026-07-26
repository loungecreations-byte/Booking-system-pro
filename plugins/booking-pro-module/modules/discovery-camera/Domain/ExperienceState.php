<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Domain;

final class ExperienceState
{
    public const CREATED = 'created';
    public const ANALYZING = 'analyzing';
    public const REVIEW = 'review';
    public const PARTIAL = 'partial';
    public const PASSED = 'passed';
    public const FAILED = 'failed';

    /** @var array<string,array<int,string>> */
    private const TRANSITIONS = array(
        self::CREATED => array(self::ANALYZING),
        self::ANALYZING => array(self::REVIEW, self::PARTIAL, self::PASSED, self::FAILED),
        self::REVIEW => array(self::PASSED, self::FAILED),
        self::PARTIAL => array(self::ANALYZING, self::REVIEW, self::PASSED, self::FAILED),
        self::FAILED => array(self::ANALYZING, self::REVIEW, self::PASSED),
        self::PASSED => array(),
    );

    public static function canTransition(string $from, string $to): bool
    {
        return $from === $to || in_array($to, self::TRANSITIONS[$from] ?? array(), true);
    }

    /** @return array<int,string> */
    public static function all(): array
    {
        return array_keys(self::TRANSITIONS);
    }
}
