<?php

declare(strict_types=1);

namespace Survos\AltoRaw;

use Survos\DataContracts\Metadata\ContentType;
use Survos\DataContracts\Vocabulary\ItemField;

/** Source projection only. Harvest owns ALTO repair, normalization, enrichment and folio layout. */
final class PennRawProjection
{
    public const BASE = 'https://panewsarchive.psu.edu';

    public function document(array $header, array $pages): array
    {
        if (!preg_match('/^(sn\d+)-(\d{4}-\d{2}-\d{2})-ed-([1-9]\d*)$/D', $header['issue'], $m)) {
            throw new \UnexpectedValueException('Invalid Penn issue identity.');
        }
        $label = $header['title']['label'] ?? $header['title']['name'];
        $url = self::BASE.'/lccn/'.$m[1].'/'.$m[2].'/ed-'.$m[3].'.json';
        $assets = [];
        foreach ($pages as $page) {
            $canvas = $page['canvas'];
            $service = self::url($canvas['images'][0]['resource']['service']['@id']);
            $assets[] = [ItemField::THUMBNAIL_URL => self::url($canvas['thumbnail']), 'pageIndex' => $page['seq'] - 1, 'iiifInfo' => $service.'/info.json', 'url' => $service.'/full/full/0/default.jpg', 'width' => $canvas['width'], 'height' => $canvas['height'], PennFields::ALTO_TREE => $page['alto'], PennFields::PROVENANCE => ['ocrPath' => $page['path'], 'ocrMd5' => $page['md5'], 'ocrSha256' => $page['sha256'], 'imagePath' => $page['imagePath'], 'imageMd5' => $page['imageMd5']]];
        }
        if ([] === $assets) {
            throw new \UnexpectedValueException('Issue has no pages.');
        }
        $doc = [ItemField::ID => $header['issue'], ItemField::TITLE => $label.' — '.$m[2], ItemField::CONTENT_TYPE => ContentType::NEWSPAPER, ItemField::DATE => $m[2], ItemField::CITATION_URL => substr($url, 0, -5).'/', ItemField::SOURCE_API_URL => $url, ItemField::THUMBNAIL_URL => $assets[0][ItemField::THUMBNAIL_URL], PennFields::LCCN => $m[1], PennFields::EDITION => (int) $m[3], PennFields::PUBLICATION_TITLE => $label, PennFields::ISSUE_KIND => 'newspaper issue', PennFields::SOURCE_LABEL => 'Pennsylvania Newspaper Archive', PennFields::ASSETS => $assets, PennFields::SOURCE => $header['source'], PennFields::ARCHIVE => 'pennsylvania', PennFields::SCANS => array_map(static fn ($p) => ['index' => $p['pageIndex'], 'imageUrl' => $p['url'], 'width' => $p['width'], 'height' => $p['height'], 'text' => null, 'layout' => null], $assets), PennFields::OCR_TEXT => '', 'issueProcessing' => ['stage' => 'source_capture', 'reviewed' => false, 'pages' => count($assets)]];
        foreach ($header['title']['metadata'] ?? [] as $entry) {
            if (in_array($entry['label'], [ItemField::STATE, ItemField::CITY, ItemField::COUNTRY, ItemField::LANGUAGE], true)) {
                $values = is_array($entry['value']) ? $entry['value'] : [$entry['value']];
                $values = array_map(static fn ($v) => is_array($v) ? $v['@value'] : $v, $values);
                $values = array_values(array_unique(array_filter($values, static fn ($v) => is_string($v) && '' !== trim($v))));
                if ([] !== $values) {
                    $doc[$entry['label']] = 1 === count($values) ? $values[0] : $values;
                }
            }
        }
        if (isset($header['source']['license'])) {
            $doc[ItemField::RIGHTS] = $header['source']['license'];
        }
        $doc[PennFields::PROVENANCE] = ['batch' => $header['batch'], 'manifestSha256' => $header['manifestSha256'], 'checksumAlgorithm' => 'md5', 'scope' => PennFields::SCOPE];

        return $doc;
    }

    public function metadata(array $done, array $title): array
    {
        $inventoried = $title['manifests'] ?? $title['issues'] ?? throw new \UnexpectedValueException('Missing title issue inventory.');
        $label = $title['label'] ?? $title['name'];
        $provenance = array_intersect_key($done, array_flip(['batch', 'manifestSha256', 'checksumAlgorithm', 'scope']));
        $extras = [ItemField::CONTENT_TYPE => ContentType::NEWSPAPER, 'ftsContent' => 'none', PennFields::ISSUE_COUNT => count($inventoried), PennFields::CAPTURED_ISSUE_COUNT => $done['issues'], PennFields::CAPTURED_PAGE_COUNT => $done['pages'], PennFields::PROVENANCE => $provenance + [PennFields::ISSUE_COUNT => $done['issues'], PennFields::PAGE_COUNT => $done['pages']]];
        if ($done['issues'] === count($inventoried)) {
            $extras[PennFields::PAGE_COUNT] = $done['pages'];
        }

        return ['dataset' => ['datasetKey' => 'oni/pennsylvania-'.$done['lccn'], 'aggregator' => 'oni', 'sourceId' => 'pennsylvania-'.$done['lccn'], 'label' => $label, 'description' => sprintf('Pennsylvania Newspaper Archive: %d captured of %d inventoried issues.', $done['issues'], count($inventoried)), 'provider' => ['uri' => self::BASE.'/'], 'locale' => ['default' => 'en', 'targets' => []], 'source' => ['origin' => 'oni_json', 'include' => ['doc.jsonl.gz']], 'extras' => $extras, 'tags' => ['news', ContentType::NEWSPAPER, 'oni', 'pennsylvania']]];
    }

    /**
     * The issue an ONI issue JSON URL names.
     *
     * @return array{lccn: string, date: string, edition: int, id: string}
     */
    public static function identity(string $url): array
    {
        if (!preg_match('~/lccn/([a-z0-9]+)/(\d{4}-\d{2}-\d{2})/ed-([1-9][0-9]*)\.json$~D', $url, $m)) {
            throw new \UnexpectedValueException('Invalid ONI issue URL: '.$url);
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $m[2]);
        if (!$date || $date->format('Y-m-d') !== $m[2]) {
            throw new \UnexpectedValueException('Invalid issue date.');
        }

        return ['lccn' => $m[1], 'date' => $m[2], 'edition' => (int) $m[3], 'id' => $m[1].'-'.$m[2].'-ed-'.$m[3]];
    }

    public static function url(string $url): string
    {
        $url = preg_replace('~^https?://pana\.ulprod\.k8s\.libraries\.psu\.edu/~', self::BASE.'/', $url);
        if (str_starts_with($url, '/')) {
            $url = self::BASE.$url;
        }
        if ('panewsarchive.psu.edu' !== parse_url($url, PHP_URL_HOST) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new \UnexpectedValueException('Unexpected Penn source host.');
        }

        return $url;
    }
}
