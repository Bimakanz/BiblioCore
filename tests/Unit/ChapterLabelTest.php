<?php

namespace Tests\Unit;

use App\Services\Manga\Support\ChapterLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChapterLabelTest extends TestCase
{
    #[DataProvider('labels')]
    public function test_extracts_chapter_numbers(string $label, int $fallback, string $expected): void
    {
        $this->assertSame($expected, ChapterLabel::number($label, $fallback));
    }

    public static function labels(): array
    {
        return [
            'weebcentral extra with last read' => ['Extra 36 Last Read 2024-09-07T17:04:15.717343Z', 0, '36'],
            'plain chapter' => ['Chapter 12', 0, '12'],
            'decimal chapter' => ['Chapter 12.5', 0, '12.5'],
            'volume and chapter' => ['Vol. 2 Ch. 7', 0, '7'],
            'side story' => ['Side Story 3', 0, '3'],
            'zero padded' => ['Chapter 010', 0, '10'],
            'no number falls back to position' => ['Prologue', 5, '5'],
            'season label' => ['Season 2 - 15', 0, '15'],
        ];
    }

    public function test_clean_removes_last_read_marker_and_tags(): void
    {
        $this->assertSame('Extra 36', ChapterLabel::clean('<span>Extra 36</span> Last Read 2024-09-07T17:04:15Z'));
    }
}
