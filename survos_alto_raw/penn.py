#!/usr/bin/env python3
"""Capture an existing Penn HTTP BagIt cache as verified lossless JSONL packs.

No downloads, no XML writes. Root is scoped to this title; the completion manifest
covers selected METS/ALTO only, never unmirrored JP2s or the entire bag.
Reuses the archive converter's tree, deterministic gzip and ranged-read format.
"""
import argparse
import hashlib
import importlib.util
import json
import os
import re
import shutil
from pathlib import Path

from . import archive as shared


def manifest(data):
    result = {}
    for line in data.decode().splitlines():
        match = re.fullmatch(r'([a-fA-F0-9]{32})\s+\*?([A-Za-z0-9_./-]+)', line)
        if not match:
            raise ValueError('Invalid manifest line')
        digest, path = match.groups()
        if path.startswith('/') or any(p in ('.', '..') for p in path.split('/')) or path in result:
            raise ValueError('Unsafe or duplicate manifest path: ' + path)
        result[path] = digest.lower()
    return result


def checked(bag, path, checksums):
    data = (bag / path).read_bytes()
    if hashlib.md5(data).hexdigest() != checksums[path]:
        raise ValueError('BagIt checksum mismatch: ' + path)
    return data


def inventory(bag, lccn):
    tags = manifest((bag / 'tagmanifest-md5.txt').read_bytes())
    for name in ('bagit.txt', 'bag-info.txt'):
        checked(bag, name, tags)
    source = checked(bag, 'manifest-md5.txt', tags)
    checksums = manifest(source)
    issues = {}
    for path in checksums:
        m = re.fullmatch(r'data/([a-z0-9]+)/[A-Za-z0-9]+/(\d{4})(\d{2})(\d{2})(\d{2})/(\d+)\.jp2', path)
        if m and m[1] == lccn:
            issue = f'{lccn}-{m[2]}-{m[3]}-{m[4]}-ed-{int(m[5])}'
            issues.setdefault(issue, []).append(path)
    if not issues:
        raise ValueError('No manifest pages for ' + lccn)
    return source, checksums, issues


