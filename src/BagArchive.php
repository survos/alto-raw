<?php

declare(strict_types=1);

namespace Survos\AltoRaw;

use Survos\BagIt\BagIt;
use Survos\BagIt\Filesystem;

/** Uncompressed tar transport, compatible with Harvest's validated RawBagArchive reader. */
final class BagArchive
{
    public function pack(string $bag, string $target): void
    {
        (new BagIt())->verify($bag);
        if (file_exists($target)) {
            throw new \RuntimeException('Archive destination already exists.');
        }
        $temporary = dirname($target).'/.penn-'.bin2hex(random_bytes(8)).'.tar';
        try {
            $tar = new \PharData($temporary, format: \Phar::TAR);
            foreach (Filesystem::files($bag) as $path) {
                // This is a private generated bag, never the source capture.
                touch($bag.'/'.$path, 0);
                $tar->addFile($bag.'/'.$path, $path);
            }
            unset($tar);
            if (!rename($temporary, $target)) {
                throw new \RuntimeException('Cannot publish raw archive.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
