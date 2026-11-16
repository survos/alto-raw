<?php

declare(strict_types=1);

namespace Survos\AltoRaw\Contract;

final readonly class Word extends Region
{
    public ?string $content;
    public ?float $confidence;
    public ?string $characterConfidence;
    public ?string $substitutionType;
    public ?string $substitutionContent;

    public function __construct(NodeIdentity $identity, array $attributes, array $attributeNamespaces = [],
        public ?int $blockId = null, public ?int $lineId = null, public ?int $wordIndex = null)
    {
        parent::__construct($identity, $attributes, $attributeNamespaces);
        $this->content = $attributes['CONTENT'] ?? null;
        $this->confidence = $this->number('WC');
        $this->characterConfidence = $attributes['CC'] ?? null;
        $this->substitutionType = $attributes['SUBS_TYPE'] ?? null;
        $this->substitutionContent = $attributes['SUBS_CONTENT'] ?? null;
    }

    public function searchableText(): string
    {
        return $this->substitutionContent ?? $this->content
            ?? throw new \UnexpectedValueException('ALTO word lacks CONTENT.');
    }
}
