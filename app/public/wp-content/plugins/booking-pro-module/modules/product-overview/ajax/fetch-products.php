<?php

declare(strict_types=1);

use SBDP\Modules\ProductOverview\ProductOverviewComponent;

/** @var ProductOverviewComponent|null $component */
/** @var array<string, mixed>|null $filtersForAjax */

if (! isset($component) || ! $component instanceof ProductOverviewComponent) {
    return array(
        'products'   => array(),
        'pagination' => array(
            'page'       => 1,
            'perPage'    => 0,
            'total'      => 0,
            'totalPages' => 1,
        ),
    );
}

$filters = isset($filtersForAjax) && is_array($filtersForAjax)
    ? $filtersForAjax
    : array();

return $component->buildProductResponse($filters);
