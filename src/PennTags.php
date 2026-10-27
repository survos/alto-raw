<?php

declare(strict_types=1);

namespace Survos\AltoRaw;

use Survos\BagIt\IntegrityException;
use Survos\BagIt\Manifest;

/**
 * Penn State's BagIt tag files and the one rule for verifying them, wherever the bag is read from (a local
 * capture, the HTTP directory, a mirror).
 *
 * bagit.txt and manifest-md5.txt must match tagmanifest-md5.txt: the payload manifest is what every METS, ALTO and
 * JP2 checksum rests on. Penn State rewrote bag-info.txt (CRLF, two added Batch-* lines) and the batch XML after
 * bagging on 203 of 266 batches (checked 2026-10-27), so those are the only files a non-strict reader tolerates, and
 * every tolerated mismatch is returned so the caller can record it in provenance.
 */
final class PennTags
{
    public const TAG_FILES = ['bagit.txt', 'bag-info.txt', 'manifest-md5.txt', 'tagmanifest-md5.txt'];

    /**
     * @param callable(string): string $read returns a tag file's bytes by name
     *
     * @return array{checksums: array<string, string>, revision: string, mismatches: array<string, string>}
     *                                                                                                      checksums: payload path => md5 from manifest-md5.txt; revision: sha256 of manifest-md5.txt
     *
     * @throws IntegrityException on a mismatch that is never tolerated, or any mismatch when $strict
     */
    public static function verify(callable $read, bool $strict = true): array
    {
        $tags = self::manifest($read('tagmanifest-md5.txt'));
        $mismatches = [];
        $checksums = [];
        $revision = '';
        foreach (['bagit.txt', 'bag-info.txt', 'manifest-md5.txt'] as $name) {
            if (!isset($tags[$name])) {
                throw new IntegrityException('Missing BagIt tag checksum: '.$name);
            }
            $body = $read($name);
            if (!hash_equals($tags[$name], md5($body))) {
                if ($strict || !self::tolerated($name)) {
                    throw new IntegrityException('BagIt checksum mismatch: '.$name);
                }
                $mismatches[$name] = 'live md5 '.md5($body).' differs from tagmanifest '.$tags[$name];
            }
            if ('manifest-md5.txt' === $name) {
                $checksums = self::manifest($body);
                $revision = hash('sha256', $body);
            }
        }

        return ['checksums' => $checksums, 'revision' => $revision, 'mismatches' => $mismatches];
    }

    /** The batch-level files Penn State is known to rewrite after bagging; never a payload that carries page content. */
    public static function tolerated(string $path): bool
    {
        return 'bag-info.txt' === $path || 1 === preg_match('~^data/BATCH[^/]*\.xml$~iD', $path);
    }

    /**
     * Parse manifest text (path => lowercase md5), rejecting unsafe or duplicate paths.
     *
     * @return array<string, string>
     */
    public static function manifest(string $text): array
    {
        $stream = tmpfile();
        if (false === $stream) {
            throw new \RuntimeException('Cannot stage BagIt manifest.');
        }
        try {
            if (strlen($text) !== fwrite($stream, $text) || !fflush($stream)) {
                throw new \RuntimeException('Incomplete BagIt manifest write.');
            }

            return Manifest::read(stream_get_meta_data($stream)['uri'], 'md5');
        } finally {
            fclose($stream);
        }
    }
}
