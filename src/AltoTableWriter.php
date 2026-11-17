<?php

declare(strict_types=1);

namespace Survos\AltoRaw;

use Survos\AltoRaw\Contract\Block;
use Survos\AltoRaw\Contract\Document;
use Survos\AltoRaw\Contract\Element;
use Survos\AltoRaw\Contract\Line;
use Survos\AltoRaw\Contract\Page;
use Survos\AltoRaw\Contract\Word;

/** Typed ALTO entities, with an ordered relational remainder for less common fields/elements. */
final class AltoTableWriter
{
    public const FORMAT = 'alto-tables-v1';
    private const BATCH_ROWS = 8192;
    private ?\PDO $db;
    private int $documents = 0;
    private array $batches = [];
    private array $counts = [];
    private array $columns = [];
    /** @var array<string, list<string>> SQL type of each column, in order, for the bulk insert */
    private array $types = [];
    private const BOX = ['HPOS' => ['x', 'DOUBLE'], 'VPOS' => ['y', 'DOUBLE'], 'WIDTH' => ['width', 'DOUBLE'], 'HEIGHT' => ['height', 'DOUBLE']];
    private const STYLE = ['STYLEREFS' => ['style_refs', 'VARCHAR'], 'TAGREFS' => ['tag_refs', 'VARCHAR'], 'LANG' => ['language', 'VARCHAR'], 'ROTATION' => ['rotation', 'DOUBLE']];
    public const FIELDS = [
        'alto_page' => self::BOX + ['PHYSICAL_IMG_NR' => ['physical_image_number', 'INTEGER'], 'PRINTED_IMG_NR' => ['printed_image_number', 'VARCHAR'], 'QUALITY' => ['quality', 'VARCHAR'], 'POSITION' => ['position', 'VARCHAR']] + self::STYLE,
        'alto_block' => self::BOX + self::STYLE,
        'alto_line' => self::BOX + ['BASELINE' => ['baseline', 'VARCHAR']] + self::STYLE,
        'alto_word' => self::BOX + ['CONTENT' => ['content', 'VARCHAR'], 'WC' => ['confidence', 'DOUBLE'], 'CC' => ['character_confidence', 'VARCHAR'], 'SUBS_TYPE' => ['substitution_type', 'VARCHAR'], 'SUBS_CONTENT' => ['substitution_content', 'VARCHAR']] + self::STYLE,
        'alto_element' => [],
    ];

