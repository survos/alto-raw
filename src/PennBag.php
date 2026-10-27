<?php

declare(strict_types=1);

namespace Survos\AltoRaw;

use Survos\BagIt\IntegrityException;
use Survos\DataContracts\Vocabulary\ItemField;

/**
 * Penn State's HTTP BagIt directory, read selectively: tag files and the payload manifest first, then only the
 * METS, canvases and ALTO of the issues asked for. JP2s are referenced with their manifest checksum, never fetched.
 *
 * The caller's fetch closure owns transport, caching, pacing and atomic writes: ($url, $cachePath) => bytes.
 * Every payload is verified against manifest-md5.txt before it is used; see {@see PennTags} for the tag rules.
 */
final class PennBag
{
    private array $manifest;
    public readonly string $base;
    public readonly string $revision;
    /** @var array<string, string> files accepted although their checksum differs from the manifests ($strict false) */
    private array $tagMismatches;

    /** @param \Closure(string, string): string $fetch */
    public function __construct(public readonly string $batch, private readonly string $cache, private readonly \Closure $fetch, bool $strict = true)
    {
        if (!preg_match('/^batch_[A-Za-z0-9_]+_ver\d+$/D', $batch)) {
            throw new \InvalidArgumentException('Invalid Penn State batch name.');
        }
        $this->base = PennRawProjection::BASE.'/data/batches/'.$batch.'/';
        $tags = PennTags::verify(fn (string $name): string => ($this->fetch)($this->base.$name, $this->cache.'/'.$name), $strict);
        $this->manifest = $tags['checksums'];
        $this->revision = $tags['revision'];
        $this->tagMismatches = $tags['mismatches'];
        foreach (array_keys($this->manifest) as $path) {
            if (PennTags::tolerated($path) && str_starts_with($path, 'data/')) {
                try {
                    $this->payload($path);
                } catch (IntegrityException $e) {
                    if ($strict || !str_starts_with($e->getMessage(), 'BagIt checksum mismatch')) {
                        throw $e;
                    }
                    $this->tagMismatches[$path] = 'live md5 differs from manifest-md5.txt '.$this->manifest[$path];
                }
            }
        }
    }

    /** @return array<string, list<string>> issue JSON URL => manifest-listed JP2 paths, in reading order */
    public function issues(string $lccn): array
    {
        $issues = [];
        foreach ($this->manifest as $path => $checksum) {
            if (!preg_match('~^data/([a-z0-9]+)/[A-Za-z0-9]+/(\d{4})(\d{2})(\d{2})(\d{2})/(\d+)\.jp2$~D', $path, $m) || $m[1] !== $lccn) {
                continue;
            }
            $url = PennRawProjection::BASE.'/lccn/'.$lccn.'/'.$m[2].'-'.$m[3].'-'.$m[4].'/ed-'.(int) $m[5].'.json';
            PennRawProjection::identity($url);
            if (!isset($this->manifest[substr($path, 0, -4).'.xml'])) {
                throw new IntegrityException('Missing ALTO in manifest: '.$path);
            }
            $issues[$url][] = $path;
        }
        ksort($issues);
        foreach ($issues as &$pages) {
            sort($pages, SORT_NATURAL);
        }
        unset($pages);
        if ([] === $issues) {
            throw new IntegrityException('No manifest pages for '.$lccn);
        }

        return $issues;
    }

    private function payload(string $path): string
    {
        $checksum = $this->manifest[$path] ?? throw new IntegrityException('Unlisted payload: '.$path);
        $body = ($this->fetch)($this->base.$path, $this->cache.'/'.$path);
        if (!hash_equals($checksum, md5($body))) {
            throw new IntegrityException('BagIt checksum mismatch: '.$path);
        }

        return $body;
    }

    /** @return array<string, mixed> batch, url, manifestSha256, checksumAlgorithm, scope, and tagMismatches when any were tolerated */
    public function provenance(): array
    {
        $provenance = ['batch' => $this->batch, 'url' => $this->base, 'manifestSha256' => $this->revision, 'checksumAlgorithm' => 'md5', 'scope' => PennFields::SCOPE];
        if ([] !== $this->tagMismatches) {
            $provenance['tagMismatches'] = $this->tagMismatches;
        }

        return $provenance;
    }

