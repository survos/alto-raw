#!/usr/bin/env python3
"""Explode a LOC NDNP OCR archive (.tar.bz2) into per-issue gzip members packed per title, plus a catalog: the "vault gz" layer.

Why Python: this is file-level archive work (stream a bzip2 tar, hash as it is read, regroup members, write atomically), which the
standard library does well. Everything downstream of the layer it writes is PHP.

A batch archive is read once. Each issue becomes one gzip member, and a title's issues from the batch are concatenated into one pack
file (a series of gzip members is itself a valid gzip stream). The catalog records each issue's offset and length in its pack, so one
issue is one ranged read and still decompresses on its own; a title is built by reading only its own pack, with no archive scan and
no XML parse of the whole batch. Multi-title batches are split by title here, which is the cheap moment to do it.

Layout under <root> (a local directory today; the same keys will be object keys in the bucket):

    packs/<lccn>/<batch>.pack                                      the title's issues from the batch: one gzip member per issue
    catalog/<batch>.jsonl.gz                                       one row per issue: pack, offset, length (the member), sha256 of the member
    batches/<batch>.json                                           the batch manifest: written last, so its presence means "done"

Issue member (decompressed): line 1 is the header {"v":1, "kind":"loc-issue", "issue", "lccn", "date", "edition", "batch", "batchSha256",
"pages":[{"seq","altoWidth","altoHeight","altoUnit","bytes","sha256","blocks"}]}, where "blocks" lists the page's
TextBlocks as [id, hpos, vpos, width, height, lines, words] in ALTO units (raw: not scaled, not repaired); each following line is {"seq": n, "alto": <tree>}.
<tree> is a faithful JSON form of the page's ALTO XML: {"t": local name, "a": {attributes}, "x": text, "c": [children]}, with "n" on a
node only when its namespace differs from the root's (the root carries "ns"). Formatting whitespace between elements is not kept; no
element, attribute or text is dropped, so xml_from_tree() rebuilds an equivalent document (see the "verify" mode).

The source archive's SHA-256 is checked against LOC's published value before anything becomes visible: files are written to
<root>/.incoming/<batch>/ and moved into place only after the checksum matches.

Modes:
    explode <archive|-> <sha256> <root> [--lccn X] [--force] [--batch NAME]   the layer above ('-' reads the archive from stdin)
    verify  <archive> <root>                         rebuild XML from the layer and compare it with the archive, page by page
    materialize <root> --batch B --plan plan.json    write page-<n>.xml caches from the layer (the bridge to today's PHP capture;
                                                     same plan format as cron-america-stage-alto.py --plan)
"""
import argparse
import base64
import gzip
import hashlib
import io
import json
import os
import re
import shutil
import sys
import tarfile
from pathlib import Path
from xml.etree import ElementTree as ET

VERSION = 2
GZIP_LEVEL = int(os.environ.get('EXPLODE_GZIP_LEVEL', '6'))
PAGE = re.compile(r'^([a-z0-9]+)/(\d{4})/(\d{2})/(\d{2})/ed-([1-9][0-9]*)/seq-([1-9][0-9]*)/ocr\.xml$')
ET.register_namespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance')
ET.register_namespace('xlink', 'http://www.w3.org/1999/xlink')


class _Hashing:
    """Digest the compressed bytes as tarfile pulls them, so the read that extracts also verifies."""

    def __init__(self, stream):
        self.stream = stream
        self.digest = hashlib.sha256()

    def read(self, size=-1):
        block = self.stream.read(size)
        self.digest.update(block)
        return block


_SPLIT = {}


def _split(tag):
    cached = _SPLIT.get(tag)
    if cached is None:
        if tag.startswith('{'):
            ns, local = tag[1:].split('}', 1)
            cached = (ns, local)
        else:
            cached = (None, tag)
        _SPLIT[tag] = cached
    return cached


def tree_from_element(node, root_ns):
    ns, local = _split(node.tag)
    out = {'t': local}
    if ns != root_ns:
        out['n'] = ns
    if node.attrib:
        out['a'] = dict(node.attrib)
    if node.text is not None and node.text.strip() != '':
        out['x'] = node.text
    children = [tree_from_element(child, root_ns) for child in node]
    if children:
        out['c'] = children
    return out


