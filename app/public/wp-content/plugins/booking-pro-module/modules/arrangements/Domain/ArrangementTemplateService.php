<?php

declare(strict_types=1);

namespace SBDP\Modules\Arrangements\Domain;

final class ArrangementTemplateService
{
    public function __construct(private ArrangementRepository $repository = new ArrangementRepository())
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTemplates(): array
    {
        return array_values(array_filter(
            $this->repository->query(array('arrangement_type' => 'fixed')),
            static fn (array $item): bool => (string) ($item['creation_mode'] ?? '') === 'template'
                || (string) ($item['creation_mode'] ?? '') === 'fixed'
        ));
    }

    public function createInstanceFromTemplate(int $templateId, array $overrides = array()): ?array
    {
        $template = $this->repository->find($templateId);
        if (! is_array($template)) {
            return null;
        }

        return (new ArrangementInstanceFactory())->createFromTemplate($template, $overrides);
    }
}