    /**
     * One issue's pages: each canvas paired with its manifest-listed JP2 and checksum-verified ALTO, in reading order.
     *
     * @param list<string>                                                                                               $paths the issue's JP2 paths from {@see issues()}
     * @param callable(string, int, int, int): array{text: ?string, layout: array{blocks: mixed}, ocrProcessing?: mixed} $parse ALTO xml, width, height, page index => text and layout
     *
     * @return list<array<string, mixed>>
     */
    public function pages(string $issueUrl, array $paths, callable $parse): array
    {
        $directory = dirname($paths[0]);
        // Preserve issue-level METS; image masters are mirrored separately.
        $mets = 0;
        foreach ($this->manifest as $path => $checksum) {
            if (dirname($path) === $directory && preg_match('/\/\d{10}(?:_\d+)?\.xml$/D', $path)) {
                $this->payload($path);
                ++$mets;
            }
        }
        if (0 === $mets) {
            throw new IntegrityException('Missing issue METS in manifest: '.$directory);
        }
        $pages = [];
        $remaining = array_fill_keys($paths, true);
        foreach (array_keys($paths) as $index) {
            $pageUrl = substr($issueUrl, 0, -5).'/seq-'.($index + 1).'.json';
            $canvas = json_decode(($this->fetch)($pageUrl, $this->cache.'/'.$directory.'/seq-'.($index + 1).'.json'), true, flags: JSON_THROW_ON_ERROR);
            $service = PennRawProjection::url($canvas['images'][0]['resource']['service']['@id']);
            // Reading order may interleave supplements whose filenames sort later: require a bijection with the manifest paths.
            $path = null;
            foreach ($remaining as $candidate => $_) {
                if (str_ends_with(rawurldecode($service), '/'.$this->batch.'/'.$candidate)) {
                    $path = $candidate;
                    break;
                }
            }
            if (null === $path) {
                throw new IntegrityException('Canvas is unknown or repeated in BagIt issue: '.$pageUrl);
            }
            unset($remaining[$path]);
            $ocrPath = substr($path, 0, -4).'.xml';
            $parsed = $parse($this->payload($ocrPath), $canvas['width'], $canvas['height'], $index);
            $pages[] = [ItemField::THUMBNAIL_URL => PennRawProjection::url($canvas['thumbnail']),
                'pageIndex' => $index, 'iiifInfo' => $service.'/info.json', 'url' => $service.'/full/full/0/default.jpg',
                'width' => $canvas['width'], 'height' => $canvas['height'], 'files' => [['url' => $this->base.$path, 'mimetype' => 'image/jp2', 'md5' => $this->manifest[$path]]],
                'text' => $parsed['text'], 'layout' => $parsed['layout']['blocks'], 'ocrUrl' => $this->base.$ocrPath, 'ocrError' => null,
                PennFields::PROVENANCE => [ItemField::SOURCE_API_URL => $pageUrl, 'imagePath' => $path, 'imageMd5' => $this->manifest[$path],
                    'ocrPath' => $ocrPath, 'ocrMd5' => $this->manifest[$ocrPath], 'ocrProcessing' => $parsed['ocrProcessing'] ?? null]];
        }

        return $pages;
    }

    /** The viewer is the rights evidence; never infer rights from Open ONI's software license. */
    public function rights(string $issueUrl): ?array
    {
        $viewer = substr($issueUrl, 0, -5).'/seq-1/';
        $identity = PennRawProjection::identity($issueUrl);
        $html = ($this->fetch)($viewer, $this->cache.'/rights/'.$identity['id'].'.html');
        if (!str_contains($html, 'creativecommons.org/publicdomain/mark/1.0')) {
            // An absent public-domain statement is not a download failure. Keep the source response, invent no license.
            return null;
        }

        return ['label' => 'Public Domain Mark 1.0', 'url' => 'https://creativecommons.org/publicdomain/mark/1.0/', 'source' => $viewer];
    }
}
