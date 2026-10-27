<?php

declare(strict_types=1);

namespace Survos\AltoRaw\Tests;

use PHPUnit\Framework\TestCase;
use Survos\AltoRaw\PennBag;
use Survos\AltoRaw\PennFields;
use Survos\AltoRaw\PennInventory;
use Survos\AltoRaw\PennTags;
use Survos\BagIt\IntegrityException;

final class PennBagTest extends TestCase
{
    private const LCCN = 'sn83032227';
    private const ISSUE = 'https://panewsarchive.psu.edu/lccn/sn83032227/1857-06-24/ed-1.json';

    /** @return array<string, string> cache-relative path => bytes, a minimal consistent bag with one issue of two pages (a supplement first) */
    private static function files(string $batch, bool $rewriteBagInfo = false): array
    {
        $directory = 'data/'.self::LCCN.'/000001966/1857062401';
        $image = $directory.'/0001.jp2';
        $supplement = $directory.'/0432.jp2';
        $alto = '<alto><Layout><Page WIDTH="1000" HEIGHT="2000"/></Layout></alto>';
        $payloads = [$image => 'image deliberately unavailable', $directory.'/0001.xml' => $alto, $directory.'/1857062401.xml' => '<mets/>', $supplement => 'supplement unavailable', $directory.'/0432.xml' => $alto, 'data/BATCH_1.xml' => '<batch/>'];
        $manifest = '';
        foreach ($payloads as $path => $body) {
            $manifest .= md5($body).'  '.$path."\n";
        }
        $files = ['bagit.txt' => "BagIt-Version: 0.97\n", 'bag-info.txt' => "Payload-Oxum: 1.1\n", 'manifest-md5.txt' => $manifest];
        $tags = '';
        foreach ($files as $path => $body) {
            $tags .= md5($body).'  '.$path."\n";
        }
        $files['tagmanifest-md5.txt'] = $tags;
        if ($rewriteBagInfo) {
            // Penn rewrote the file after bagging (CRLF, added Batch-* lines) and regenerated the batch XML; the tagmanifest still names the originals.
            $files['bag-info.txt'] = "Batch-name: \r\nBatch Content: $batch\r\nPayload-Oxum: 1.1\r\n";
            $payloads['data/BATCH_1.xml'] = '<batch regenerated="yes"/>';
        }
        $files['rights/sn83032227-1857-06-24-ed-1.html'] = '<html><body>Newspaper viewer with no rights statement</body></html>';
        $service = 'https://panewsarchive.psu.edu/iiif/'.rawurlencode($batch.'/'.$supplement);
        $files[$directory.'/seq-1.json'] = json_encode(['width' => 1000, 'height' => 2000, 'thumbnail' => $service.'/full/120,/0/default.jpg', 'images' => [['resource' => ['service' => ['@id' => $service]]]]], JSON_THROW_ON_ERROR);
        $files[$directory.'/seq-2.json'] = str_replace(rawurlencode($batch.'/'.$supplement), rawurlencode($batch.'/'.$image), $files[$directory.'/seq-1.json']);
        unset($payloads[$image], $payloads[$supplement]);

        return $files + $payloads;
    }

    private static function fetch(array $files, array &$requested = []): \Closure
    {
        return static function (string $url, string $path) use ($files, &$requested): string {
            $key = substr($path, strlen('/cache/'));
            $requested[] = $key;

            return $files[$key] ?? throw new \RuntimeException('Unexpected download: '.$key);
        };
    }

    private static function parse(string $xml, int $width, int $height, int $index): array
    {
        return ['text' => 'page '.$index, 'layout' => ['blocks' => [['w' => $width, 'h' => $height]]], 'ocrProcessing' => null];
    }

