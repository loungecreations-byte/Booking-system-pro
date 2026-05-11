<?php

declare(strict_types=1);

namespace SBDP;

final class BookingDispatcher
{
    /**
     * @var array<string, array<int, callable>>
     */
    private array $listeners = array();

    public function on(string $event, callable $listener): void
    {
        $event = $this->normalizeEventName($event);
        if ($event === '') {
            return;
        }

        if (! array_key_exists($event, $this->listeners)) {
            $this->listeners[$event] = array();
        }

        $this->listeners[$event][] = $listener;
    }

    /**
     * Dispatch an event and return the most recent non-null listener result.
     *
     * @param array<string, mixed> $payload
     *
     * @return mixed
     */
    public function dispatch(string $event, array $payload = array())
    {
        $event  = $this->normalizeEventName($event);
        $result = null;

        foreach ($this->listeners[$event] ?? array() as $listener) {
            $response = $listener($payload);
            if ($response !== null) {
                $result = $response;
                if (is_array($response)) {
                    $payload = $response;
                }
            }
        }

        return $result ?? $payload;
    }

    private function normalizeEventName(string $event): string
    {
        return strtolower(trim($event));
    }
}
