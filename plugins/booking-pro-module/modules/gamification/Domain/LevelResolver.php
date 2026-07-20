<?php
declare(strict_types=1);
namespace BSP\Gamification\Domain;

final class LevelResolver
{
    private const LEVELS = array(
        1 => array('name' => 'Nieuwsgierige bezoeker', 'xp' => 0),
        2 => array('name' => 'Stadsverkenner', 'xp' => 250),
        3 => array('name' => 'Bossche kenner', 'xp' => 750),
        4 => array('name' => 'Ervaringsjager', 'xp' => 1500),
        5 => array('name' => 'Route-expert', 'xp' => 3000),
        6 => array('name' => 'Bossche insider', 'xp' => 5000),
        7 => array('name' => 'Stadambassadeur', 'xp' => 8000),
        8 => array('name' => 'Meester van Den Bosch', 'xp' => 12500),
    );

    /** @return array<string,mixed> */
    public function resolve(int $xp): array
    {
        $xp = max(0, $xp); $levels = $this->levels(); $current = 1;
        foreach ($levels as $number => $level) { if ($xp >= (int) $level['xp']) { $current = (int) $number; } }
        $level = $levels[$current]; $next = $levels[$current + 1] ?? null;
        $base = (int) $level['xp']; $target = $next ? (int) $next['xp'] : $base;
        return array(
            'number' => $current, 'name' => (string) $level['name'], 'xp' => $xp,
            'base_xp' => $base, 'next_xp' => $next ? $target : null,
            'next_name' => $next ? (string) $next['name'] : null,
            'progress' => $next ? min(100, (int) floor((($xp - $base) / max(1, $target - $base)) * 100)) : 100,
        );
    }

    /** @return array<int,array{name:string,xp:int}> */
    public function levels(): array
    {
        $levels = self::LEVELS;
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('bsp/gamification/levels', $levels);
            if (is_array($filtered) && $filtered !== array()) { $levels = $filtered; }
        }
        return $levels;
    }
}
