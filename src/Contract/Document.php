<?php

declare(strict_types=1);

namespace Survos\AltoRaw\Contract;

/** One source ALTO page, with all nodes in source order. No dataset or storage dependencies. */
final readonly class Document
{
    /** @param list<Element> $elements */
    public function __construct(public Page $page, public array $elements, public string $sourceSha256,
        public ?string $originalXml = null)
    {
    }

    /** @return \Generator<Word> */
    public function words(): \Generator
    {
        foreach ($this->elements as $element) {
            if ($element instanceof Word && null !== $element->wordIndex) {
                yield $element;
            }
        }
    }

    /** Compatibility projection for the earlier nested raw format; the typed writer does not use it. */
    public function structure(): array
    {
        $children = [];
        foreach ($this->elements as $node) {
            $children[$node->identity->parentId ?? 0][] = $node;
        }

        return $this->structureNode($this->elements[0], $children, null);
    }

    private function structureNode(Element $element, array $children, ?string $parentNamespace): array
    {
        $node = [];
        if ($element->identity->namespace !== $parentNamespace) {
            $node['_namespace'] = $element->identity->namespace;
        }
        foreach ($element->attributes as $name => $value) {
            $node['@'.$name] = $value;
        }
        if ([] !== $element->attributeNamespaces) {
            $node['_attributeNamespaces'] = $element->attributeNamespaces;
        }
        $order = [];
        foreach ($children[$element->identity->id] ?? [] as $child) {
            $name = $child->identity->name;
            $node[$name][] = '#text' === $name ? $child->text : $this->structureNode($child, $children, $element->identity->namespace);
            $order[] = $name;
        }
        if (count(array_unique($order)) > 1) {
            $node['_order'] = $order;
        }

        return $node;
    }
}
