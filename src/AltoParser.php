<?php

declare(strict_types=1);

namespace Survos\AltoRaw;

use Survos\AltoRaw\Contract\Block;
use Survos\AltoRaw\Contract\Document;
use Survos\AltoRaw\Contract\Element;
use Survos\AltoRaw\Contract\Line;
use Survos\AltoRaw\Contract\NodeIdentity;
use Survos\AltoRaw\Contract\Page;
use Survos\AltoRaw\Contract\Word;

/** Parse once into typed source contracts, retaining ordered extension elements and attributes. */
final class AltoParser
{
    public function __construct(private readonly XmlTree $xml = new XmlTree())
    {
    }

    public function parse(string $xml, bool $keepXml = false): Document
    {
        return $this->parseDocument($this->xml->document($xml), hash('sha256', $xml), $keepXml ? $xml : null);
    }

    /** Capture source contracts before another consumer applies DOM layout repairs. */
    public function parseDocument(\DOMDocument $document, string $sourceSha256, ?string $originalXml = null): Document
    {
        if (null !== $document->doctype || null === $document->documentElement) {
            throw new \UnexpectedValueException('Expected ALTO without a DTD.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $sourceSha256)
            || (null !== $originalXml && hash('sha256', $originalXml) !== $sourceSha256)) {
            throw new \InvalidArgumentException('ALTO source checksum does not match.');
        }
        $root = $document->documentElement;
        if ('alto' !== $root->localName) {
            throw new \UnexpectedValueException('Expected ALTO XML.');
        }
        $elements = [];
        $index = 0;
        $this->walk($root, null, 0, $root->namespaceURI, null, null, $index, $elements);
        $pages = array_values(array_filter($elements, static fn ($node) => $node instanceof Page));
        if (1 !== count($pages)) {
            throw new \UnexpectedValueException('Expected one ALTO page.');
        }

        return new Document($pages[0], $elements, $sourceSha256, $originalXml);
    }

    private function walk(\DOMNode $node, ?int $parent, int $order, ?string $altoNamespace,
        ?int $block, ?int $line, int &$wordIndex, array &$elements): void
    {
        $id = count($elements) + 1;
        if ($node instanceof \DOMText) {
            $elements[] = new Element(new NodeIdentity($id, $parent, $order, '#text', null), [], text: $node->data);

            return;
        }
        $attributes = $namespaces = [];
        foreach ($node->attributes as $attribute) {
            $attributes[$attribute->nodeName] = $attribute->value;
            if (null !== $attribute->namespaceURI) {
                $namespaces[$attribute->nodeName] = $attribute->namespaceURI;
            }
        }
        $identity = new NodeIdentity($id, $parent, $order, $node->nodeName, $node->namespaceURI);
        $kind = $node->namespaceURI === $altoNamespace ? $node->localName : null;
        if ('TextBlock' === $kind) {
            $block = $id;
        }
        if ('TextLine' === $kind) {
            $line = $id;
        }
        if ('String' === $kind) {
            $word = new Word($identity, $attributes, $namespaces, $block, $line);
            $valid = null !== $word->box() && $word->width > 0 && $word->height > 0;
            $element = new Word($identity, $attributes, $namespaces, $block, $line, $valid ? $wordIndex++ : null);
        } else {
            $element = match ($kind) {
                'Page' => new Page($identity, $attributes, $namespaces),
                'TextBlock', 'ComposedBlock', 'Illustration', 'GraphicalElement' => new Block($identity, $attributes, $namespaces),
                'TextLine' => new Line($identity, $attributes, $namespaces),
                default => new Element($identity, $attributes, $namespaces),
            };
        }
        $elements[] = $element;
        $position = 0;
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement || ($child instanceof \DOMText && '' !== trim($child->data))) {
                $this->walk($child, $id, $position++, $altoNamespace, $block, $line, $wordIndex, $elements);
            }
        }
    }
}