    public function testOcrCaptureFollowsCanvasOrderAndNeverFetchesImageMasters(): void
    {
        $batch = 'batch_pst_aiusLocutius_ver03';
        $files = self::files($batch);
        $requested = [];
        $bag = new PennBag($batch, '/cache', self::fetch($files, $requested));
        $issues = $bag->issues(self::LCCN);
        self::assertSame([self::ISSUE], array_keys($issues));
        $pages = $bag->pages(self::ISSUE, $issues[self::ISSUE], self::parse(...));
        self::assertCount(2, $pages);
        self::assertNotContains('data/'.self::LCCN.'/000001966/1857062401/0001.jp2', $requested);
        self::assertSame('data/'.self::LCCN.'/000001966/1857062401/0432.xml', $pages[0][PennFields::PROVENANCE]['ocrPath'], 'the supplement canvas comes first');
        self::assertSame('page 1', $pages[1]['text']);
        self::assertSame(md5('image deliberately unavailable'), $pages[1]['files'][0]['md5']);
        self::assertNull($bag->rights(self::ISSUE));
        self::assertArrayNotHasKey('tagMismatches', $bag->provenance());
        self::assertSame(PennFields::SCOPE, $bag->provenance()['scope']);

        $files['data/'.self::LCCN.'/000001966/1857062401/seq-2.json'] = $files['data/'.self::LCCN.'/000001966/1857062401/seq-1.json'];
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('Canvas is unknown or repeated');
        (new PennBag($batch, '/cache', self::fetch($files)))->pages(self::ISSUE, $issues[self::ISSUE], self::parse(...));
    }

    public function testRewrittenBatchFilesAreRefusedWhenStrictAndRecordedOtherwise(): void
    {
        $batch = 'batch_pst_unabcorres_ver01';
        $files = self::files($batch, rewriteBagInfo: true);
        $lenient = new PennBag($batch, '/cache', self::fetch($files), strict: false);
        $mismatches = $lenient->provenance()['tagMismatches'];
        self::assertSame(['bag-info.txt', 'data/BATCH_1.xml'], array_keys($mismatches));
        self::assertCount(2, $lenient->pages(self::ISSUE, $lenient->issues(self::LCCN)[self::ISSUE], self::parse(...)), 'page payloads are still verified and still usable');

        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('BagIt checksum mismatch: bag-info.txt');
        new PennBag($batch, '/cache', self::fetch($files));
    }

    public function testPayloadMismatchIsNeverTolerated(): void
    {
        $batch = 'batch_pst_unabcorres_ver01';
        $files = self::files($batch, rewriteBagInfo: true);
        $files['data/'.self::LCCN.'/000001966/1857062401/0432.xml'] = '<alto>tampered</alto>';
        $bag = new PennBag($batch, '/cache', self::fetch($files), strict: false);
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('BagIt checksum mismatch: data/'.self::LCCN.'/000001966/1857062401/0432.xml');
        $bag->pages(self::ISSUE, $bag->issues(self::LCCN)[self::ISSUE], self::parse(...));
    }

    public function testLocalInventorySharesTheTagRules(): void
    {
        $root = sys_get_temp_dir().'/penn-tags-'.bin2hex(random_bytes(6));
        $batch = 'batch_pst_unabcorres_ver01';
        foreach (self::files($batch, rewriteBagInfo: true) as $path => $body) {
            if (str_starts_with($path, 'rights/') || str_ends_with($path, '.json')) {
                continue;
            }
            @mkdir(dirname($root.'/batches/'.$batch.'/'.$path), 0700, true);
            file_put_contents($root.'/batches/'.$batch.'/'.$path, $body);
        }
        try {
            $inventory = new PennInventory($root, $batch, self::LCCN, strict: false);
            self::assertSame(['bag-info.txt'], array_keys($inventory->tagMismatches));
            self::assertSame(['sn83032227-1857-06-24-ed-1'], array_keys($inventory->issues));
            self::assertTrue(PennTags::tolerated('data/BATCH_1.xml'));
            self::assertFalse(PennTags::tolerated('data/sn83032227/000001966/1857062401/0001.xml'));
            $this->expectException(IntegrityException::class);
            new PennInventory($root, $batch, self::LCCN);
        } finally {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($root);
        }
    }
}
