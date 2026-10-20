<?php

declare(strict_types=1);

namespace Survos\AltoRaw;

/** Existing ONI raw wire fields consumed by Harvest's newspaper normalizer. */
final class PennFields
{
    public const ASSETS = 'pageAssets';
    public const ALTO_TREE = 'altoTree';
    public const PROVENANCE = 'bagProvenance';
    public const LCCN = 'lccn';
    public const EDITION = 'edition';
    public const PUBLICATION_TITLE = 'publicationTitle';
    public const ISSUE_KIND = 'issueKind';
    public const SOURCE_LABEL = 'sourceLabel';
    public const SOURCE = 'oniSourceMetadata';
    public const ARCHIVE = 'hostingArchive';
    public const SCANS = 'scans';
    public const OCR_TEXT = 'ocrText';
    public const ISSUE_COUNT = 'issueCount';
    public const PAGE_COUNT = 'pageCount';
    public const CAPTURED_ISSUE_COUNT = 'capturedIssueCount';
    public const CAPTURED_PAGE_COUNT = 'capturedPageCount';
}
