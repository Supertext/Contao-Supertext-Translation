<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\ContaoTranslation\Html\FieldCodec;
use Supertext\ContaoTranslation\Html\SegmentDocument;

final class SegmentDocumentTest extends TestCase
{
    public function testRoundTripKeepsMarkupUmlautsAndInsertTags(): void
    {
        $doc = new SegmentDocument();
        $doc->add('tl_page.1.title', FieldCodec::textToHtml('Fish & chips'));
        $doc->add('tl_content.5.text', '<p>Read <a href="{{link_url::12}}">our <strong>guide</strong></a>[nbsp]now. Grüezi {{br}}</p>');
        $doc->add('tl_content.6.text', '   ');

        $html = $doc->toHtml();
        $this->assertStringContainsString('<meta charset="utf-8">', $html);
        $this->assertSame(2, $doc->count());
        $this->assertStringNotContainsString('{{br}}', $html);
        $this->assertStringContainsString('<span translate="no" data-st-ph="1">1</span>', $html);

        // The translator keeps the markup and placeholders.
        $result = $doc->parse(str_replace(['Read', 'now', 'Grüezi'], ['Lisez', 'maintenant', 'Grüessech'], $html));

        $this->assertSame([], $result['missing']);
        $this->assertSame([], $result['lostPlaceholders']);
        $this->assertSame('Fish &amp; chips', $result['translations']['tl_page.1.title']);
        $this->assertSame(
            '<p>Lisez <a href="{{link_url::12}}">our <strong>guide</strong></a>[nbsp]maintenant. Grüessech {{br}}</p>',
            $result['translations']['tl_content.5.text'],
        );
    }

    public function testReportsMissingSegmentsAndRestoresLostPlaceholders(): void
    {
        $doc = new SegmentDocument();
        $doc->add('a', 'Hello {{br}} world');
        $doc->add('b', 'Second');

        $result = $doc->parse('<html><body><div data-st-id="a">Hallo Welt</div><div data-st-id="b"></div></body></html>');

        $this->assertSame(['b'], $result['missing']);
        $this->assertSame(['a'], $result['lostPlaceholders']);
        $this->assertSame('Hallo Welt{{br}}', $result['translations']['a']);
    }

    public function testFieldCodecs(): void
    {
        $headline = serialize(['unit' => 'h2', 'value' => 'Welcome']);
        $list = serialize(['One', '<strong>Two</strong>', '']);
        $table = serialize([['Name', 'Price'], ['Coffee', '3.50']]);

        $this->assertSame(['h.value' => 'Welcome'], FieldCodec::segments('inputUnit', 'h', $headline));
        $this->assertSame(['l.0' => 'One', 'l.1' => '<strong>Two</strong>'], FieldCodec::segments('list', 'l', $list));
        $this->assertSame(['t.0.0' => 'Name', 't.0.1' => 'Price', 't.1.0' => 'Coffee', 't.1.1' => '3.50'], FieldCodec::segments('table', 't', $table));
        $this->assertSame([], FieldCodec::segments('inputUnit', 'h', serialize(['unit' => 'h2', 'value' => ''])));

        $this->assertSame(['unit' => 'h2', 'value' => 'Willkommen & hallo'], unserialize(FieldCodec::apply('inputUnit', 'h', $headline, ['h.value' => 'Willkommen &amp; hallo'])));
        $this->assertSame(['Eins', '<strong>Zwei</strong>', ''], unserialize(FieldCodec::apply('list', 'l', $list, ['l.0' => 'Eins', 'l.1' => '<strong>Zwei</strong>'])));
        $this->assertSame([['Name', 'Preis'], ['Kaffee', '3.50']], unserialize(FieldCodec::apply('table', 't', $table, ['t.0.1' => 'Preis', 't.1.0' => 'Kaffee'])));
    }

    public function testTextIsSentAsEscapedHtml(): void
    {
        // Contao 5 stores quotes/brackets as numeric entities but leaves & as is.
        $this->assertSame('Tom &amp; "Jerry" &lt;3', FieldCodec::textToHtml('Tom & &#34;Jerry&#34; &#60;3'));
        $this->assertSame(['t' => 'Fish &amp; chips'], FieldCodec::segments('text', 't', 'Fish & chips'));
    }

    public function testTextIsStoredTheWayEachContaoVersionDoes(): void
    {
        $translated = "L'été &amp; <b>co</b> (2) =\n #1 &lt;3";

        // Contao 6: as typed
        $this->assertSame("L'été & co (2) = #1 <3", FieldCodec::htmlToText($translated, 'raw'));
        // Contao 5 default (Input::encodeInput encodeAll)
        $this->assertSame('L&#39;été & co &#40;2&#41; &#61; &#35;1 &#60;3', FieldCodec::htmlToText($translated, 'encodeAll'));
        // Contao 5 decodeEntities fields
        $this->assertSame("L'été & co (2) = #1 &#60;3", FieldCodec::htmlToText($translated, 'encodeLessThanSign'));

        $headline = serialize(['unit' => 'h2', 'value' => 'Tea & cake']);
        $this->assertSame(['h.value' => 'Tea &amp; cake'], FieldCodec::segments('inputUnit', 'h', $headline));
        $this->assertSame('Tee &#40;&#41; & Kuchen', unserialize(FieldCodec::apply('inputUnit', 'h', $headline, ['h.value' => 'Tee () &amp; Kuchen'], 'encodeAll'))['value']);
    }

    public function testRejectsSerializedObjects(): void
    {
        $evil = 'a:1:{i:0;O:8:"stdClass":0:{}}';
        $this->assertSame([], FieldCodec::segments('list', 'l', $evil));
    }
}