def alto_tree(data):
    root = ET.fromstring(data)
    ns, local = _split(root.tag)
    tree = tree_from_element(root, ns)
    tree['ns'] = ns
    return tree


def element_from_tree(tree, root_ns):
    ns = tree.get('n', root_ns)
    node = ET.Element(('{%s}%s' % (ns, tree['t'])) if ns else tree['t'], tree.get('a', {}))
    if 'x' in tree:
        node.text = tree['x']
    for child in tree.get('c', []):
        node.append(element_from_tree(child, root_ns))
    return node


def xml_from_tree(tree):
    """Rebuild an equivalent ALTO document from the layer's JSON form."""
    ns = tree.get('ns')
    if ns:
        ET.register_namespace('', ns)
    return ET.tostring(element_from_tree(tree, ns), encoding='utf-8', xml_declaration=True)


ALTO_WIDTH = re.compile(rb'<Page\b[^>]*?\bWIDTH="(\d+)"')
ALTO_HEIGHT = re.compile(rb'<Page\b[^>]*?\bHEIGHT="(\d+)"')
ALTO_UNIT = re.compile(rb'<MeasurementUnit>([^<]+)</MeasurementUnit>')
HEADER_BYTES = 262144


def page_geometry(data):
    head = data[:HEADER_BYTES]
    width, height, unit = ALTO_WIDTH.search(head), ALTO_HEIGHT.search(head), ALTO_UNIT.search(head)
    unit = unit.group(1).decode() if unit else None
    if width is None or height is None:
        return None, None, unit
    return int(width.group(1)), int(height.group(1)), unit


def tree_geometry(tree):
    """Width and height from the first Page node of a parsed tree, for pages the header scan could not read."""
    stack = [tree]
    while stack:
        node = stack.pop()
        if node.get('t') == 'Page':
            a = node.get('a', {})
            try:
                return int(float(a['WIDTH'])), int(float(a['HEIGHT']))
            except (KeyError, ValueError):
                return None, None
        stack.extend(reversed(node.get('c', [])))
    return None, None


def count_words(tree):
    """(words, sum of word confidence, words with a confidence) over the String elements of a page tree."""
    words = conf_sum = conf_n = 0
    stack = [tree]
    while stack:
        node = stack.pop()
        if node['t'] == 'String':
            words += 1
            wc = node.get('a', {}).get('WC')
            if wc is not None:
                try:
                    conf_sum += float(wc)
                    conf_n += 1
                except ValueError:
                    pass
        stack.extend(node.get('c', ()))
    return words, conf_sum, conf_n


def _number(value):
    try:
        number = float(value)
    except (TypeError, ValueError):
        return None
    return int(number) if number == int(number) else number


def block_geometry(tree):
    """Raw TextBlock boxes of a page, in ALTO units, unscaled and unrepaired: [id, hpos, vpos, width, height, lines, words].

    Deliberately not harvest's processed layout (AltoParser and its repairs, scaled to the scan): that stays PHP's job, where the
    repairs can change without rewriting this layer. These boxes are enough to screen pages for defects or size columns without
    reading the page's full tree.
    """
    blocks = []
    stack = [tree]
    while stack:
        node = stack.pop()
        if node['t'] == 'TextBlock':
            a = node.get('a', {})
            lines = sum(1 for c in node.get('c', ()) if c['t'] == 'TextLine')
            words = 0
            inner = [node]
            while inner:
                n = inner.pop()
                if n['t'] == 'String':
                    words += 1
                inner.extend(n.get('c', ()))
            blocks.append([a.get('ID'), _number(a.get('HPOS')), _number(a.get('VPOS')), _number(a.get('WIDTH')), _number(a.get('HEIGHT')), lines, words])
            continue
        stack.extend(node.get('c', ()))
    blocks.sort(key=lambda b: (b[2] if b[2] is not None else 0, b[1] if b[1] is not None else 0))
    return blocks


