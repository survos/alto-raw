<?php

declare(strict_types=1);

namespace Survos\AltoRaw\Contract;

final readonly class Page extends Region
{
    public ?int $physicalImageNumber;
    public ?string $printedImageNumber;
    public ?string $quality;
    public ?string $position;

    public function __construct(NodeIdentity $identity, array $attributes, array $attributeNamespaces = [])
    {
        parent::__construct($identity, $attributes, $attributeNamespaces);
        if (null === $this->width || null === $this->height || $this->width <= 0 || $this->height <= 0) {
            throw new \UnexpectedValueException('Invalid ALTO page dimensions.');
        }
        $this->physicalImageNumber = $this->integer('PHYSICAL_IMG_NR');
        $this->printedImageNumber = $attributes['PRINTED_IMG_NR'] ?? null;
        $this->quality = $attributes['QUALITY'] ?? null;
        $this->position = $attributes['POSITION'] ?? null;
    }
}
