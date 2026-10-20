<?php

declare(strict_types=1);

namespace Survos\AltoRaw;

use Survos\BagIt\Filesystem;
use Survos\BagIt\IntegrityException;

/** One selected METS/ALTO payload, with its original Penn manifest identity. */
final readonly class SourceXml
{
    public function __construct(public string $path, public string $file, public string $md5)
    {
        Filesystem::path($path);
        if (!str_ends_with(strtolower($path), '.xml') || !preg_match('/^[a-f0-9]{32}$/D', $md5)) {
            throw new \InvalidArgumentException('Expected an XML payload path and lowercase manifest MD5.');
        }
    }

    /** Verified snapshot; large XML spills to disk. The caller closes the stream. @return resource */
    public function openVerified(): mixed
    {
        if (is_link($this->file) || !is_file($this->file)) {
            throw new IntegrityException('Expected a regular source XML file: '.$this->path);
        }
        $input = fopen($this->file, 'rb');
        $snapshot = fopen('php://temp/maxmemory:2097152', 'w+b');
        if (false === $input || false === $snapshot) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($snapshot)) {
                fclose($snapshot);
            }
            throw new \RuntimeException('Cannot open XML snapshot: '.$this->path);
        }
        try {
            $hash = hash_init('md5');
            while (!feof($input)) {
                $chunk = fread($input, 1048576);
                if (false === $chunk || ('' === $chunk && !feof($input))) {
                    throw new \RuntimeException('Cannot read source XML: '.$this->path);
                }
                hash_update($hash, $chunk);
                if (fwrite($snapshot, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Cannot write XML snapshot: '.$this->path);
                }
            }
            if (!hash_equals($this->md5, hash_final($hash))) {
                throw new IntegrityException('Penn manifest checksum mismatch: '.$this->path);
            }
            if (!rewind($snapshot)) {
                throw new \RuntimeException('Cannot rewind XML snapshot.');
            }

            return $snapshot;
        } catch (\Throwable $error) {
            fclose($snapshot);
            throw $error;
        } finally {
            fclose($input);
        }
    }
}
