<?php

declare(strict_types=1);

namespace Survos\AltoRaw\Tests;

use PHPUnit\Framework\TestCase;
use Survos\AltoRaw\AltoParser;
use Survos\AltoRaw\AltoTableReader;
use Survos\AltoRaw\AltoTableWriter;
use Survos\AltoRaw\XmlTree;

final class AltoTableTest extends TestCase
{
    public function testTypedRoundTripKeepsIssuesSeparateAndPreservesExtensions(): void
    {
        if (!in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO DuckDB required');
        }
        $path = sys_get_temp_dir().'/alto-roundtrip-'.bin2hex(random_bytes(6));
        $xml = '<a:alto xmlns:a="urn:alto" xmlns:e="urn:extra"><a:Description>before<e:note e:flag="yes"/>after</a:Description><a:Layout><a:Page WIDTH="100.5" HEIGHT="200"><a:TextBlock ID="b" HPOS="1" VPOS="2" WIDTH="90" HEIGHT="180"><a:TextLine ID="l"><a:String CONTENT="news" HPOS="3.5" VPOS="4" WIDTH="10" HEIGHT="8" WC="0.95"/></a:TextLine></a:TextBlock></a:Page></a:Layout></a:alto>';
        try {
            $db = new \PDO('duckdb:'.$path);
            $db->exec('CREATE TABLE acquisition(metadata JSON)');
            $db->exec("INSERT INTO acquisition VALUES ('{\"tables\":{},\"rows\":0}')");
            $db = null;
            $writer = new AltoTableWriter($path.'.alto');
            $writer->write((new AltoParser())->parse($xml), 'issue-a', 0);
            $writer->write((new AltoParser())->parse(str_replace('news', 'other', $xml)), 'issue-b', 0);
            $writer->finishInto($path);
            unset($writer);
            $db = new \PDO('duckdb:'.$path);
            $reader = new AltoTableReader($db);
            $doc = $reader->read('issue-a', 0);
            self::assertNull($doc->originalXml);
            self::assertSame(hash('sha256', $xml), $doc->sourceSha256);
            self::assertSame('news', iterator_to_array($doc->words())[0]->content);
            self::assertSame('other', iterator_to_array($reader->read('issue-b', 0)->words())[0]->content);
            self::assertEquals((new XmlTree())->parse($xml), (new XmlTree())->parse($reader->dom('issue-a', 0)->saveXML()));
            self::assertSame(2, (int) $db->query('SELECT count(*) FROM alto_source')->fetchColumn());
            $this->expectException(\UnexpectedValueException::class);
            $reader->read('missing', 0);
        } finally {
            unset($reader, $db, $writer);
            foreach (glob($path.'*') as $file) {
                unlink($file);
            }
        }
    }
}
