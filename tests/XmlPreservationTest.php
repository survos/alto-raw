<?php

declare(strict_types=1);

namespace Survos\AltoRaw\Tests;

use PHPUnit\Framework\TestCase;
use Survos\AltoRaw\OriginalXmlSource;
use Survos\AltoRaw\SourceXml;
use Survos\AltoRaw\XmlTree;
use Survos\BagIt\BagIt;
use Survos\BagIt\IntegrityException;

final class XmlPreservationTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/alto-raw-'.bin2hex(random_bytes(8));
        mkdir($this->temporary);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->temporary, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->temporary);
    }

    public function testLebanonTreesMatchExistingPythonPack(): void
    {
        foreach ($this->documents() as $record) {
            $xml = $this->fixture($record['name'].'.xml.gz');
            self::assertSame($record['sha256'], hash('sha256', $xml));
            $expected = json_decode($this->fixture($record['name'].'.tree.json.gz'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($expected, (new XmlTree())->parse($xml));
        }
    }

    public function testRawBagPreservesExactLebanonMetsAndAltoBytes(): void
    {
        $documents = [];
        foreach ($this->documents() as $record) {
            $file = $this->temporary.'/'.$record['name'].'.xml';
            file_put_contents($file, $this->fixture($record['name'].'.xml.gz'));
            $documents[] = new SourceXml($record['path'], $file, $record['md5']);
        }
        $bag = $this->temporary.'/raw';
        $library = new BagIt();
        $library->createFrom(new OriginalXmlSource($documents), $bag, ['Source-Organization' => 'Penn State University Libraries']);
        $library->verify($bag);
        foreach ($this->documents() as $record) {
            self::assertSame($this->fixture($record['name'].'.xml.gz'), file_get_contents($bag.'/data/originals/'.$record['path']));
        }
    }

    public function testCorruptSourceDoesNotPublishRawBag(): void
    {
        $record = $this->documents()[0];
        $file = $this->temporary.'/bad.xml';
        file_put_contents($file, '<alto>tampered</alto>');
        try {
            (new BagIt())->createFrom(new OriginalXmlSource([new SourceXml($record['path'], $file, $record['md5'])]), $this->temporary.'/raw');
            self::fail('Corrupt source was accepted.');
        } catch (IntegrityException $error) {
            self::assertStringContainsString('checksum mismatch', $error->getMessage());
            self::assertDirectoryDoesNotExist($this->temporary.'/raw');
        }
    }

    public function testTreeIsExplicitlyLossyWhileOriginalRemainsExact(): void
    {
        $xml = '<alto xmlns="urn:alto">before<!-- comment --><String CONTENT="word"/>tail</alto>';
        $file = $this->temporary.'/mixed.xml';
        file_put_contents($file, $xml);
        $document = new SourceXml('data/mixed.xml', $file, md5($xml));
        $stream = $document->openVerified();
        try {
            self::assertSame($xml, stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
        self::assertSame(['t' => 'alto', 'x' => 'before', 'c' => [['t' => 'String', 'a' => ['CONTENT' => 'word']]], 'ns' => 'urn:alto'], (new XmlTree())->parse($xml));
    }

    public function testDtdIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new XmlTree())->parse('<!DOCTYPE alto [<!ENTITY secret SYSTEM "file:///never-read">]><alto>&secret;</alto>');
    }

    public function testMalformedXmlIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new XmlTree())->parse('<alto>');
    }

    public function testUnsafeOriginalPathIsRejected(): void
    {
        $this->expectException(IntegrityException::class);
        new SourceXml('../outside.xml', '/unused', str_repeat('0', 32));
    }

    private function documents(): array
    {
        return json_decode(file_get_contents(__DIR__.'/Fixtures/lebanon/provenance.json'), true, flags: JSON_THROW_ON_ERROR)['documents'];
    }

    private function fixture(string $name): string
    {
        $data = gzdecode(file_get_contents(__DIR__.'/Fixtures/lebanon/'.$name));
        self::assertIsString($data);

        return $data;
    }
}
