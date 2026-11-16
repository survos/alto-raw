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

/** Rehydrate one page at a time from the typed source tables, including unknown ALTO fields. */
final readonly class AltoTableReader
{
    public function __construct(private \PDO $db)
    {
    }

    public function read(string $issue, int $pageIndex): Document
    {
        $statement = $this->db->prepare('SELECT * FROM alto_source WHERE issue = ? AND page_index = ?');
        $statement->execute([$issue, $pageIndex]);
        $sources = $statement->fetchAll(\PDO::FETCH_ASSOC);
        if (1 !== count($sources)) {
            throw new \UnexpectedValueException('Expected exactly one ALTO page: '.$issue.':'.$pageIndex);
        }
        $source = $sources[0];
        $elements = [];
        $page = null;
        foreach (AltoTableWriter::FIELDS as $table => $fields) {
            $statement = $this->db->prepare('SELECT * FROM '.$table.' WHERE document_id = ? ORDER BY node_id');
            $statement->execute([$source['document_id']]);
            foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $attributes = is_array($row['attributes']) ? $row['attributes'] : json_decode($row['attributes'] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
                if (null !== $row['source_id']) {
                    $attributes['ID'] = $row['source_id'];
                }
                foreach ($fields as $name => [$column, $type]) {
                    if (null !== $row[$column]) {
                        $attributes[$name] = (string) $row[$column];
                    }
                }
                $namespaces = is_array($row['attribute_namespaces']) ? $row['attribute_namespaces'] : json_decode($row['attribute_namespaces'] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
                $id = new NodeIdentity((int) $row['node_id'], null === $row['parent_id'] ? null : (int) $row['parent_id'], (int) $row['ord'], $row['element'], $row['namespace']);
                $node = match ($table) {
                    'alto_page' => new Page($id, $attributes, $namespaces),
                    'alto_block' => new Block($id, $attributes, $namespaces),
                    'alto_line' => new Line($id, $attributes, $namespaces),
                    'alto_word' => new Word($id, $attributes, $namespaces, $row['block_id'], $row['line_id'], $row['word_index']),
                    default => new Element($id, $attributes, $namespaces, $row['text']),
                };
                $elements[$id->id] = $node;
                if ($node instanceof Page) {
                    $page = $node;
                }
            }
        }
        ksort($elements, SORT_NUMERIC);
        if (null === $page) {
            throw new \UnexpectedValueException('Missing ALTO Page.');
        }

        return new Document($page, array_values($elements), $source['source_sha256'], $source['original_xml']);
    }

    /** DOM for existing geometry normalization, without serializing or parsing XML. */
    public function dom(string $issue, int $pageIndex): \DOMDocument
    {
        $document = $this->read($issue, $pageIndex);
        $dom = new \DOMDocument();
        $nodes = [];
        foreach ($document->elements as $element) {
            $id = $element->identity;
            $node = '#text' === $id->name ? $dom->createTextNode($element->text ?? '')
                : $dom->createElementNS($id->namespace, $id->name);
            if ($node instanceof \DOMElement) {
                foreach ($element->attributes as $name => $value) {
                    $namespace = $element->attributeNamespaces[$name] ?? null;
                    if (null === $namespace) {
                        $node->setAttribute($name, $value);
                    } else {
                        $node->setAttributeNS($namespace, $name, $value);
                    }
                }
            }
            $parent = null === $id->parentId ? $dom : ($nodes[$id->parentId] ?? throw new \UnexpectedValueException('ALTO parent missing.'));
            $parent->appendChild($node);
            $nodes[$id->id] = $node;
        }

        return $dom;
    }
}
