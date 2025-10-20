<?php

declare(strict_types=1);

namespace BSP\Planner\Bundles;

use JsonSerializable;

final class BundleDefinition implements JsonSerializable
{
    private string $id;

    private string $label;

    /**
     * @var array<mixed>
     */
    private array $items;

    /**
     * @var array<string, mixed>
     */
    private array $meta;

    /**
     * @var array<string, mixed>
     */
    private array $payloadOverrides;

    /**
     * @param array<mixed> $items
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $payloadOverrides
     */
    public function __construct(string $id, string $label, array $items = array(), array $meta = array(), array $payloadOverrides = array())
    {
        $this->id               = $id;
        $this->label            = $label;
        $this->items            = $items;
        $this->meta             = $meta;
        $this->payloadOverrides = $payloadOverrides;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $id      = (string) ($config['id'] ?? '');
        $label   = (string) ($config['label'] ?? $id);
        $items   = is_array($config['items'] ?? null) ? (array) $config['items'] : array();
        $meta    = is_array($config['meta'] ?? null) ? (array) $config['meta'] : array();
        $payload = array();

        if (isset($config['payload']) && is_array($config['payload'])) {
            $payload = (array) $config['payload'];
        } elseif (isset($config['payload_overrides']) && is_array($config['payload_overrides'])) {
            $payload = (array) $config['payload_overrides'];
        } elseif (isset($config['compose_payload']) && is_array($config['compose_payload'])) {
            $payload = (array) $config['compose_payload'];
        }

        if ('' === $id) {
            throw new \InvalidArgumentException('BundleDefinition requires a non-empty id.');
        }

        return new self($id, '' === $label ? $id : $label, $items, $meta, $payload);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @return array<mixed>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayloadOverrides(): array
    {
        return $this->payloadOverrides;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array(
            'id'      => $this->id,
            'label'   => $this->label,
            'items'   => $this->items,
            'meta'    => $this->meta,
            'payload' => $this->toPayload(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = array(
            'mode'      => 'request',
            'bundle_id' => $this->id,
            'items'     => $this->items,
            'meta'      => array_merge(
                array(
                    'bundle_label' => $this->label,
                ),
                $this->meta
            ),
        );

        foreach ($this->payloadOverrides as $key => $value) {
            if ('bundle_id' === $key) {
                continue;
            }

            if ('meta' === $key && is_array($value)) {
                $payload['meta'] = array_merge($payload['meta'], $value);
                continue;
            }

            $payload[$key] = $value;
        }

        $payload['meta']['bundle_label'] = $this->label;

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}