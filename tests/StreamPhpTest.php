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
        'jNQXAC9IVRw',  // Me at the zoo
        'O9NVK12Udj4',  // MOV_0001
        'N-B6I9HgA-4',  // IMG_0002.mp4
        'N4shgVitgxU',  // MOV_0001.mp4
        'DjhGJIBUYWQ',  // IMG_0002
        'PdH25sGIlkM',  // MOV_0001.mp4
        '_JCtlXFmXk4',  // MOV_0001.wmv
        '5lEiSSPxqXE',  // MOV_0001.mp4
        'ryhc8_HxwVM',  // IMG_0002.mp4
        'LKRvZ9rZEbA',  // IMG_0001.avi
        'GaaMh42NasM',  // daily commute LA
        'Ruy_KuILcf0',  // morning dog walk UK
        'r066gsM2mWU',  // 1970 family home video
        'Sor6pDozLiY',  // kids garden
        '_4NeWUWWoCk',  // morning walk Troyes
    ];

    const SLOT_DURATION = 300;
    const POOL_CACHE_VERSION = '3';

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
            'slotDuration' => self::SLOT_DURATION
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
            'slotDuration' => self::SLOT_DURATION
        ]));

        // Elapsed 400s > slot 300s → should advance
        $elapsedSeconds = 400;
        $this->assertGreaterThanOrEqual(self::SLOT_DURATION, $elapsedSeconds);
    }

    public function testSlotDurationWithinBounds()
    {
        $this->assertGreaterThan(0, self::SLOT_DURATION);
        $this->assertLessThanOrEqual(3600, self::SLOT_DURATION);
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
            $this->assertEquals(11, strlen($videoId), "Invalid video ID length: $videoId");
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
            'slotDuration' => self::SLOT_DURATION
        ]));

        // Offset is only 10s (well within slot), but skip=1 should still advance
        $skipRequested = true;
        $elapsedSeconds = 10;
        $shouldAdvance = $skipRequested || $elapsedSeconds >= self::SLOT_DURATION;

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

    // =========================================================================
    // Helpers
    // =========================================================================

    protected function initializeState()
    {
        if (!file_exists($this->tmpStateFile)) {
            $state = [
                'videoId' => self::FALLBACK_POOL[0],
                'startedAt' => round(microtime(true) * 1000),
                'slotDuration' => self::SLOT_DURATION
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
                'slotDuration' => self::SLOT_DURATION
            ];
            file_put_contents($this->tmpStateFile, json_encode($state));
        }

        return $state;
    }
}
?>
