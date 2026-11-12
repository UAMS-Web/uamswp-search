<?php

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use UAMS_Search\Config;
use UAMS_Search\Indexer;
use UAMS_Search\Query;
use UAMS_Search\Tests\Support\IntegrationTestCase;

/**
 * PDF attachments through WordPress' upload and attachment APIs. Ported from `tests/integration.php` (#107).
 */
final class PdfIndexTest extends IntegrationTestCase
{
    /** @return array<string, mixed> */
    private function search(string $s, array $args = []): array
    {
        return Query::run(array_replace(['s' => $s, 'site' => $this->site, 'scope' => 'site', 'suggest' => false], $args));
    }

    /** @return list<int> */
    private function ids(array $out): array
    {
        return array_map(intval(...), array_column($out['results'], 'id'));
    }

    private function attach(string $fixture, array $args): int
    {
        $upload = wp_upload_bits('uamsfixture-'.$fixture, null, (string) file_get_contents(dirname(__DIR__, 2).'/fixtures/'.$fixture));
        if ($upload['error']) {
            $dir = wp_upload_dir();
            throw new \RuntimeException($upload['error'].' DEBUG '.json_encode([$dir, is_dir($dir['basedir']), is_writable($dir['basedir']), posix_geteuid(), substr(sprintf('%o', fileperms($dir['basedir'])), -4), file_exists($dir['basedir'].'/2026')]));
        }

        $id = wp_insert_attachment(array_replace(['post_mime_type' => 'application/pdf', 'post_status' => 'inherit'], $args), $upload['file']);

        return $this->track($id);
    }

    public function set_up(): void
    {
        parent::set_up();
        $config = Config::defaults();
        $config['pdf'] = true;
        update_site_option('uams_search_settings', $config);
    }

    public function test_a_wordpress_pdf_attachment_is_extracted_indexed_and_cached(): void
    {
        $parent = $this->make_page();
        $pdf = $this->attach('text.pdf', ['post_title' => 'PDF fixture', 'post_parent' => $parent]);
        $row = Indexer::one($this->site, $pdf);
        $this->assertTrue($row['status'] === 'ready' && str_contains($row['pdf'], 'Cardiology PDF sentinel'), 'A WordPress PDF attachment is extracted and indexed');
        $out = $this->search('"Cardiology PDF sentinel"', ['type' => 'attachment']);
        $this->assertTrue(! $out['fallback'] && in_array($pdf, $this->ids($out), true), 'PDF-only text is found by real full-text search');
        $cached = Indexer::one($this->site, $pdf);
        $this->assertTrue($cached['file_signature'] === $row['file_signature'] && $cached['fields'] === $row['fields'], 'An unchanged PDF keeps its cached text and filename');
    }

    public function test_a_pdf_with_a_newly_private_parent_disappears_before_its_queue_update(): void
    {
        $parent = $this->make_page();
        $pdf = $this->attach('text.pdf', ['post_title' => 'PDF fixture', 'post_parent' => $parent]);
        Indexer::one($this->site, $pdf);
        wp_update_post(['ID' => $parent, 'post_status' => 'private']);
        $this->assertNotContains($pdf, $this->ids($this->search('"Cardiology PDF sentinel"', ['type' => 'attachment'])), 'A PDF with a newly private parent disappears before its queue update');
    }

    public function test_a_text_free_pdf_is_flagged_and_still_found_by_filename(): void
    {
        $scan = $this->attach('scanned.pdf', ['post_title' => 'Scanned fixture']);
        $row = Indexer::one($this->site, $scan);
        $this->assertTrue($row['status'] === 'scanned' && $row['pdf'] === '', 'A text-free PDF is flagged without blocking indexing');
        $this->assertContains($scan, $this->ids($this->search('uamsfixture-scanned', ['type' => 'attachment'])), 'A text-free PDF is still found by its filename');
    }
}
