<?php

namespace Tests\Unit\Parsing;

use App\Services\Parsing\HtmlMarathiBiodataTableExtractor;
use App\Services\Parsing\IntakeNormalizedBiodataDraftBuilder;
use App\Services\Parsing\IntakeNormalizedBiodataHtmlPreprocessor;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntakeNormalizedBiodataHtmlPreprocessorTest extends TestCase
{
    public function test_plain_text_passthrough_without_table_metadata(): void
    {
        $input = <<<'TXT'
मुलीचे नाव :- कु. अंजली पाटील
मोबाईल नं :- 9876543210
TXT;

        $prepared = app(IntakeNormalizedBiodataHtmlPreprocessor::class)->prepare($input);

        $this->assertFalse($prepared['has_structured_table']);
        $this->assertSame([], $prepared['table_hints']);
        $this->assertNull($prepared['post_table_body']);
        $this->assertStringContainsString('मुलीचे नाव', $prepared['text']);
        $this->assertStringContainsString('मोबाईल नं', $prepared['text']);
        $this->assertStringContainsString('9876543210', $prepared['text']);
    }

    public function test_basic_html_table_is_flattened_and_hints_extracted(): void
    {
        $html = <<<'HTML'
<table>
<tr><td>मुलीचे नाव</td><td>कु. अंजली पाटील</td></tr>
<tr><td>जन्म तारीख</td><td>01/01/1998</td></tr>
<tr><td>मोबाईल नं</td><td>9876543210</td></tr>
</table>
HTML;

        $prepared = app(IntakeNormalizedBiodataHtmlPreprocessor::class)->prepare($html);

        $this->assertTrue($prepared['has_structured_table']);
        $this->assertIsArray($prepared['table_hints']);
        $this->assertNotEmpty($prepared['table_hints']);
        $this->assertTrue(HtmlMarathiBiodataTableExtractor::isStructuredTableHints($prepared['table_hints']));

        $text = $prepared['text'];
        $this->assertStringContainsString('मुलीचे नाव', $text);
        $this->assertStringContainsString('कु. अंजली पाटील', $text);
        $this->assertStringContainsString('जन्म तारीख', $text);
        $this->assertStringContainsString('01/01/1998', $text);
        $this->assertStringContainsString('मोबाईल नं', $text);
        $this->assertStringContainsString('9876543210', $text);
        $this->assertStringNotContainsString('<td>', $text);
        $this->assertStringNotContainsString('<tr>', $text);
        $this->assertStringNotContainsString('<table', $text);
    }

    public function test_builder_stores_html_metadata_in_draft_meta_only(): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $html = <<<'HTML'
<table>
<tr><td>मुलीचे नाव</td><td>:-</td><td>कु. अंजली पाटील</td></tr>
<tr><td>मोबाईल नंबर</td><td>:-</td><td>9876543210</td></tr>
</table>
Print Shop Contact 9604289289
HTML;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($html);
        $meta = $draft['meta'] ?? [];

        $this->assertTrue($meta['html_table_structured'] ?? false);
        $this->assertArrayHasKey('table_hints', $meta);
        $this->assertIsArray($meta['table_hints']);
        $this->assertNotEmpty($meta['table_hints']);
        $this->assertStringContainsString('मुलीचे नाव', (string) ($draft['cleaned_text'] ?? ''));
        $this->assertStringContainsString('9876543210', (string) ($draft['cleaned_text'] ?? ''));

        $this->assertArrayNotHasKey('table_hints', $draft['normalized'] ?? []);
        $this->assertSame([], $queries);

        $this->assertSame(
            'Print Shop Contact 9604289289',
            $meta['post_table_body'] ?? null,
            'Post-table body is preserved in meta for a later footer-filter phase'
        );
    }
}