def page_summary(tree):
    """count_words() and block_geometry() in one walk of the page tree: ((words, conf_sum, conf_n), blocks)."""
    words = conf_sum = conf_n = 0
    blocks = []
    stack = [(tree, None)]
    while stack:
        node, block = stack.pop()
        tag = node['t']
        if tag == 'TextBlock':
            a = node.get('a', {})
            block = [a.get('ID'), _number(a.get('HPOS')), _number(a.get('VPOS')), _number(a.get('WIDTH')), _number(a.get('HEIGHT')), 0, 0]
            blocks.append(block)
        elif tag == 'TextLine' and block is not None:
            block[5] += 1
        elif tag == 'String':
            words += 1
            if block is not None:
                block[6] += 1
            wc = node.get('a', {}).get('WC')
            if wc is not None:
                try:
                    conf_sum += float(wc)
                    conf_n += 1
                except ValueError:
                    pass
        for child in node.get('c', ()):
            stack.append((child, block))
    blocks.sort(key=lambda b: (b[2] if b[2] is not None else 0, b[1] if b[1] is not None else 0))
    return (words, conf_sum, conf_n), blocks


def page_bytes(page):
    """The original bytes of a stored page: rebuilt from its tree, or restored from the copy kept for a page that was not XML."""
    unparsed = page.get('unparsed')
    if unparsed:
        if unparsed.get('b64gz') is None:
            raise ValueError('Original bytes of an unparsed page were not kept (too large)')
        return gzip.decompress(base64.b64decode(unparsed['b64gz']))
    return xml_from_tree(page['alto'])


def _pack_path(lccn, batch):
    return 'packs/%s/%s.pack' % (lccn, batch)


def _member(lines):
    """One issue as a single gzip member (deterministic: fixed level, no timestamp)."""
    buffer = io.BytesIO()
    with gzip.GzipFile(fileobj=buffer, mode='wb', compresslevel=GZIP_LEVEL, mtime=0) as gz:
        for line in lines:
            gz.write((json.dumps(line, ensure_ascii=False, separators=(',', ':')) + '\n').encode())
    return buffer.getvalue()


def _issue_key(row):
    year, month, day = row['date'].split('-')
    return (row['lccn'], year, month, day, int(row['edition']))


def load_catalog(root, batch):
    """The batch catalog as {(lccn, yyyy, mm, dd, edition): row}."""
    with gzip.open(Path(root) / 'catalog' / (batch + '.jsonl.gz'), 'rt', encoding='utf-8') as handle:
        return {_issue_key(row): row for row in map(json.loads, filter(str.strip, handle))}


def _write_gz(path, lines):
    path.parent.mkdir(parents=True, exist_ok=True)
    pending = path.with_name(path.name + '.pending')
    sha = hashlib.sha256()
    with open(pending, 'wb') as raw:
        with gzip.GzipFile(fileobj=raw, mode='wb', compresslevel=GZIP_LEVEL, mtime=0) as gz:
            for line in lines:
                encoded = (json.dumps(line, ensure_ascii=False, separators=(',', ':')) + '\n').encode()
                gz.write(encoded)
        raw.flush()
    with open(pending, 'rb') as check:
        for block in iter(lambda: check.read(1 << 20), b''):
            sha.update(block)
    os.replace(pending, path)
    return path.stat().st_size, sha.hexdigest()


