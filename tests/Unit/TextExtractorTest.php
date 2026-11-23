<?php

namespace Tests\Unit;

use App\Enums\KnowledgeFileType;
use App\Support\TextExtractor;
use PHPUnit\Framework\TestCase;

class TextExtractorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/extractor-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);

        parent::tearDown();
    }

    private function file(string $name, string $content): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    public function test_txt_is_returned_normalized_keeping_paragraphs(): void
    {
        $path = $this->file('a.txt', "Linha   um\t\r\n\r\n\r\n\r\nParágrafo\u{00A0}dois  \r\n");

        $text = (new TextExtractor)->extract($path, KnowledgeFileType::Txt);

        $this->assertSame("Linha um\n\nParágrafo dois", $text);
    }

    public function test_markdown_is_kept_as_is(): void
    {
        $path = $this->file('a.md', "# Título\n\n- item *um*\n- item **dois**\n");

        $this->assertSame("# Título\n\n- item *um*\n- item **dois**", (new TextExtractor)->extract($path, KnowledgeFileType::Md));
    }

    public function test_legacy_windows_1252_text_is_converted_to_utf8(): void
    {
        $path = $this->file('legacy.txt', "a\xE7\xE3o e cora\xE7\xE3o");

        $this->assertSame('ação e coração', (new TextExtractor)->extract($path, KnowledgeFileType::Txt));
    }

    public function test_utf8_bom_and_utf16_are_handled(): void
    {
        $bom = $this->file('bom.txt', "\xEF\xBB\xBFolá");
        $utf16 = $this->file('u16.txt', "\xFF\xFE".mb_convert_encoding('olá mundo', 'UTF-16LE', 'UTF-8'));

        $extractor = new TextExtractor;

        $this->assertSame('olá', $extractor->extract($bom, KnowledgeFileType::Txt));
        $this->assertSame('olá mundo', $extractor->extract($utf16, KnowledgeFileType::Txt));
    }

    public function test_html_drops_scripts_styles_and_tags_and_decodes_entities(): void
    {
        $html = <<<'HTML'
        <!DOCTYPE html>
        <html><head><title>Ignorado</title><style>body { color: red; }</style></head>
        <body>
        <script>alert('xss')</script>
        <!-- comentário -->
        <h1>Política &amp; Termos</h1>
        <p>Preço: R$&nbsp;10 &lt;b&gt; &eacute; caro</p>
        <p>Segunda<br>linha</p>
        <noscript>sem js</noscript>
        </body></html>
        HTML;

        $text = (new TextExtractor)->extract($this->file('a.html', $html), KnowledgeFileType::Html);

        $this->assertStringContainsString('Política & Termos', $text);
        $this->assertStringContainsString('Preço: R$ 10 <b> é caro', $text);
        $this->assertStringContainsString("Segunda\nlinha", $text);
        $this->assertStringNotContainsString('alert', $text);
        $this->assertStringNotContainsString('color: red', $text);
        $this->assertStringNotContainsString('Ignorado', $text);
        $this->assertStringNotContainsString('comentário', $text);
        $this->assertStringNotContainsString('sem js', $text);
        $this->assertStringContainsString("Termos\n\nPreço", $text);
    }

    public function test_control_characters_are_removed(): void
    {
        $path = $this->file('c.txt', "a\x00b\x07c\u{200B}d");

        $this->assertSame('abcd', (new TextExtractor)->extract($path, KnowledgeFileType::Txt));
    }

    public function test_pdf_extraction_when_the_binary_is_available(): void
    {
        $binary = getenv('PDFTOTEXT_PATH') ?: trim((string) shell_exec('command -v pdftotext 2>/dev/null'));

        if ($binary === '' || ! is_executable($binary)) {
            $this->markTestSkipped('pdftotext binary is not installed.');
        }

        // Minimal single page PDF with the text "Hello PDF", offsets computed below.
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 100] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            null,
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $stream = 'BT /F1 18 Tf 20 50 Td (Hello PDF) Tj ET';
        $objects[3] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        $text = (new TextExtractor($binary))->extract($this->file('a.pdf', $pdf), KnowledgeFileType::Pdf);

        $this->assertSame('Hello PDF', $text);
    }
}
