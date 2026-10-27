<?php

declare(strict_types=1);

namespace Survos\AltoRaw;

use Survos\BagIt\Filesystem;
use Survos\BagIt\IntegrityException;

/** Verified, title-scoped inventory. A manifest is not evidence that JP2s are local. */
final readonly class PennInventory
{
    public string $bag;
    public string $revision;
    public array $checksums;
    public array $issues;

    /** @var array<string, string> tag files accepted although their checksum differs from the tagmanifest ($strict false) */
    public array $tagMismatches;

    /** @param bool $strict see {@see PennTags::verify()}: false tolerates Penn's rewritten bag-info.txt and records it */
    public function __construct(public string $capture, public string $batch, public string $lccn, bool $strict = true)
    {
        if (!preg_match('/^batch_[A-Za-z0-9_]+_ver\d+$/D', $batch) || !preg_match('/^sn\d+$/D', $lccn)) {
            throw new \InvalidArgumentException('Invalid Penn batch or LCCN.');
        }
        $this->bag = rtrim($capture, '/').'/batches/'.$batch;
        $tags = PennTags::verify(fn (string $name): string => file_get_contents($this->file($name)), $strict);
        $this->checksums = $tags['checksums'];
        $this->revision = $tags['revision'];
        $this->tagMismatches = $tags['mismatches'];
        $issues = [];
        foreach ($this->checksums as $path => $checksum) {
            if (preg_match('~^data/('.preg_quote($lccn, '~').')/[A-Za-z0-9]+/(\d{4})(\d{2})(\d{2})(\d{2})/(\d+)\.jp2$~D', $path, $match)) {
                $id = sprintf('%s-%s-%s-%s-ed-%d', $match[1], $match[2], $match[3], $match[4], (int) $match[5]);
                $issues[$id][] = $path;
            }
        }
        if ([] === $issues) {
            throw new IntegrityException('No manifest pages for '.$lccn);
        }
        ksort($issues, SORT_STRING);
        $this->issues = $issues;
    }

    public function file(string $path): string
    {
        Filesystem::path($path);
        $file = $this->bag;
        foreach (explode('/', $path) as $part) {
            $file .= '/'.$part;
            if (is_link($file)) {
                throw new IntegrityException('Symlink in Penn capture: '.$path);
            }
        }
        if (!is_file($file)) {
            throw new IntegrityException('Missing Penn file: '.$path);
        }

        return $file;
    }

    public function checked(string $path): string
    {
        if (!isset($this->checksums[$path])) {
            throw new IntegrityException('Payload absent from manifest: '.$path);
        }
        $bytes = file_get_contents($this->file($path));
        if (false === $bytes || !hash_equals($this->checksums[$path], md5($bytes))) {
            throw new IntegrityException('Penn payload checksum mismatch: '.$path);
        }

        return $bytes;
    }

    /** @return list<string> */
    public function select(array $issueIds = []): array
    {
        if ([] === $issueIds) {
            return array_keys($this->issues);
        }
        if (count(array_unique($issueIds)) !== count($issueIds)) {
            throw new \InvalidArgumentException('Duplicate issue selection.');
        }
        foreach ($issueIds as $id) {
            if (!isset($this->issues[$id])) {
                throw new \InvalidArgumentException('Issue absent from manifest: '.$id);
            }
        }
        sort($issueIds, SORT_STRING);

        return $issueIds;
    }
}