def explode(archive, checksum, root, only_lccn=None, force=False, batch_name=None):
    """archive is a path, or '-' to read the .tar.bz2 from stdin (with batch_name), e.g. `rclone cat remote/x.tar.bz2 | ... explode - ...`."""
    stream = archive == '-'
    root = Path(root)
    batch = batch_name if stream else Path(archive).name.removesuffix('.tar.bz2')
    archive = Path(batch + '.tar.bz2') if stream else Path(archive)
    if not re.fullmatch(r'[A-Za-z0-9_]+', batch):
        raise ValueError('Invalid archive batch name')
    manifest = root / 'batches' / (batch + '.json')
    if not force and manifest.is_file() and json.loads(manifest.read_text()).get('sha256') == checksum and only_lccn is None:
        return json.loads(manifest.read_text()), True
    incoming = root / '.incoming' / batch
    if incoming.exists():
        shutil.rmtree(incoming)
    incoming.mkdir(parents=True)
    catalog, titles = [], {}
    current, pages = None, []
    totals = {'bytesXml': 0, 'bytesGz': 0, 'pages': 0}

    spool = incoming / '_spool'
    fragments = {}

    def spill():
        """Park the pages read so far for the current issue. Archives interleave pages of different issues, so an issue is only
        assembled once the whole archive has been read."""
        nonlocal current, pages
        if current is None:
            return
        parts = fragments.setdefault(current, [])
        path = spool / ('%s_%s_%s_%s_%d' % current) / ('%d.json.gz' % len(parts))
        path.parent.mkdir(parents=True, exist_ok=True)
        with gzip.GzipFile(path, 'wb', compresslevel=1, mtime=0) as handle:
            handle.write(json.dumps(pages, ensure_ascii=False, separators=(',', ':')).encode())
        parts.append(path)
        current, pages = None, []

    def assemble(key):
        lccn, year, month, day, edition = key
        pages = []
        for part in fragments[key]:
            with gzip.open(part, 'rt', encoding='utf-8') as handle:
                pages.extend(json.load(handle))
            part.unlink()
        # A few archives carry two versions of a page (LOC re-OCR). Extraction keeps the later one, so the page is the last
        # occurrence; the earlier copies are recorded (seq, bytes, sha256) so nothing is silently lost and verify can account for them.
        superseded = []
        by_seq = {}
        for page in pages:
            if page['seq'] in by_seq:
                old = by_seq[page['seq']]
                superseded.append({'seq': old['seq'], 'bytes': old['bytes'], 'sha256': old['sha256']})
            by_seq[page['seq']] = page
        pages = sorted(by_seq.values(), key=lambda p: p['seq'])
        issue = '%s/%s/%s/%s/ed-%d' % (lccn, year, month, day, edition)
        header = {'v': VERSION, 'kind': 'loc-issue', 'issue': issue, 'lccn': lccn, 'date': '%s-%s-%s' % (year, month, day),
                  'edition': edition, 'batch': batch, 'batchSha256': checksum,
                  'pages': [{k: p[k] for k in ('seq', 'altoWidth', 'altoHeight', 'altoUnit', 'bytes', 'sha256', 'blocks')} for p in pages]}
        if superseded:
            header['superseded'] = superseded
        relative = _pack_path(lccn, batch)
        member = _member([header] + [{'seq': p['seq'], 'alto': p['tree'], **({'unparsed': p['unparsed']} if p.get('unparsed') else {})} for p in pages])
        pack = incoming / relative
        pack.parent.mkdir(parents=True, exist_ok=True)
        offset = pack.stat().st_size if pack.exists() else 0
        with open(pack, 'ab') as handle:
            handle.write(member)
        size, digest = len(member), hashlib.sha256(member).hexdigest()
        words = conf_sum = conf_n = 0
        for p in pages:
            w, s, n = p['words']
            words, conf_sum, conf_n = words + w, conf_sum + s, conf_n + n
        catalog.append({'issue': issue, 'lccn': lccn, 'date': header['date'], 'edition': edition, 'batch': batch, 'pack': relative, 'offset': offset, 'length': size,
                        'pages': len(pages), 'superseded': len(superseded), 'unparsed': sum(1 for p in pages if p.get('unparsed')), 'seqs': [p['seq'] for p in pages], 'altoUnit': pages[0]['altoUnit'], 'words': words,
                        'textBlocks': sum(len(p['blocks']) for p in pages),
                        'meanWordConfidence': round(conf_sum / conf_n, 4) if conf_n else None,
                        'bytesXml': sum(p['bytes'] for p in pages), 'bytesGz': size, 'sha256': digest})
        title = titles.setdefault(lccn, {'issues': 0, 'pages': 0, 'first': None, 'last': None})
        title['issues'] += 1
        title['pages'] += len(pages)
        title['first'] = min(filter(None, [title['first'], header['date']]))
        title['last'] = max(filter(None, [title['last'], header['date']]))
        totals['bytesXml'] += catalog[-1]['bytesXml']
        totals['bytesGz'] += size
        totals['pages'] += len(pages)

    with (sys.stdin.buffer if stream else archive.open('rb')) as raw:
        hashing = _Hashing(raw)
        with tarfile.open(fileobj=hashing, mode='r|bz2') as entries:
            for member in entries:
                identity = PAGE.fullmatch(member.name)
                if identity is None:
                    continue
                lccn, year, month, day, edition, seq = identity.groups()
                if only_lccn and lccn != only_lccn:
                    continue
                if not member.isfile() or not 0 < member.size <= 100_000_000:
                    raise ValueError('Invalid ALTO member')
                key = (lccn, year, month, day, int(edition))
                if key != current:
                    spill()
                    current = key
                data = entries.extractfile(member).read()
                width, height, unit = page_geometry(data)
                # A few pages are not XML at all (LOC delivered a blank 993 KB file for one). Keep the page, with its original
                # bytes compressed (a blank compresses to almost nothing) and its checksum, instead of failing the batch.
                try:
                    tree = alto_tree(data)
                except ET.ParseError:
                    tree = None
                unparsed = None
                if tree is None:
                    unparsed = {'reason': 'not well-formed XML', 'blank': not data.strip(), 'bytes': len(data),
                                'b64gz': base64.b64encode(gzip.compress(data, 9, mtime=0)).decode() if len(data) <= 8_000_000 else None}
                elif width is None:
                    width, height = tree_geometry(tree)
                summary = page_summary(tree) if tree is not None else ((0, 0, 0), [])
                pages.append({'seq': int(seq), 'altoWidth': width, 'altoHeight': height, 'altoUnit': unit, 'bytes': len(data),
                              'sha256': hashlib.sha256(data).hexdigest(), 'tree': tree, 'unparsed': unparsed, 'words': summary[0], 'blocks': summary[1]})
            spill()
        for key in sorted(fragments):
            assemble(key)
        shutil.rmtree(spool, ignore_errors=True)
        while hashing.read(1 << 20):
            pass
    if only_lccn is None and hashing.digest.hexdigest() != checksum:
        shutil.rmtree(incoming)
        raise ValueError('Archive checksum differs from LOC inventory')
    # Verified: move the files into place, then the catalog, then (last) the manifest that says the batch is complete.
    for relative in sorted({row['pack'] for row in catalog}):
        destination = root / relative
        destination.parent.mkdir(parents=True, exist_ok=True)
        os.replace(incoming / relative, destination)
    catalog.sort(key=lambda r: (r['lccn'], r['date'], r['edition']))
    _write_gz(root / 'catalog' / (batch + '.jsonl.gz'), catalog)
    result = {'v': VERSION, 'batch': batch, 'archive': archive.name, 'sha256': checksum, 'titles': titles, 'issues': len(catalog),
              'pages': totals['pages'], 'bytesXml': totals['bytesXml'], 'bytesGz': totals['bytesGz'], 'complete': only_lccn is None}
    manifest.parent.mkdir(parents=True, exist_ok=True)
    pending = manifest.with_suffix('.json.pending')
    pending.write_text(json.dumps(result, indent=1))
    os.replace(pending, manifest)
    shutil.rmtree(incoming, ignore_errors=True)
    return result, False


