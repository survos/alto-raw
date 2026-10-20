"""Run with python3 -m unittest discover -s tests -p test_penn_bagit_jsonl.py."""
import importlib.util
import json
import hashlib
import tempfile
import unittest
from pathlib import Path

from survos_alto_raw import penn


class PennCaptureTest(unittest.TestCase):
    def fixture(self, root):
        capture = root / 'pennsylvania-sn1' / '_capture'
        batch = 'batch_pst_test_ver01'
        bag = capture / 'batches' / batch
        directory = 'data/sn1/123A/1857062401'
        (bag / directory).mkdir(parents=True)
        (capture / 'sn1-1857-06-24-ed-1').mkdir()
        (capture / 'title.json').write_text('{"label":"Test"}')
        (capture / 'sn1-1857-06-24-ed-1/issue.json').write_text('{}')
        payloads = {directory + '/1857062401.xml': b'<mets/>', directory + '/0001.jp2': b'never downloaded',
                    directory + '/0001.xml': b'<alto xmlns="urn:alto"><Layout><Page><TextBlock ID="b"><String CONTENT="hello"/></TextBlock></Page></Layout></alto>'}
        for path, data in payloads.items():
            if not path.endswith('.jp2'):
                (bag / path).write_bytes(data)
        manifest = ''.join(hashlib.md5(data).hexdigest() + '  ' + path + '\n' for path, data in payloads.items()).encode()
        tags = {'manifest-md5.txt': manifest, 'bagit.txt': b'BagIt-Version: 0.97', 'bag-info.txt': b'Bag'}
        for path, data in tags.items():
            (bag / path).write_bytes(data)
        (bag / 'tagmanifest-md5.txt').write_text(''.join(hashlib.md5(data).hexdigest() + '  ' + path + '\n' for path, data in tags.items()))
        service = 'https://panewsarchive.psu.edu/iiif/' + batch + '/' + directory + '/0001.jp2'
        (bag / directory / 'seq-1.json').write_text(json.dumps({'width': 100, 'height': 200, 'thumbnail': service + '/thumb', 'images': [{'resource': {'service': {'@id': service}}}]}))
        return capture, batch, bag / directory / '0001.xml'

    def test_round_trip_missing_geometry_determinism_and_no_xml_output(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            capture, batch, _ = self.fixture(root)
            for name in ('one', 'two'):
                penn.explode(capture, batch, root / name)
                self.assertEqual(penn.verify(capture, batch, root / name)['verifiedPages'], 1)
                self.assertEqual(list((root / name).rglob('*.xml')), [])
            for path in (root / 'one').rglob('*'):
                if path.is_file():
                    self.assertEqual(path.read_bytes(), (root / 'two' / path.relative_to(root / 'one')).read_bytes())

    def test_checksum_failure_never_publishes(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            capture, batch, xml = self.fixture(root)
            xml.write_bytes(b'<alto>corrupt</alto>')
            with self.assertRaisesRegex(ValueError, 'checksum mismatch'):
                penn.explode(capture, batch, root / 'out')
            self.assertFalse((root / 'out/batches' / (batch + '.json')).exists())
            self.assertFalse((root / 'out/packs').exists())
