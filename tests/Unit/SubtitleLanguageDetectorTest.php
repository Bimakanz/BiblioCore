<?php

namespace Tests\Unit;

use App\Services\Anime\SubtitleLanguageDetector;
use PHPUnit\Framework\TestCase;

class SubtitleLanguageDetectorTest extends TestCase
{
    private function vtt(string $cue): string
    {
        return "WEBVTT\n\n00:00:01.000 --> 00:00:03.000\n{$cue}\n";
    }

    public function test_detects_simplified_chinese(): void
    {
        $detector = new SubtitleLanguageDetector();

        $this->assertSame('zh', $detector->classify($this->vtt('七百万年的历史即将结束。人类的末日就要来临。')));
    }

    public function test_detects_japanese(): void
    {
        $detector = new SubtitleLanguageDetector();

        $this->assertSame('ja', $detector->classify($this->vtt('これはテストです。あなたはそれを知っていますか。')));
    }

    public function test_detects_korean(): void
    {
        $detector = new SubtitleLanguageDetector();

        $this->assertSame('ko', $detector->classify($this->vtt('이것은 테스트입니다. 당신은 그것을 알고 있습니까?')));
    }

    public function test_detects_arabic(): void
    {
        $detector = new SubtitleLanguageDetector();

        $this->assertSame('ar', $detector->classify($this->vtt('هذه القصة هي من نسج الخيال. جميع الأسماء والشخصيات وهمية.')));
    }

    public function test_detects_thai(): void
    {
        $detector = new SubtitleLanguageDetector();

        $this->assertSame('th', $detector->classify($this->vtt('เรื่องนี้เป็นเรื่องสมมติ ชื่อและสถานที่ทั้งหมดไม่มีอยู่จริง')));
    }

    public function test_detects_english(): void
    {
        $detector = new SubtitleLanguageDetector();

        $this->assertSame('en', $detector->classify($this->vtt('The seven-million-year history of mankind is about to end.')));
    }

    public function test_detects_indonesian(): void
    {
        $detector = new SubtitleLanguageDetector();

        $this->assertSame('id', $detector->classify($this->vtt('Ini adalah cerita yang tidak nyata. Aku dan kamu tidak akan bisa kembali.')));
    }

    public function test_detects_thumbnail_sprite_tracks(): void
    {
        $detector = new SubtitleLanguageDetector();

        $this->assertSame('sprite', $detector->classify($this->vtt('thumbnails/sprite_0.webp#xywh=0,0,160,90')));
    }

    public function test_returns_null_for_empty_cues(): void
    {
        $detector = new SubtitleLanguageDetector();

        $this->assertNull($detector->classify("WEBVTT\n\n00:00:01.000 --> 00:00:03.000\n"));
    }
}