def read_issue(root, row):
    """One issue from its pack: a ranged read of the catalog row's member."""
    with open(Path(root) / row['pack'], 'rb') as handle:
        handle.seek(row['offset'])
        member = handle.read(row['length'])
    if hashlib.sha256(member).hexdigest() != row['sha256']:
        raise ValueError('Issue member does not match its catalog checksum: %s' % row['issue'])
    lines = gzip.decompress(member).decode('utf-8').splitlines()
    return json.loads(lines[0]), [json.loads(line) for line in lines[1:] if line.strip()]


def _canonical(data):
    """Order-independent, whitespace-insensitive form of an ALTO document, for comparing archive XML with the rebuilt XML."""
    root = ET.fromstring(data)

    def walk(node):
        return [node.tag, sorted(node.attrib.items()), (node.text or '').strip(), [walk(child) for child in node]]
    return walk(root)


def verify(archive, root):
    """Every ALTO page in the archive must equal the page rebuilt from the layer."""
    archive, root = Path(archive), Path(root)
    batch = archive.name.removesuffix('.tar.bz2')
    checked = bad = missing = superseded = 0
    index = load_catalog(root, batch)
    cache = {}
    with tarfile.open(archive, 'r|bz2') as entries:
        for member in entries:
            identity = PAGE.fullmatch(member.name)
            if identity is None:
                continue
            lccn, year, month, day, edition, seq = identity.groups()
            path = (lccn, year, month, day, int(edition))
            if path not in cache:
                cache.clear()
                cache[path] = {p['seq']: p for p in read_issue(root, index[path])[1]} if path in index else None
            pages = cache[path]
            data = entries.extractfile(member).read()
            if pages is None or int(seq) not in pages:
                missing += 1
                continue
            if index[path].get('superseded') and hashlib.sha256(data).hexdigest() in {d['sha256'] for d in read_issue(root, index[path])[0].get('superseded', [])}:
                superseded += 1
                continue
            checked += 1
            stored = pages[int(seq)]
            if stored.get('unparsed'):
                if page_bytes(stored) != data:
                    bad += 1
            elif _canonical(data) != _canonical(xml_from_tree(stored['alto'])):
                bad += 1
    return {'checked': checked, 'different': bad, 'missing': missing, 'superseded': superseded}


