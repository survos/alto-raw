<?php

declare(strict_types=1);

namespace Survos\AltoRaw;

/** Compatibility with archive.py page_geometry/tree_geometry/block_geometry. */
final class PageGeometry
{
    public function summarize(string $xml, array $tree): array
    {
        $head = substr($xml, 0, 262144);
        $width = preg_match('/<Page\b[^>]*?\bWIDTH="(\d+)"/', $head, $m) ? (int) $m[1] : null;
        $height = preg_match('/<Page\b[^>]*?\bHEIGHT="(\d+)"/', $head, $m) ? (int) $m[1] : null;
        $unit = preg_match('~<MeasurementUnit>([^<]+)</MeasurementUnit>~', $head, $m) ? $m[1] : null;
        if (null === $width || null === $height) {
            $width = $height = null;
            $stack = [$tree];
            while ([] !== $stack) {
                $node = array_pop($stack);
                if ('Page' === $node['t']) {
                    $a = $node['a'] ?? [];
                    if (isset($a['WIDTH'], $a['HEIGHT']) && is_numeric($a['WIDTH']) && is_numeric($a['HEIGHT'])) {
                        $width = (int) (float) $a['WIDTH'];
                        $height = (int) (float) $a['HEIGHT'];
                    }
                    break;
                }
                array_push($stack, ...array_reverse($node['c'] ?? []));
            }
        }
        $blocks = [];
        $stack = [$tree];
        while ([] !== $stack) {
            $node = array_pop($stack);
            if ('TextBlock' === $node['t']) {
                $a = $node['a'] ?? [];
                $lines = count(array_filter($node['c'] ?? [], static fn ($child) => 'TextLine' === $child['t']));
                $words = 0;
                $inner = [$node];
                while ([] !== $inner) {
                    $child = array_pop($inner);
                    $words += 'String' === $child['t'] ? 1 : 0;
                    array_push($inner, ...($child['c'] ?? []));
                }
                $blocks[] = [$a['ID'] ?? null, self::number($a['HPOS'] ?? null), self::number($a['VPOS'] ?? null), self::number($a['WIDTH'] ?? null), self::number($a['HEIGHT'] ?? null), $lines, $words];
            } else {
                array_push($stack, ...($node['c'] ?? []));
            }
        }
        usort($blocks, static fn ($a, $b) => [$a[2] ?? 0, $a[1] ?? 0] <=> [$b[2] ?? 0, $b[1] ?? 0]);

        return ['altoWidth' => $width, 'altoHeight' => $height, 'altoUnit' => $unit, 'blocks' => $blocks];
    }

    private static function number(?string $value): int|float|null
    {
        if (null === $value || !is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        if (!is_finite($number)) {
            throw new \UnexpectedValueException('Non-finite ALTO coordinate.');
        }

        return $number === (float) (int) $number ? (int) $number : $number;
    }
}
