<?php

declare(strict_types=1);

namespace Survos\AltoRaw\Contract;

final readonly class Line extends Region
{
    public ?string $baseline;

    public function __construct(NodeIdentity $identity, array $attributes, array $attributeNamespaces = [])
    {
        parent::__construct($identity, $attributes, $attributeNamespaces);
        $this->baseline = $attributes['BASELINE'] ?? null;
    }
}