def materialize(root, batch, plan):
    """Write page-<position>.xml into each issue cache (what cron-america-stage-alto.py --plan does from the archive)."""
    root = Path(root)
    index = load_catalog(root, batch)
    written = 0
    for selection in plan:
        cache, page_count = Path(selection['cache']), selection['pages']
        payload = json.loads((cache / 'item.json').read_text())
        identity = re.fullmatch(r'https?://(?:www\.)?loc\.gov/item/([a-z0-9]+)/(\d{4})-(\d{2})-(\d{2})/ed-(\d+)/?', payload['item']['id'])
        if identity is None:
            raise ValueError('Expected issue-specific LOC item identity')
        lccn, year, month, day, edition = identity.groups()
        key = (lccn, year, month, day, int(edition))
        if key not in index:
            raise ValueError('Issue is not in the layer: %s' % (key,))
        header, pages = read_issue(root, index[key])
        by_seq = {p['seq']: p for p in pages}
        sequences = selection.get('sequences') or list(range(1, page_count + 1))
        if sorted(sequences) != list(sequences) or len(set(sequences)) != len(sequences) or len(sequences) != page_count:
            raise ValueError('Page sequences must be sorted, unique and match the page count')
        receipt = {'version': 1, 'archive': batch + '.tar.bz2', 'sha256': header['batchSha256'], 'issue': payload['item']['id'], 'members': {},
                   'source': 'vault-gz'}
        for position, sequence in enumerate(sequences):
            if sequence not in by_seq:
                raise ValueError('Page missing from the layer: seq-%d' % sequence)
            data = page_bytes(by_seq[sequence])
            destination = cache / ('page-%d.xml' % position)
            temporary = destination.with_suffix('.xml.pending')
            temporary.write_bytes(data)
            os.replace(temporary, destination)
            receipt['members']['%s/%s/%s/%s/ed-%s/seq-%d/ocr.xml' % (lccn, year, month, day, edition, sequence)] = {
                'file': destination.name, 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()}
            written += 1
        temporary = cache / 'bulk-alto-receipt.pending'
        temporary.write_text(json.dumps(receipt, indent=2))
        os.replace(temporary, cache / 'bulk-alto-receipt.json')
    return written


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest='mode', required=True)
    p = sub.add_parser('explode')
    p.add_argument('archive')
    p.add_argument('checksum')
    p.add_argument('root')
    p.add_argument('--lccn')
    p.add_argument('--force', action='store_true', help='Rebuild even when the batch is already complete in the layer')
    p.add_argument('--batch', help="Batch name; required when the archive is '-' (stdin)")
    p = sub.add_parser('verify')
    p.add_argument('archive')
    p.add_argument('root')
    p = sub.add_parser('materialize')
    p.add_argument('root')
    p.add_argument('--batch', required=True)
    p.add_argument('--plan', required=True)
    args = parser.parse_args()
    if args.mode == 'explode':
        result, skipped = explode(args.archive, args.checksum, args.root, args.lccn, args.force, args.batch)
        print(('Already complete: ' if skipped else 'Wrote ') + '%d issues, %d pages (%d titles) for %s: %.1f MB XML -> %.1f MB gz' % (
            result['issues'], result['pages'], len(result['titles']), result['batch'], result['bytesXml'] / 1e6, result['bytesGz'] / 1e6))
    elif args.mode == 'verify':
        result = verify(args.archive, args.root)
        print(json.dumps(result))
        sys.exit(0 if result['different'] == 0 and result['missing'] == 0 else 1)
    else:
        print('Staged %d pages from the layer' % materialize(args.root, args.batch, json.loads(Path(args.plan).read_text())))
