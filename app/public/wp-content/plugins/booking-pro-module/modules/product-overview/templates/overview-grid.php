<?php

declare(strict_types=1);

if (! isset($component['defaultView'])) {
    $component['defaultView'] = 'grid';
}

include __DIR__ . '/activity-overview.php';
