<?php

declare(strict_types=1);

namespace Survos\AltoRaw\Tests;

use PHPUnit\Framework\TestCase;
use Survos\AltoRaw\BagArchive;
use Survos\AltoRaw\PennConverter;
use Survos\BagIt\BagIt;
use Survos\BagIt\IntegrityException;

final class PennConverterTest extends TestCase
{
    private const BATCH = 'batch_pst_aura_ver01';
    private const ISSUE = 'sn83032227-1857-06-24-ed-1';
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/penn-convert-'.bin2hex(random_bytes(8));
        mkdir($this->root);
        $fixture = __DIR__.'/Fixtures/lebanon/capture';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fixture, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = $this->root.'/capture/'.substr($file->getPathname(), strlen($fixture) + 1, -3);
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            file_put_contents($path, gzdecode(file_get_contents($file->getPathname())));
        }
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testEntireLebanonIssueParityAndDeterminismWithRawBag(): void
    {
        $converter = new PennConverter();
        $done = $this->convert($converter, 'one');
        self::assertSame(4, $done['pages']);
        $expected = array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", trim(gzdecode(file_get_contents(__DIR__.'/Fixtures/lebanon/issue.jsonl.gz')))));
        $row = $converter->catalog($this->root.'/one', self::BATCH)[0];
        self::assertSame($expected, $converter->readIssue($this->root.'/one', $row));
        self::assertSame($done, $this->convert($converter, 'one'));
        $this->convert($converter, 'two');
        foreach (\Survos\BagIt\Filesystem::files($this->root.'/one') as $path) {
            self::assertSame(hash_file('sha256', $this->root.'/one/'.$path), hash_file('sha256', $this->root.'/two/'.$path), $path);
        }
        $converter->createBag($this->root.'/one', self::BATCH, $this->root.'/bag');
        (new BagIt())->verify($this->root.'/bag');
        (new BagArchive())->pack($this->root.'/bag', $this->root.'/raw.tar');
        self::assertFileExists($this->root.'/raw.tar');
        $raw = json_decode(trim(gzdecode(file_get_contents($this->root.'/bag/data/raw/doc.jsonl.gz'))), true, flags: JSON_THROW_ON_ERROR);
        self::assertEquals(json_decode(trim(gzdecode(file_get_contents(__DIR__.'/Fixtures/lebanon/raw-doc.jsonl.gz'))), true, flags: JSON_THROW_ON_ERROR), $raw);
        self::assertSame(self::ISSUE, $raw['id']);
        self::assertCount(4, $raw['pageAssets']);
        self::assertSame($expected[1]['alto'], $raw['pageAssets'][0]['altoTree']);
        foreach ($expected[0]['mets'] as $mets) {
            self::assertSame($mets['sha256'], hash_file('sha256', $this->root.'/bag/data/originals/'.$mets['path']));
        }
    }

    public function testCorruptInputDoesNotPublish(): void
    {
        file_put_contents($this->root.'/capture/batches/'.self::BATCH.'/data/sn83032227/000001966/1857062401/0001.xml', '<alto/>');
        try {
            $this->convert(new PennConverter(), 'out');
            self::fail('Accepted corrupt payload.');
        } catch (IntegrityException) {
            self::assertDirectoryDoesNotExist($this->root.'/out');
            self::assertSame([], glob($this->root.'/.alto-raw-*'));
        }
    }

    public function testDuplicateCanvasIsRejected(): void
    {
        $directory = $this->root.'/capture/batches/'.self::BATCH.'/data/sn83032227/000001966/1857062401';
        copy($directory.'/seq-1.json', $directory.'/seq-2.json');
        $this->expectException(IntegrityException::class);
        $this->convert(new PennConverter(), 'out');
    }

    public function testCorruptPublishedOutputIsNotSkippedOnRetry(): void
    {
        $converter = new PennConverter();
        $this->convert($converter, 'out');
        file_put_contents($this->root.'/out/raw/doc.jsonl.gz', 'bad');
        $this->expectException(IntegrityException::class);
        $this->convert($converter, 'out');
    }

    private function convert(PennConverter $converter, string $out): array
    {
        return $converter->convert($this->root.'/capture', self::BATCH, 'sn83032227', $this->root.'/'.$out, [self::ISSUE]);
    }
}
