# ALTO raw

`survos/alto-raw` is a PHP library scaffold for Penn newspaper capture conversion.
It requires PHP 8.5 and `survos/bagit`; it has no Symfony or Harvest runtime dependency.

## First release: foundations, not a complete Penn converter

Implemented and tested against cached Lebanon source documents:

- `XmlTree::parse()` produces the existing Python v2 ALTO/METS tree representation.
- `SourceXml` verifies a selected XML file against its Penn manifest MD5 and returns
  an exact-byte snapshot (spilling to temporary disk above 2 MiB).
- `OriginalXmlSource` supplies selected originals to the shared BagItTools wrapper.
  Originals are stored under `data/originals/<Penn payload path>` in the new bag.
- Original XML hashes, original bytes in a verified BagIt, and decoded tree parity
  are tested using real Lebanon ALTO and METS fixtures, not regenerated expectations.

The complete Penn capture inventory, manifest/tagmanifest verification, canvas matching,
geometry summaries, deterministic gzip pack writer, catalog/completion receipts and
full conversion CLI are **not implemented in PHP yet**. No runnable placeholder
pretends to complete those stages. This package is being published first so it can
be registered in the package catalog before installation and monolink in Depot.

## Preserve originals

```php
use Survos\AltoRaw\OriginalXmlSource;
use Survos\AltoRaw\SourceXml;
use Survos\BagIt\BagIt;

// Paths/checksums come from the caller's verified Penn payload manifest.
$source = new OriginalXmlSource([
    new SourceXml('data/sn83032227/000001966/1857062401/0001.xml', $altoFile, $altoMd5),
    new SourceXml('data/sn83032227/000001966/1857062401/1857062401.xml', $metsFile, $metsMd5),
]);
(new BagIt())->createFrom($source, $newBagDirectory, [
    'Source-Organization' => 'Penn State University Libraries',
]);
```

The caller selects complete source documents and must authenticate the manifest
chain and issue inventory before using these adapters. This API verifies the
selected files, not all of the upstream bag or referenced JP2s. BagIt creates its
own SHA-256 manifests and publishes only after validation; it refuses overwrite.
Originals remain byte-for-byte intact, including comments, tails and formatting.

The compatible JSON tree is deliberately lossy: formatting whitespace, tail text,
comments, processing instructions and namespace prefix spelling are not retained.
DTD inputs are rejected and external entity expansion is not enabled. Never use
this tree to reconstruct an archival original.

## Lebanon reference fixture

The pilot is **The Lebanon advertiser**, `sn83032227`, June 24, 1857, edition 1,
batch `batch_pst_aura_ver01`. `tests/Fixtures/lebanon/provenance.json` records
source identity, source MD5/SHA-256, manifest revision and the existing Python pack
member checksum. One complete ALTO page and one complete issue METS are stored
as gzip fixtures; decompression reproduces the original bytes. The golden trees
were extracted from the checksum-verified existing pack, not produced by PHP.
No JP2s or network calls are required for these tests.

```sh
composer install
composer test
# From the mono checkout with bagit's test dependencies already installed:
lib/bagit/vendor/bin/phpunit -c lib/alto-raw/phpunit.xml.dist
```

## Next phase after package registration

1. Implement verified Penn inventory and conversion around these foundations.
   Preserve METS/ALTO, source manifests and provenance beside the derived packs in
   the raw BagIt. Add issue-wide parity tests for canvas identity, geometry, missing
   payloads, repeat runs, failure publication and ranged gzip member decoding.
2. Require the published package in Depot, then use the existing `monolink` workflow.
   Depot owns HTTP inventory, local OWC reuse, missing downloads, JP2 checksum
   verification, workflow/Messenger state, S3 publication and Harvest request/ack.
3. Retrieve folio layout and resolve Penn image identities to verified OWC JP2s for
   local rend generation without downloading them again. M6 needs Depot, not the
   Harvest application installed.

Do not port, deploy or restart the active LOC fleet as part of this migration.
The existing Python package under `survos_alto_raw/` is retained unchanged as a
reference implementation; PHP does not invoke it. Its archive module is the frozen
LOC implementation, and `penn.py` can still be run separately by Python users.
The Python README previously called the tree lossless; the limitations above apply
to that representation too. Python tests: `PYTHONPATH=. python3 -m unittest discover -s tests`.
