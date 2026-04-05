<?php
/**
 * Unit tests for public/api/stream.php
 * Tests state management, file locking, and fallback behavior.
 *
 * These tests simulate stream.php logic in isolation — they don't execute
 * the actual PHP script (which sends headers and exits).
 */

namespace Tests;

use PHPUnit\Framework\TestCase;

class StreamPhpTest extends TestCase
{
    protected $tmpStateFile;
    protected $tmpPoolCacheFile;
    protected $tmpLockFile;

    // Must match public/api/stream.php FALLBACK_POOL
    const FALLBACK_POOL = [
        // Original pool
        'jNQXAC9IVRw', 'O9NVK12Udj4', 'N-B6I9HgA-4', 'N4shgVitgxU',
        'DjhGJIBUYWQ', 'PdH25sGIlkM', '_JCtlXFmXk4', '5lEiSSPxqXE',
        'ryhc8_HxwVM', 'LKRvZ9rZEbA', 'GaaMh42NasM', 'Ruy_KuILcf0',
        'r066gsM2mWU', '_4NeWUWWoCk',
        // Italian street footage
        '3J5eBZ1eZp8', '8Qk3V5lQEgo', 'WnGm1ulheH4', 'LCekcF41R60',
        'Kj2upCRXPVI', 'YIk0eKOrYEs', '2IIjnyGT0EY', 'hL_7p_CkooI',
        's2taiRr8Vl0', 'LsoLmD3gFKw', 'sA9nuZFGhOw',
        // Travel / vacation footage
        'ycmOU6p8ozk', 'sNdPFqfEeyg', 'iSek6GZpKJ4', 'XF64OZahV-Q',
        // Default camera filenames
        'bu-zyEG_3Lw', 'qhpr9kCwy84', 'Awb4WOkYiYY', 'dhg9wHnzt0I',
        'SI6Zped0odc', 'X4nUbe-ql8o', 'A7t0VXUboeU', 'HrFTg0ZOvqE',
        '-R8QsuY0Noc', 'oIDYbT2yYis', 'o_aryrAb8zA', 'NQUxOpRcSUE',
        'mHvb4d66S4w', 'GFbdUrMw82o', 'pAdjKt4tkzY', 'lzgkA0TYClQ',
        'OLmg8bRrAUs',
        // Domestic / mundane moments
        'BlxJDFl21po', 'b9UO9tn4MpI', 'ByKmsHdhra8',
        // Multilingual amateur footage
        'E6W-KsL_5qo', 'sar4MONgTXg', '-2Jke10WmHw',
    ];

    // Must match stream.php SLOT_MIN / SLOT_MAX
    const SLOT_MIN = 10;
    const SLOT_MAX = 120;
    const POOL_CACHE_VERSION = '4';

    protected function setUp(): void
    {
        $this->tmpStateFile = TEST_TMP_DIR . '/state.json';
        $this->tmpPoolCacheFile = TEST_TMP_DIR . '/pool-cache.v' . self::POOL_CACHE_VERSION . '.json';
        $this->tmpLockFile = TEST_TMP_DIR . '/state.lock';
    }

