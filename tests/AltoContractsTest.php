<?php

declare(strict_types=1);

namespace Survos\AltoRaw\Tests;

use PHPUnit\Framework\TestCase;
use Survos\AltoRaw\AltoParser;
use Survos\AltoRaw\Contract\Block;
use Survos\AltoRaw\Contract\Element;
use Survos\AltoRaw\Contract\Line;
use Survos\AltoRaw\Contract\Page;
use Survos\AltoRaw\Contract\Word;

final class AltoContractsTest extends TestCase
{
    public function testSharedDomMatchesXmlAndRetainsSourceBeforeMutation(): void
    {
        $xml = '<alto><Layout><Page WIDTH="100" HEIGHT="200"><PrintSpace><TextBlock ID="b" HPOS="1" VPOS="2" WIDTH="90" HEIGHT="80"><TextLine><String CONTENT="news" HPOS="3" VPOS="4" WIDTH="10" HEIGHT="8"/></TextLine></TextBlock></PrintSpace></Page></Layout></alto>';
        $parser = new AltoParser();
        $dom = (new \Survos\AltoRaw\XmlTree())->document($xml);
        $source = $parser->parseDocument($dom, hash('sha256', $xml), $xml);
        self::assertEquals($parser->parse($xml, true), $source);
        $dom->getElementsByTagName('String')->item(0)->setAttribute('CONTENT', 'changed');
        self::assertSame('news', iterator_to_array($source->words())[0]->content);
    }

    public function testTypedHierarchyPreservesSourceFieldsAndExtensions(): void
    {
        $xml = '<a:alto xmlns:a="http://www.loc.gov/standards/alto/ns-v4#" xmlns:e="urn:extension"><a:Description>before<e:note e:flag="yes"/>after</a:Description><a:Layout><a:Page WIDTH="100.5" HEIGHT="200" PHYSICAL_IMG_NR="1" ID="p01"><a:PrintSpace><a:TextBlock ID="b01" HPOS="1.5" VPOS="2" WIDTH="90" HEIGHT="100" ROTATION="2" STYLEREFS="s01"><a:Shape><a:Polygon POINTS="1,2 91,2 91,102"/></a:Shape><a:TextLine ID="l01" BASELINE="1,20 91,20"><a:String ID="w01" HPOS="3" VPOS="4" WIDTH="20.5" HEIGHT="10" CONTENT="inter-" SUBS_TYPE="HypPart1" SUBS_CONTENT="international" WC="0.95" CC="012"><a:ALTERNATIVE>infer</a:ALTERNATIVE></a:String><a:SP WIDTH="3"/><a:HYP CONTENT="-"/><e:String CONTENT="extension"/></a:TextLine></a:TextBlock></a:PrintSpace></a:Page></a:Layout></a:alto>';
        $document = (new AltoParser())->parse($xml);
        self::assertInstanceOf(Page::class, $document->page);
        self::assertSame(100.5, $document->page->width);
        self::assertSame(1, $document->page->physicalImageNumber);
        self::assertNull($document->originalXml);
        self::assertSame(hash('sha256', $xml), $document->sourceSha256);
        $words = iterator_to_array($document->words());
        self::assertCount(1, $words);
        $word = $words[0];
        self::assertInstanceOf(Word::class, $word);
        self::assertSame('inter-', $word->content);
        self::assertSame('international', $word->searchableText());
        self::assertSame('012', $word->characterConfidence);
        self::assertSame(0.95, $word->confidence);
        self::assertSame([3.0, 4.0, 20.5, 10.0], $word->box());
        $block = $document->elements[$word->blockId - 1];
        $line = $document->elements[$word->lineId - 1];
        self::assertInstanceOf(Block::class, $block);
        self::assertInstanceOf(Line::class, $line);
        self::assertSame('b01', $block->sourceId);
        self::assertSame(2.0, $block->rotation);
        self::assertSame('1,20 91,20', $line->baseline);
        $extension = array_values(array_filter($document->elements, static fn ($n) => 'e:note' === $n->identity->name))[0];
        self::assertSame(Element::class, $extension::class);
        self::assertSame(['e:flag' => 'yes'], $extension->attributes);
        self::assertSame(['e:flag' => 'urn:extension'], $extension->attributeNamespaces);
        $description = $document->structure()['a:Description'][0];
        self::assertSame(['#text', 'e:note', '#text'], $description['_order']);
        self::assertSame(['before', 'after'], $description['#text']);
        self::assertSame($xml, (new AltoParser())->parse($xml, keepXml: true)->originalXml);
    }

    public function testZeroAreaWordsAreRetainedButNotIndexed(): void
    {
        $doc = (new AltoParser())->parse('<alto><Layout><Page WIDTH="10" HEIGHT="20"><TextBlock><TextLine><String CONTENT="zero" HPOS="0" VPOS="0" WIDTH="0" HEIGHT="3"/><String CONTENT="word" HPOS="0" VPOS="0" WIDTH="2" HEIGHT="3"/></TextLine></TextBlock></Page></Layout></alto>');
        self::assertCount(2, array_filter($doc->elements, static fn ($n) => $n instanceof Word));
        $words = iterator_to_array($doc->words());
        self::assertCount(1, $words);
        self::assertSame(0, $words[0]->wordIndex);
    }

    public function testInvalidNumericFieldFails(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new AltoParser())->parse('<alto><Layout><Page WIDTH="oops" HEIGHT="2"/></Layout></alto>');
    }

    public function testTypedParserAlsoRejectsDtd(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new AltoParser())->parse('<!DOCTYPE alto [<!ENTITY x SYSTEM "file:///never-read">]><alto>&x;</alto>');
    }
}
