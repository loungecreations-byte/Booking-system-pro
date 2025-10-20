<?php
declare(strict_types=1);

namespace BSP\Planner\Vendor;

final class CityGuideProfile
{
    public int $id;
    public string $name;
    public string $icalUrl;
    public string $timezone;
    public ?string $note;
    public ?string $lastSync;
    public string $status;

    public function __construct(
        int $id,
        string $name,
        string $icalUrl,
        string $timezone,
        ?string $note = null,
        ?string $lastSync = null,
        string $status = 'idle'
    ) {
        $this->id       = $id;
        $this->name     = $name;
        $this->icalUrl  = $icalUrl;
        $this->timezone = $timezone;
        $this->note     = $note;
        $this->lastSync = $lastSync;
        $this->status   = $status;
    }
}
