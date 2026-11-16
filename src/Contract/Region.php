<?php

declare(strict_types=1);

namespace Survos\AltoRaw\Contract;

/** Coordinates retain the document's measurement unit and fractional precision. */
readonly class Region extends Element
{
    public ?float $x;
    public ?float $y;
    public ?float $width;
    public ?float $height;
    public ?float $rotation;
    public ?string $styleRefs;
    public ?string $tagRefs;
    public ?string $language;

    public function __construct(NodeIdentity $identity, array $attributes, array $attributeNamespaces = [])
    {
        parent::__construct($identity, $attributes, $attributeNamespaces);
        $this->x = $this->number('HPOS');
        $this->y = $this->number('VPOS');
        $this->width = $this->number('WIDTH');
        $this->height = $this->number('HEIGHT');
        $this->rotation = $this->number('ROTATION');
        $this->styleRefs = $attributes['STYLEREFS'] ?? null;
        $this->tagRefs = $attributes['TAGREFS'] ?? null;
        $this->language = $attributes['LANG'] ?? null;
    }

    /** @return list<float>|null */
    public function box(): ?array
    {
        return null === $this->x || null === $this->y || null === $this->width || null === $this->height
            ? null : [$this->x, $this->y, $this->width, $this->height];
    }
}