    public function __construct(private readonly string $path)
    {
        $this->db = new \PDO('duckdb:'.$path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::DUCKDB_ATTR_CONFIG => ['memory_limit' => '1GB', 'threads' => '2']]);
        $this->db->exec('CREATE TABLE alto_source(document_id UINTEGER, issue VARCHAR, page_index INTEGER, source_sha256 VARCHAR, original_xml VARCHAR)');
        $this->columns['alto_source'] = ['document_id', 'issue', 'page_index', 'source_sha256', 'original_xml'];
        $this->types['alto_source'] = ['UINTEGER', 'VARCHAR', 'INTEGER', 'VARCHAR', 'VARCHAR'];
        foreach (self::FIELDS as $table => $fields) {
            $ddl = ['document_id UINTEGER', 'node_id UINTEGER', 'parent_id UINTEGER', 'ord UINTEGER', 'element VARCHAR', 'namespace VARCHAR', 'source_id VARCHAR', 'attributes JSON', 'attribute_namespaces JSON'];
            foreach ($fields as [$column, $type]) {
                $ddl[] = $column.' '.$type;
            }
            if ('alto_word' === $table) {
                $ddl = [...$ddl, 'block_id UINTEGER', 'line_id UINTEGER', 'word_index UINTEGER'];
            }
            if ('alto_element' === $table) {
                $ddl[] = 'text VARCHAR';
            }
            $this->columns[$table] = array_map(static fn ($c) => explode(' ', $c)[0], $ddl);
            $this->types[$table] = array_map(static fn ($c) => explode(' ', $c, 2)[1], $ddl);
            $this->db->exec('CREATE TABLE '.$table.' ('.implode(', ', $ddl).')');
        }
        $this->db->beginTransaction();
    }

    public function write(Document $source, string $issue, int $pageIndex): void
    {
        $document = ++$this->documents;
        $this->add('alto_source', [$document, $issue, $pageIndex, $source->sourceSha256, $source->originalXml]);
        foreach ($source->elements as $element) {
            $this->element($element, $document);
        }
    }

    private function element(Element $element, int $document): void
    {
        $table = match (true) {
            $element instanceof Page => 'alto_page',
            $element instanceof Block => 'alto_block',
            $element instanceof Line => 'alto_line',
            $element instanceof Word => 'alto_word',
            default => 'alto_element',
        };
        $attrs = $element->attributes;
        unset($attrs['ID']);
        $values = [];
        foreach (self::FIELDS[$table] as $attribute => [$column, $type]) {
            $property = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $column))));
            $values[] = $element->$property;
            unset($attrs[$attribute]);
        }
        $id = $element->identity;
        $row = [$document, $id->id, $id->parentId, $id->order, $id->name, $id->namespace, $element->sourceId,
            [] === $attrs ? null : json_encode($attrs, JSON_THROW_ON_ERROR),
            [] === $element->attributeNamespaces ? null : json_encode($element->attributeNamespaces, JSON_THROW_ON_ERROR), ...$values];
        if ($element instanceof Word) {
            $row = [...$row, $element->blockId, $element->lineId, $element->wordIndex];
        }
        if ('alto_element' === $table) {
            $row[] = $element->text;
        }
        $this->add($table, $row);
    }

    private function add(string $table, array $row): void
    {
        $this->counts[$table] = ($this->counts[$table] ?? 0) + 1;
        $this->batches[$table][] = $row;
        if (count($this->batches[$table]) >= self::BATCH_ROWS) {
            $this->flush($table);
        }
    }

    /**
     * One JSON parameter per batch, unpacked by DuckDB. Binding every value of a multi-row INSERT cost about 90 ms
     * per thousand rows; this loads the same rows, in the same order, in a few milliseconds.
     */
    private function flush(string $table): void
    {
        $rows = $this->batches[$table] ?? [];
        if ([] === $rows) {
            return;
        }
        $select = [];
        foreach ($this->types[$table] as $index => $type) {
            $select[] = 'CAST(value->>'.$index.' AS '.$type.')';
        }
        $this->db->prepare('INSERT INTO '.$table.' SELECT '.implode(', ', $select).' FROM json_each(CAST(? AS JSON)) ORDER BY CAST(key AS BIGINT)')
            ->execute([json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]);
        $this->batches[$table] = [];
    }

    /** Merge only into the unpublished snapshot, then update its verification evidence. */
    public function finishInto(string $snapshot): void
    {
        foreach (array_keys($this->columns) as $table) {
            $this->flush($table);
        }
        $this->db->commit();
        $this->db->exec('CHECKPOINT');
        $this->db = null;
        $db = new \PDO('duckdb:'.$snapshot, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::DUCKDB_ATTR_CONFIG => ['memory_limit' => '1GB', 'threads' => '2']]);
        $db->exec('ATTACH '.$db->quote($this->path).' AS alto_input (READ_ONLY)');
        $db->beginTransaction();
        foreach (array_keys($this->columns) as $table) {
            $db->exec('CREATE TABLE '.$table.' AS SELECT * FROM alto_input.'.$table.' ORDER BY document_id'.('alto_source' === $table ? '' : ', node_id'));
        }
        $metadata = json_decode($db->query('SELECT metadata::VARCHAR FROM acquisition')->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        foreach (array_keys($this->columns) as $table) {
            $count = $this->counts[$table] ?? 0;
            $metadata['tables'][$table] = $count;
            $metadata['rows'] += $count;
        }
        $db->prepare('UPDATE acquisition SET metadata = ?::JSON')->execute([json_encode($metadata, JSON_THROW_ON_ERROR)]);
        $db->commit();
        $db->exec('DETACH alto_input');
        $db->exec('CHECKPOINT');
        // ALTO arrives after RecordWriter packed the small issue table. Repack the
        // complete payload at the measured newspaper block size before BagIt hashes it.
        $packed = $snapshot.'.alto-packed-'.bin2hex(random_bytes(6));
        try {
            $db->exec('ATTACH '.$db->quote($packed).' AS packed (BLOCK_SIZE 65536)');
            $catalog = '"'.str_replace('"', '""', (string) $db->query('SELECT current_database()')->fetchColumn()).'"';
            $db->exec('COPY FROM DATABASE '.$catalog.' TO packed');
            $db->exec('CHECKPOINT packed');
            $db->exec('DETACH packed');
            $db = null;
            if (!rename($packed, $snapshot)) {
                throw new \RuntimeException('Cannot replace completed ALTO snapshot.');
            }
        } finally {
            $db = null;
            if (is_file($packed)) {
                unlink($packed);
            }
        }
    }

    public function __destruct()
    {
        $this->db = null;
        foreach ([$this->path, $this->path.'.wal'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
