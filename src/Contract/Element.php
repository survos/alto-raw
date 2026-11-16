<?php

declare(strict_types=1);

namespace Survos\AltoRaw\Contract;

/** Every source attribute survives, including fields unknown to this version. */
readonly class Element
{
    public ?string $sourceId;

    /** @param array<string,string> $attributes @param array<string,string> $attributeNamespaces */
    public function __construct(
        public NodeIdentity $identity,
        public array $attributes,
        public array $attributeNamespaces = [],
        public ?string $text = null,
    ) {
        $this->sourceId = $attributes['ID'] ?? null;
    }

    protected function number(string $name): ?float
    {
        if (!array_key_exists($name, $this->attributes)) {
            return null;
        }
        $value = $this->attributes[$name];
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new \UnexpectedValueException('Invalid ALTO '.$name);
        }

        return (float) $value;
    }

    protected function integer(string $name): ?int
    {
        $value = $this->number($name);
        if (null === $value) {
            return null;
        }
        if ($value !== (float) (int) $value) {
            throw new \UnexpectedValueException('Non-integral ALTO '.$name);
        }

        return (int) $value;
    }
}
