<?php

declare(strict_types=1);

namespace Survos\AltoRaw;

use Survos\BagIt\Source\FileSource;

/** Selected originals only; does not assert that the whole Penn bag was mirrored. */
final class OriginalXmlSource implements FileSource
{
    /** @var array<string, SourceXml> */
    private array $documents = [];

    /** @param iterable<SourceXml> $documents */
    public function __construct(iterable $documents)
    {
        foreach ($documents as $document) {
            $path = 'originals/'.$document->path;
            if (isset($this->documents[$path])) {
                throw new \InvalidArgumentException('Duplicate original XML path: '.$document->path);
            }
            $this->documents[$path] = $document;
        }
        if ([] === $this->documents) {
            throw new \InvalidArgumentException('Select at least one original XML payload.');
        }
        ksort($this->documents, SORT_STRING);
    }

    public function files(): iterable
    {
        yield from array_keys($this->documents);
    }

    public function readStream(string $path): mixed
    {
        if (!isset($this->documents[$path])) {
            throw new \OutOfBoundsException('Unknown original XML path: '.$path);
        }

        return $this->documents[$path]->openVerified();
    }
}
