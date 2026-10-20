<?php

declare(strict_types=1);

namespace Survos\AltoRaw;

/** Python v2 tree compatibility, NOT an archival serialization of XML. */
final class XmlTree
{
    public function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET)) {
                throw new \UnexpectedValueException('Invalid XML.');
            }
            // Never resolve external entities or accept a DTD in raw capture inputs.
            if (null !== $document->doctype) {
                throw new \UnexpectedValueException('DTD declarations are not supported.');
            }
            $root = $document->documentElement;
            if (null === $root) {
                throw new \UnexpectedValueException('Missing XML root.');
            }
            $tree = $this->element($root, $root->namespaceURI);
            $tree['ns'] = $root->namespaceURI;

            return $tree;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function element(\DOMElement $element, ?string $rootNamespace): array
    {
        $tree = ['t' => $element->localName];
        if ($element->namespaceURI !== $rootNamespace) {
            $tree['n'] = $element->namespaceURI;
        }
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            if ('http://www.w3.org/2000/xmlns/' === $attribute->namespaceURI) {
                continue;
            }
            $name = null === $attribute->namespaceURI
                ? $attribute->name
                : '{'.$attribute->namespaceURI.'}'.$attribute->localName;
            $attributes[$name] = $attribute->value;
        }
        if ([] !== $attributes) {
            $tree['a'] = $attributes;
        }
        $text = '';
        $beforeFirstElement = true;
        $children = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $beforeFirstElement = false;
                $children[] = $this->element($child, $rootNamespace);
            } elseif ($beforeFirstElement && ($child instanceof \DOMText || $child instanceof \DOMCdataSection)) {
                $text .= $child->nodeValue;
            }
        }
        if (1 === preg_match('/\S/u', $text)) {
            $tree['x'] = $text;
        }
        if ([] !== $children) {
            $tree['c'] = $children;
        }

        return $tree;
    }
}