def explode(capture, batch, root, limit=0):
    bag = capture / 'batches' / batch
    title = json.loads((capture / 'title.json').read_text())
    lccn = capture.parent.name.removeprefix('pennsylvania-')
    source, checksums, issues = inventory(bag, lccn)
    revision = hashlib.sha256(source).hexdigest()
    selected = sorted(issues)[:limit or None]
    # An exclusive lock keeps two writers from replacing a visible pack under one catalog.
    import fcntl
    root.mkdir(parents=True, exist_ok=True)
    with (root / '.lock').open('w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        done = root / 'batches' / (batch + '.json')
        if done.exists():
            existing = json.loads(done.read_text())
            if existing['manifestSha256'] == revision and existing['issueIds'] == selected:
                return existing
            raise ValueError('Output already published with a different scope; choose a new root')
        incoming = root / '.incoming' / batch
        if incoming.exists():
            shutil.rmtree(incoming)
        incoming.mkdir(parents=True)
        # Verify and spool every selected payload before assembling/publishing any pack.
        for issue in selected:
            paths = issues[issue]
            directory = str(Path(paths[0]).parent)
            mets = [p for p in checksums if str(Path(p).parent) == directory and re.search(r'/\d{10}(?:_\d+)?\.xml$', p)]
            if not mets:
                raise ValueError('Missing issue METS: ' + issue)
            metadata = []
            for path in mets:
                data = checked(bag, path, checksums)
                metadata.append({'path': path, 'md5': checksums[path], 'sha256': hashlib.sha256(data).hexdigest(), 'tree': shared.alto_tree(data)})
            remaining = set(paths)
            pages = []
            for seq in range(1, len(paths) + 1):
                canvas = json.loads((bag / directory / f'seq-{seq}.json').read_text())
                service = canvas['images'][0]['resource']['service']['@id']
                from urllib.parse import unquote
                candidates = [p for p in remaining if unquote(service).endswith('/' + batch + '/' + p)]
                if len(candidates) != 1:
                    raise ValueError('Unknown or repeated canvas: ' + issue)
                image = candidates[0]
                remaining.remove(image)
                path = image[:-4] + '.xml'
                data = checked(bag, path, checksums)
                tree = shared.alto_tree(data)
                if tree['t'] != 'alto':
                    raise ValueError('Expected ALTO: ' + path)
                width, height, unit = shared.page_geometry(data)
                if width is None:
                    width, height = shared.tree_geometry(tree)
                pages.append({'seq': seq, 'alto': tree, 'canvas': canvas, 'path': path, 'imagePath': image,
                              'md5': checksums[path], 'imageMd5': checksums[image], 'sha256': hashlib.sha256(data).hexdigest(),
                              'bytes': len(data), 'altoWidth': width, 'altoHeight': height, 'altoUnit': unit,
                              'blocks': shared.block_geometry(tree)})
            issue_source = json.loads((capture / issue / 'issue.json').read_text())
            header = {'v': shared.VERSION, 'kind': 'penn-issue', 'issue': issue, 'lccn': lccn,
                      'batch': batch, 'manifestSha256': revision, 'title': title, 'source': issue_source,
                      'mets': metadata, 'pages': [{k: v for k, v in p.items() if k not in ('alto', 'canvas')} for p in pages]}
            shared._write_gz(incoming / '_spool' / (issue + '.jsonl.gz'), [header] + pages)
        catalog = []
        relative = shared._pack_path(lccn, batch)
        pack = incoming / relative
        pack.parent.mkdir(parents=True, exist_ok=True)
        import gzip
        with pack.open('wb') as out:
            for issue in selected:
                with gzip.open(incoming / '_spool' / (issue + '.jsonl.gz'), 'rt') as handle:
                    lines = [json.loads(line) for line in handle]
                member = shared._member(lines)
                catalog.append({'issue': issue, 'pack': relative, 'offset': out.tell(), 'length': len(member),
                                'sha256': hashlib.sha256(member).hexdigest(), 'pages': len(lines) - 1})
                out.write(member)
        destination = root / relative
        destination.parent.mkdir(parents=True, exist_ok=True)
        os.replace(pack, destination)
        shared._write_gz(root / 'catalog' / (batch + '.jsonl.gz'), catalog)
        result = {'v': shared.VERSION, 'batch': batch, 'manifestSha256': revision, 'lccn': lccn,
                  'issueIds': selected, 'issues': len(selected), 'pages': sum(r['pages'] for r in catalog),
                  'checksumAlgorithm': 'md5', 'scope': 'selected issue METS and ALTO; JP2 references only; not the entire bag'}
        done.parent.mkdir(parents=True, exist_ok=True)
        pending = done.with_suffix('.pending')
        pending.write_text(json.dumps(result, sort_keys=True, indent=2))
        os.replace(pending, done)
        shutil.rmtree(incoming)
        return result


def verify(capture, batch, root):
    import gzip
    bag = capture / 'batches' / batch
    done = json.loads((root / 'batches' / (batch + '.json')).read_text())
    source, checksums, _ = inventory(bag, done['lccn'])
    if hashlib.sha256(source).hexdigest() != done['manifestSha256']:
        raise ValueError('Bag manifest revision changed')
    count = 0
    with gzip.open(root / 'catalog' / (batch + '.jsonl.gz'), 'rt') as catalog:
        for row in map(json.loads, catalog):
            header, pages = shared.read_issue(root, row)
            for page in pages:
                original = checked(bag, page['path'], checksums)
                if shared._canonical(original) != shared._canonical(shared.xml_from_tree(page['alto'])):
                    raise ValueError('Round trip failed: ' + page['path'])
                count += 1
            for mets in header['mets']:
                if shared._canonical(checked(bag, mets['path'], checksums)) != shared._canonical(shared.xml_from_tree(mets['tree'])):
                    raise ValueError('METS round trip failed: ' + mets['path'])
    if count != done['pages']:
        raise ValueError('Page inventory differs')
    return {'verifiedPages': count, 'issues': done['issues']}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=['explode', 'verify'])
    parser.add_argument('capture', type=Path)
    parser.add_argument('batch')
    parser.add_argument('root', type=Path)
    parser.add_argument('--limit', type=int, default=0)
    args = parser.parse_args()
    if not re.fullmatch(r'batch_[A-Za-z0-9_]+_ver\d+', args.batch) or args.limit < 0:
        parser.error('Invalid batch or limit')
    print(json.dumps(explode(args.capture, args.batch, args.root, args.limit) if args.mode == 'explode' else verify(args.capture, args.batch, args.root)))
