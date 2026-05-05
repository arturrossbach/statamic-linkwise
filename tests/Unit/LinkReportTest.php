<?php

namespace Inkline\Linkwise\Tests\Unit;

use Inkline\Linkwise\Indexer\EntryRecord;
use Inkline\Linkwise\Reports\LinkReport;
use PHPUnit\Framework\TestCase;

class LinkReportTest extends TestCase
{
    public function test_computes_inbound_and_outbound_counts(): void
    {
        $records = [
            'a' => new EntryRecord('a', 'Entry A', '/a', 'pages', '', outboundLinks: ['b']),
            'b' => new EntryRecord('b', 'Entry B', '/b', 'pages', '', outboundLinks: []),
        ];

        $report = new LinkReport($records);

        $this->assertSame(0, $report->inboundCount('a'));
        $this->assertSame(1, $report->outboundCount('a'));
        $this->assertSame(1, $report->inboundCount('b'));
        $this->assertSame(0, $report->outboundCount('b'));
    }

    public function test_detects_orphaned_entries(): void
    {
        $records = [
            'a' => new EntryRecord('a', 'Entry A', '/a', 'pages', '', outboundLinks: ['b']),
            'b' => new EntryRecord('b', 'Entry B', '/b', 'pages', '', outboundLinks: []),
            'c' => new EntryRecord('c', 'Entry C', '/c', 'pages', '', outboundLinks: []),
        ];

        $report = new LinkReport($records);

        $orphaned = $report->orphanedEntries();
        $orphanedIds = array_map(fn ($r) => $r->id, $orphaned);

        // A has 0 inbound (orphaned), C has 0 inbound (orphaned), B has 1 inbound (not orphaned)
        $this->assertContains('a', $orphanedIds);
        $this->assertContains('c', $orphanedIds);
        $this->assertNotContains('b', $orphanedIds);
        $this->assertSame(2, $report->orphanedCount());
    }

    public function test_handles_dangling_links(): void
    {
        $records = [
            'a' => new EntryRecord('a', 'Entry A', '/a', 'pages', '', outboundLinks: ['nonexistent']),
        ];

        $report = new LinkReport($records);

        // Ghost links (to entries not in index) are filtered out
        $this->assertSame(0, $report->inboundCount('a'));
        $this->assertSame(0, $report->outboundCount('a'));
        $this->assertSame(0, $report->inboundCount('nonexistent'));
        $this->assertSame(0, $report->totalInternalLinks());
    }

    public function test_handles_empty_index(): void
    {
        $report = new LinkReport([]);

        $this->assertSame(0, $report->totalEntries());
        $this->assertSame(0, $report->totalInternalLinks());
        $this->assertSame(0, $report->orphanedCount());
    }

    public function test_multiple_inbound_links(): void
    {
        $records = [
            'a' => new EntryRecord('a', 'Entry A', '/a', 'pages', '', outboundLinks: ['c']),
            'b' => new EntryRecord('b', 'Entry B', '/b', 'pages', '', outboundLinks: ['c']),
            'c' => new EntryRecord('c', 'Entry C', '/c', 'pages', '', outboundLinks: []),
        ];

        $report = new LinkReport($records);

        $this->assertSame(2, $report->inboundCount('c'));
    }

    public function test_total_internal_links(): void
    {
        $records = [
            'a' => new EntryRecord('a', 'Entry A', '/a', 'pages', '', outboundLinks: ['b', 'c']),
            'b' => new EntryRecord('b', 'Entry B', '/b', 'pages', '', outboundLinks: ['c']),
            'c' => new EntryRecord('c', 'Entry C', '/c', 'pages', '', outboundLinks: []),
        ];

        $report = new LinkReport($records);

        $this->assertSame(3, $report->totalInternalLinks());
    }

    public function test_to_array_structure(): void
    {
        $records = [
            'a' => new EntryRecord('a', 'Entry A', '/a', 'articles', '', outboundLinks: ['b']),
            'b' => new EntryRecord('b', 'Entry B', '/b', 'pages', '', outboundLinks: []),
        ];

        $report = new LinkReport($records);
        $data = $report->toArray();

        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('entries', $data);
        $this->assertArrayHasKey('collections', $data);

        $this->assertSame(2, $data['summary']['total_entries']);
        $this->assertSame(1, $data['summary']['total_links']);
        $this->assertSame(1, $data['summary']['orphaned_count']);

        $this->assertCount(2, $data['entries']);
        $this->assertSame(['articles', 'pages'], $data['collections']);

        $entryA = collect($data['entries'])->firstWhere('id', 'a');
        $this->assertSame(0, $entryA['inbound_count']);
        $this->assertSame(1, $entryA['outbound_count']);
        $this->assertTrue($entryA['is_orphaned']);

        $entryB = collect($data['entries'])->firstWhere('id', 'b');
        $this->assertSame(1, $entryB['inbound_count']);
        $this->assertFalse($entryB['is_orphaned']);

        // New Sprint 8 metrics
        $this->assertArrayHasKey('health', $data);
        $this->assertSame(0.5, $data['summary']['avg_outbound']);
        $this->assertSame(50, $data['health']['coverage']);
        $this->assertSame('ok', $data['health']['coverage_status']);
    }

    public function test_health_metrics(): void
    {
        $records = [
            'a' => new EntryRecord('a', 'Entry A', '/a', 'pages', '', outboundLinks: ['b', 'c']),
            'b' => new EntryRecord('b', 'Entry B', '/b', 'pages', '', outboundLinks: ['c']),
            'c' => new EntryRecord('c', 'Entry C', '/c', 'pages', '', outboundLinks: ['a']),
        ];

        $report = new LinkReport($records);
        $health = $report->health();

        // All 3 entries have inbound links: a←c, b←a, c←a,b → coverage 100%
        $this->assertSame(100, $health['coverage']);
        $this->assertSame('great', $health['coverage_status']);
        // 4 outbound links / 3 entries = 1.3
        $this->assertSame(1.3, $health['avg_outbound']);
        $this->assertSame('ok', $health['avg_outbound_status']);
    }

    public function test_most_and_least_linked(): void
    {
        $records = [
            'a' => new EntryRecord('a', 'Entry A', '/a', 'pages', '', outboundLinks: ['c']),
            'b' => new EntryRecord('b', 'Entry B', '/b', 'pages', '', outboundLinks: ['c']),
            'c' => new EntryRecord('c', 'Entry C', '/c', 'pages', '', outboundLinks: ['a']),
        ];

        $report = new LinkReport($records);

        $most = $report->mostLinkedEntry();
        $this->assertSame('Entry C', $most['title']);
        $this->assertSame(2, $most['count']);

        $least = $report->leastLinkedEntry();
        $this->assertSame('Entry A', $least['title']);
        $this->assertSame(1, $least['count']);
    }

    public function test_empty_report_health(): void
    {
        $report = new LinkReport([]);
        $health = $report->health();

        $this->assertSame(0, $health['coverage']);
        $this->assertSame('warning', $health['coverage_status']);
        $this->assertNull($report->mostLinkedEntry());
        $this->assertNull($report->leastLinkedEntry());
    }
}