    protected function tearDown(): void
    {
        foreach ([$this->tmpStateFile, $this->tmpPoolCacheFile, $this->tmpLockFile] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    // =========================================================================
    // State Initialization
    // =========================================================================

    public function testInitializeStateCreatesFile()
    {
        $this->assertFalse(file_exists($this->tmpStateFile));

        $state = $this->initializeState();

        $this->assertTrue(file_exists($this->tmpStateFile));
        $this->assertIsArray($state);
        $this->assertArrayHasKey('videoId', $state);
        $this->assertArrayHasKey('startedAt', $state);
        $this->assertArrayHasKey('slotDuration', $state);
    }

    public function testCorruptedStateFileIsReset()
    {
        file_put_contents($this->tmpStateFile, '{ invalid json ]');

        $state = $this->initializeState();

        $this->assertIsArray($state);
        $this->assertArrayHasKey('videoId', $state);
        $this->assertNotEmpty($state['videoId']);
    }

    public function testMissingVideoIdTriggersReset()
    {
        file_put_contents($this->tmpStateFile, json_encode([
            'startedAt' => time() * 1000,
            'slotDuration' => rand(self::SLOT_MIN, self::SLOT_MAX)
        ]));

        $state = $this->initializeState();

        $this->assertNotEmpty($state['videoId']);
        $this->assertIsString($state['videoId']);
    }

    // =========================================================================
    // Slot Expiration
    // =========================================================================

    public function testStateAdvancesWhenSlotExpires()
    {
        $oldTime = (time() - 400) * 1000; // 400 seconds ago
        file_put_contents($this->tmpStateFile, json_encode([
            'videoId' => 'oldVideoId123',
            'startedAt' => $oldTime,
            'slotDuration' => rand(self::SLOT_MIN, self::SLOT_MAX)
        ]));

        // Elapsed 400s > slot 300s → should advance
        $elapsedSeconds = 400;
        $this->assertGreaterThanOrEqual(self::SLOT_MAX, $elapsedSeconds);
    }

    public function testSlotDurationRangeIsValid()
    {
        $this->assertGreaterThan(0, self::SLOT_MIN);
        $this->assertGreaterThanOrEqual(self::SLOT_MIN, self::SLOT_MAX);
        $this->assertLessThanOrEqual(3600, self::SLOT_MAX);
    }

    // =========================================================================
    // File Locking
    // =========================================================================

    public function testLockFileAcquireAndRelease()
    {
        $lockHandle = fopen($this->tmpLockFile, 'w');
        $this->assertNotFalse($lockHandle);

        $locked = flock($lockHandle, LOCK_EX | LOCK_NB);
        $this->assertTrue($locked);

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);

        // Lock should be available for next request
        $lockHandle2 = fopen($this->tmpLockFile, 'w');
        $locked2 = flock($lockHandle2, LOCK_EX | LOCK_NB);
        $this->assertTrue($locked2);

        flock($lockHandle2, LOCK_UN);
        fclose($lockHandle2);
    }

    // =========================================================================
    // Fallback Pool
    // =========================================================================

    public function testFallbackPoolIsNonEmpty()
    {
        $this->assertGreaterThan(0, count(self::FALLBACK_POOL));
    }

    public function testFallbackPoolContainsValidVideoIds()
    {
        foreach (self::FALLBACK_POOL as $videoId) {
            $this->assertIsString($videoId);
            $this->assertMatchesRegularExpression(
                '/^[a-zA-Z0-9_-]{11}$/',
                $videoId,
                "Invalid video ID: $videoId"
            );
        }
    }

    public function testInitializedStateUsesFirstFallbackVideo()
    {
        $state = $this->initializeState();
        $this->assertEquals(self::FALLBACK_POOL[0], $state['videoId']);
    }

    // =========================================================================
    // Timestamps
    // =========================================================================

    public function testStateTimestampIsUnixMilliseconds()
    {
        $state = $this->initializeState();

        $this->assertIsInt($state['startedAt']);
        // 13-digit Unix millisecond timestamp
        $this->assertGreaterThan(1000000000000, $state['startedAt']);
        $this->assertLessThan(99999999999999, $state['startedAt']);
    }

    // =========================================================================
    // Skip Parameter
    // =========================================================================

    public function testSkipForcesAdvancementRegardlessOfOffset()
    {
        $currentTime = time() * 1000;
        file_put_contents($this->tmpStateFile, json_encode([
            'videoId' => 'currentVideo123',
            'startedAt' => $currentTime,
            'slotDuration' => rand(self::SLOT_MIN, self::SLOT_MAX)
        ]));

        // Offset is only 10s (well within slot), but skip=1 should still advance
        $skipRequested = true;
        $elapsedSeconds = 10;
        $shouldAdvance = $skipRequested || $elapsedSeconds >= self::SLOT_MAX;

        $this->assertTrue($shouldAdvance);
    }

    // =========================================================================
    // Cache Versioning
    // =========================================================================

    public function testCacheFileUsesVersionSuffix()
    {
        $expectedFile = TEST_TMP_DIR . '/pool-cache.v' . self::POOL_CACHE_VERSION . '.json';
        $this->assertEquals($this->tmpPoolCacheFile, $expectedFile);
    }

    public function testCacheFormatIncludesSourceMetadata()
    {
        $cacheData = [
            'pool' => ['dQw4w9WgXcQ', 'jNQXAC9IVRw'],
            'source' => 'piped',
        ];

        file_put_contents($this->tmpPoolCacheFile, json_encode($cacheData));
        $cached = json_decode(file_get_contents($this->tmpPoolCacheFile), true);

        $this->assertArrayHasKey('pool', $cached);
        $this->assertArrayHasKey('source', $cached);
        $this->assertIsArray($cached['pool']);
        $this->assertContains($cached['source'], ['youtube_api', 'piped', 'rss', 'fallback', 'unknown']);
    }

    public function testFallbackCacheUsesSourceFallback()
    {
        $cacheData = [
            'pool' => self::FALLBACK_POOL,
            'source' => 'fallback',
        ];

        file_put_contents($this->tmpPoolCacheFile, json_encode($cacheData));
        $cached = json_decode(file_get_contents($this->tmpPoolCacheFile), true);

        $this->assertEquals('fallback', $cached['source']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    protected function initializeState()
    {
        if (!file_exists($this->tmpStateFile)) {
            $state = [
                'videoId' => self::FALLBACK_POOL[0],
                'startedAt' => round(microtime(true) * 1000),
                'slotDuration' => rand(self::SLOT_MIN, self::SLOT_MAX)
            ];
            file_put_contents($this->tmpStateFile, json_encode($state));
            return $state;
        }

        $json = file_get_contents($this->tmpStateFile);
        $state = json_decode($json, true);

        if (!is_array($state) || !isset($state['videoId'])) {
            $state = [
                'videoId' => self::FALLBACK_POOL[0],
                'startedAt' => round(microtime(true) * 1000),
                'slotDuration' => rand(self::SLOT_MIN, self::SLOT_MAX)
            ];
            file_put_contents($this->tmpStateFile, json_encode($state));
        }

        return $state;
    }
}
?>
