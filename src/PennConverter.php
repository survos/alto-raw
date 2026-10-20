<?php

declare(strict_types=1);

namespace Survos\AltoRaw;

use Survos\BagIt\BagIt;
use Survos\BagIt\IntegrityException;
use Survos\BagIt\Verification;

/** Offline conversion; one issue in memory, immutable directory publication. No image downloads. */
final class PennConverter
{
    private const JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function convert(string $capture, string $batch, string $lccn, string $destination, array $issueIds = []): array
    {
        $inventory = new PennInventory($capture, $batch, $lccn);
        $selected = $inventory->select($issueIds);
        $parent = realpath(dirname($destination));
        if (false === $parent || is_link($destination)) {
            throw new \InvalidArgumentException('Output parent must exist and output must not be a symlink.');
        }
        $destination = $parent.'/'.basename($destination);
        if ($destination === realpath($capture) || str_starts_with(realpath($capture).'/', $destination.'/')) {
            throw new \InvalidArgumentException('Output cannot contain capture inputs.');
        }
        $lock = fopen($parent, 'r');
        if (false === $lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new \RuntimeException('Another conversion is publishing in this directory.');
        }
        $stage = null;
        try {
            if (file_exists($destination)) {
                $done = $this->verify($destination, $batch);
                if ($done['manifestSha256'] !== $inventory->revision || $done['issueIds'] !== $selected || $done['lccn'] !== $lccn) {
                    throw new IntegrityException('Output already exists with a different scope or manifest revision.');
                }

                return $done;
            }
            $stage = $parent.'/.alto-raw-'.bin2hex(random_bytes(12));
            mkdir($stage, 0700);
            $title = $this->jsonFile($capture.'/title.json');
            $this->copy($capture.'/title.json', $stage.'/source/title.json');
            foreach (['bagit.txt', 'bag-info.txt', 'manifest-md5.txt', 'tagmanifest-md5.txt'] as $tag) {
                $this->copy($inventory->file($tag), $stage.'/source/'.$batch.'/'.$tag);
            }
            $relative = 'packs/'.$lccn.'/'.$batch.'.pack';
            $this->directory(dirname($stage.'/'.$relative));
            $pack = fopen($stage.'/'.$relative, 'xb');
            $catalog = [];
            $this->directory($stage.'/raw');
            $raw = gzopen($stage.'/raw/doc.jsonl.gz', 'wb6');
            $projection = new PennRawProjection();
            try {
                foreach ($selected as $issue) {
                    [$header, $pages] = $this->issue($inventory, $issue, $title, $stage);
                    $document = $projection->document($header, $pages);
                    $rightsFile = $capture.'/'.$issue.'/rights.json';
                    $previousFile = $capture.'/'.$issue.'/complete.json';
                    $rights = is_file($rightsFile) ? $this->jsonFile($rightsFile) : (is_file($previousFile) ? $this->jsonFile($previousFile) : []);
                    if (!isset($document[\Survos\DataContracts\Vocabulary\ItemField::RIGHTS]) && isset($rights[\Survos\DataContracts\Vocabulary\ItemField::RIGHTS])) {
                        $document[\Survos\DataContracts\Vocabulary\ItemField::RIGHTS] = $rights[\Survos\DataContracts\Vocabulary\ItemField::RIGHTS];
                        $this->write($stage.'/source/'.$issue.'/rights.json', json_encode([\Survos\DataContracts\Vocabulary\ItemField::RIGHTS => $document[\Survos\DataContracts\Vocabulary\ItemField::RIGHTS]], self::JSON));
                    }
                    $rawLine = json_encode($document, self::JSON)."\n";
                    if (gzwrite($raw, $rawLine) !== strlen($rawLine)) {
                        throw new \RuntimeException('Short raw JSONL write.');
                    }
                    $json = json_encode($header, self::JSON)."\n";
                    foreach ($pages as $page) {
                        $json .= json_encode($page, self::JSON)."\n";
                    }
                    $member = gzencode($json, 6, ZLIB_ENCODING_GZIP);
                    if (false === $member) {
                        throw new \RuntimeException('Cannot compress issue.');
                    }
                    $catalog[] = ['issue' => $issue, 'pack' => $relative, 'offset' => ftell($pack), 'length' => strlen($member), 'sha256' => hash('sha256', $member), 'pages' => count($pages)];
                    if (fwrite($pack, $member) !== strlen($member)) {
                        throw new \RuntimeException('Short pack write.');
                    }
                }
            } finally {
                fclose($pack);
                gzclose($raw);
            }
            $catalogBytes = implode("\n", array_map(static fn ($row) => json_encode($row, self::JSON), $catalog))."\n";
            $this->write($stage.'/catalog/'.$batch.'.jsonl.gz', gzencode($catalogBytes, 6));
            $result = ['v' => 2, 'batch' => $batch, 'manifestSha256' => $inventory->revision, 'lccn' => $lccn, 'issueIds' => $selected, 'issues' => count($selected), 'pages' => array_sum(array_column($catalog, 'pages')), 'checksumAlgorithm' => 'md5', 'scope' => 'selected issue METS and ALTO; JP2 references only; not the entire bag'];
            $this->write($stage.'/meta/dataset.json', json_encode($projection->metadata($result, $title), self::JSON | JSON_PRETTY_PRINT)."\n");
            $this->write($stage.'/batches/'.$batch.'.json', json_encode($result, self::JSON | JSON_PRETTY_PRINT)."\n");
            $hashes = [];
            foreach (\Survos\BagIt\Filesystem::files($stage) as $path) {
                $hashes[$path] = hash_file('sha256', $stage.'/'.$path);
            }
            $this->write($stage.'/checksums.json', json_encode($hashes, self::JSON | JSON_PRETTY_PRINT)."\n");
            $this->verify($stage, $batch);
            if (file_exists($destination) || !rename($stage, $destination)) {
                throw new \RuntimeException('Cannot publish converted capture.');
            }
            $stage = null;

            return $result;
        } finally {
            if (null !== $stage) {
                $this->remove($stage);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function issue(PennInventory $inventory, string $issue, array $title, string $stage): array
    {
        $paths = $inventory->issues[$issue];
        $directory = dirname($paths[0]);
        $codec = new XmlTree();
        $geometry = new PageGeometry();
        $mets = [];
        foreach ($inventory->checksums as $path => $md5) {
            if (dirname($path) === $directory && preg_match('~/\d{10}(?:_\d+)?\.xml$~D', $path)) {
                $bytes = $inventory->checked($path);
                $mets[] = ['path' => $path, 'md5' => $md5, 'sha256' => hash('sha256', $bytes), 'tree' => $codec->parse($bytes)];
                $this->write($stage.'/originals/'.$path, $bytes);
            }
        }
        if ([] === $mets) {
            throw new IntegrityException('Missing issue METS: '.$issue);
        }
        $pages = [];
        $remaining = array_fill_keys($paths, true);
        for ($seq = 1; $seq <= count($paths); ++$seq) {
            $canvasFile = $inventory->file($directory.'/seq-'.$seq.'.json');
            $canvas = $this->jsonFile($canvasFile);
            $service = $canvas['images'][0]['resource']['service']['@id'];
            $candidates = array_filter(array_keys($remaining), static fn ($path) => str_ends_with(rawurldecode($service), '/'.$inventory->batch.'/'.$path));
            if (1 !== count($candidates)) {
                throw new IntegrityException('Unknown or repeated canvas: '.$issue.' seq '.$seq);
            }
            $image = array_values($candidates)[0];
            unset($remaining[$image]);
            $path = substr($image, 0, -4).'.xml';
            $bytes = $inventory->checked($path);
            $tree = $codec->parse($bytes);
            if ('alto' !== $tree['t']) {
                throw new IntegrityException('Expected ALTO: '.$path);
            }
            $pages[] = ['seq' => $seq, 'alto' => $tree, 'canvas' => $canvas, 'path' => $path, 'imagePath' => $image, 'md5' => $inventory->checksums[$path], 'imageMd5' => $inventory->checksums[$image], 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)] + $geometry->summarize($bytes, $tree);
            $this->write($stage.'/originals/'.$path, $bytes);
            $this->copy($canvasFile, $stage.'/source/'.$inventory->batch.'/'.$directory.'/seq-'.$seq.'.json');
        }
        $sourceFile = $inventory->capture.'/'.$issue.'/issue.json';
        $source = $this->jsonFile($sourceFile);
        $this->copy($sourceFile, $stage.'/source/'.$issue.'/issue.json');
        $header = ['v' => 2, 'kind' => 'penn-issue', 'issue' => $issue, 'lccn' => $inventory->lccn, 'batch' => $inventory->batch, 'manifestSha256' => $inventory->revision, 'title' => $title, 'source' => $source, 'mets' => $mets, 'pages' => array_map(static fn ($page) => array_diff_key($page, ['alto' => true, 'canvas' => true]), $pages)];

        return [$header, $pages];
    }

    public function verify(string $root, string $batch): array
    {
        if (!preg_match('/^batch_[A-Za-z0-9_]+_ver\d+$/D', $batch)) {
            throw new \InvalidArgumentException('Invalid batch.');
        }
        $hashes = $this->jsonFile($root.'/checksums.json');
        $actual = \Survos\BagIt\Filesystem::files($root);
        $expected = array_keys($hashes);
        $expected[] = 'checksums.json';
        sort($expected);
        if ($actual !== $expected) {
            throw new IntegrityException('Converted file inventory differs.');
        }
        foreach ($hashes as $path => $hash) {
            if (!hash_equals($hash, hash_file('sha256', $root.'/'.$path))) {
                throw new IntegrityException('Converted checksum mismatch: '.$path);
            }
        }
        $done = $this->jsonFile($root.'/batches/'.$batch.'.json');
        $rows = $this->catalog($root, $batch);
        if (array_column($rows, 'issue') !== $done['issueIds'] || array_sum(array_column($rows, 'pages')) !== $done['pages'] || count($rows) !== $done['issues']) {
            throw new IntegrityException('Issue/page inventory differs.');
        }
        foreach ($rows as $row) {
            $lines = $this->readIssue($root, $row);
            $header = array_shift($lines);
            if ($header['issue'] !== $row['issue'] || $header['manifestSha256'] !== $done['manifestSha256'] || count($lines) !== $row['pages']) {
                throw new IntegrityException('Issue header differs.');
            }
            foreach (array_merge($header['mets'], $lines) as $document) {
                $path = $document['path'];
                \Survos\BagIt\Filesystem::path($path);
                $bytes = file_get_contents($root.'/originals/'.$path);
                if ($document['sha256'] !== hash('sha256', $bytes) || $document['md5'] !== md5($bytes) || ($document['tree'] ?? $document['alto']) !== (new XmlTree())->parse($bytes)) {
                    throw new IntegrityException('Original XML parity failed: '.$path);
                }
            }
        }

        return $done;
    }

    public function createBag(string $root, string $batch, string $destination): Verification
    {
        $done = $this->verify($root, $batch);

        return (new BagIt())->create($root, $destination, ['External-Identifier' => 'oni/pennsylvania-'.$done['lccn'], 'Source-Manifest-SHA256' => $done['manifestSha256'], 'Payload-Scope' => $done['scope']]);
    }

    public function catalog(string $root, string $batch): array
    {
        $bytes = gzdecode(file_get_contents($root.'/catalog/'.$batch.'.jsonl.gz'));
        if (false === $bytes) {
            throw new IntegrityException('Invalid catalog gzip.');
        }

        return array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", rtrim($bytes, "\n")));
    }

    public function readIssue(string $root, array $row): array
    {
        \Survos\BagIt\Filesystem::path($row['pack']);
        if (!is_int($row['offset']) || !is_int($row['length']) || $row['offset'] < 0 || $row['length'] <= 0) {
            throw new IntegrityException('Invalid member range.');
        }
        $handle = fopen($root.'/'.$row['pack'], 'rb');
        try {
            if (0 !== fseek($handle, $row['offset'])) {
                throw new IntegrityException('Invalid member offset.');
            }
            $member = stream_get_contents($handle, $row['length']);
        } finally {
            fclose($handle);
        }
        if (strlen($member) !== $row['length'] || hash('sha256', $member) !== $row['sha256'] || false === ($bytes = gzdecode($member))) {
            throw new IntegrityException('Issue member checksum mismatch.');
        }

        return array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", rtrim($bytes, "\n")));
    }

    private function jsonFile(string $path): array
    {
        $bytes = file_get_contents($path);
        if (false === $bytes) {
            throw new \RuntimeException('Cannot read '.$path);
        }

        return json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    }

    private function directory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true)) {
            throw new \RuntimeException('Cannot create '.$path);
        }
    }

    private function write(string $path, string $bytes): void
    {
        $this->directory(dirname($path));
        if (file_put_contents($path, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('Short write: '.$path);
        }
    }

    private function copy(string $source, string $destination): void
    {
        $bytes = file_get_contents($source);
        if (false === $bytes) {
            throw new \RuntimeException('Cannot read '.$source);
        }
        $this->write($destination, $bytes);
    }

    private function remove(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
