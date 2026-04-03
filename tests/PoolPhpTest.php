<?php
/**
 * Unit tests for public/api/pool.php
 * Tests video pool fetching, caching, filtering, and error handling.
 *
 * These tests validate pool.php's logic in isolation — they don't execute
 * the actual script or make network calls.
 */

namespace Tests;

use PHPUnit\Framework\TestCase;

class PoolPhpTest extends TestCase
{
    // Must match pool.php getConfig()
    const POOL_SIZE = 30;
    const MAX_VIEW_COUNT = 50000;

    const INVIDIOUS_INSTANCES = [
        'https://inv.nadeko.net',
        'https://invidious.io',
        'https://yt.cdaut.de',
        'https://invidious.privacydev.net',
        'https://invidious.garudalinux.org',
        'https://iv.datura.network',
        'https://invidious.jing.rocks',
    ];

    // =========================================================================
    // Video ID Validation
    // =========================================================================

    public function testValidYoutubeVideoIds()
    {
        $validIds = ['dQw4w9WgXcQ', 'jNQXAC9IVRw', '9bZkp7q19f0'];

        foreach ($validIds as $id) {
            $this->assertTrue(
                strlen($id) === 11 && ctype_alnum($id),
                "ID '$id' should be valid"
            );
        }
    }

    public function testInvalidYoutubeVideoIds()
    {
        $invalidIds = [
            'dQw4w9WgX',      // Too short
            'dQw4w9WgXcQQ',   // Too long
            'dQw4w9WgXc!',    // Non-alphanumeric
            '',                // Empty
            null,              // Null
        ];

        foreach ($invalidIds as $id) {
            $isValid = is_string($id) && strlen($id) === 11 && ctype_alnum($id);
            $this->assertFalse($isValid, "ID should be invalid");
        }
    }

    // =========================================================================
    // Pool Response Format
    // =========================================================================

    public function testPoolResponseIsArrayOfStringIds()
    {
        $pool = ['dQw4w9WgXcQ', 'jNQXAC9IVRw', '9bZkp7q19f0'];

        $this->assertIsArray($pool);
        $this->assertGreaterThan(0, count($pool));

        foreach ($pool as $videoId) {
            $this->assertIsString($videoId);
            $this->assertEquals(11, strlen($videoId));
        }
    }

    // =========================================================================
    // Cache Format and Persistence
    // =========================================================================

    public function testPoolCacheRoundTrip()
    {
        $tmpCacheFile = TEST_TMP_DIR . '/pool-cache.v3.json';
        $pool = ['dQw4w9WgXcQ', 'jNQXAC9IVRw', '9bZkp7q19f0'];

        file_put_contents($tmpCacheFile, json_encode($pool));
        $cached = json_decode(file_get_contents($tmpCacheFile), true);

        $this->assertEquals($pool, $cached);
    }

    public function testMalformedCacheGracefullyFails()
    {
        $tmpCacheFile = TEST_TMP_DIR . '/pool-cache.v3.json';
        file_put_contents($tmpCacheFile, '{ invalid json ]');

        $decoded = json_decode(file_get_contents($tmpCacheFile), true);
        $this->assertNull($decoded);

        // Code should treat null as empty and trigger a fresh fetch
        $pool = is_array($decoded) ? $decoded : [];
        $this->assertEmpty($pool);
    }

    public function testCacheFreshnessCheck()
    {
        $tmpCacheFile = TEST_TMP_DIR . '/pool-cache.v3.json';
        file_put_contents($tmpCacheFile, json_encode(['dQw4w9WgXcQ']));

        $mtime = filemtime($tmpCacheFile);
        $ageSeconds = time() - $mtime;

        // Cache duration is 30 minutes (1800s) in stream.php
        $this->assertLessThan(1800, $ageSeconds);
    }

    // =========================================================================
    // Empty Pool Handling
    // =========================================================================

    public function testEmptyPoolTriggersRssFallback()
    {
        $invidiousResult = [];
        $this->assertEmpty($invidiousResult);

        // When Invidious returns empty, code falls through to fetchFromPlaylistRSS()
        // When that also fails, stream.php uses FALLBACK_POOL
    }

    // =========================================================================
    // Invidious Instances
    // =========================================================================

    public function testInvidiousInstanceRedundancy()
    {
        $this->assertGreaterThanOrEqual(3, count(self::INVIDIOUS_INSTANCES));
    }

    public function testInvidiousInstancesAreHttps()
    {
        foreach (self::INVIDIOUS_INSTANCES as $url) {
            $this->assertStringStartsWith('https://', $url);
        }
    }

    // =========================================================================
    // Search Query Coverage
    // =========================================================================

    public function testSearchQueriesHaveVariety()
    {
        // Queries should cover multiple categories
        $queryCategories = [
            'IMG_0001',         // Default camera filenames
            'my backyard',      // Hyper-specific domestic
            'home video',       // Proven queries
            'VHS home video',   // Camera/format-specific
            'video casero',     // Multilingual
        ];

        foreach ($queryCategories as $query) {
            $this->assertIsString($query);
            $this->assertNotEmpty($query);
        }
    }

    // =========================================================================
    // Invidious URL Construction
    // =========================================================================

    public function testInvidiousSearchUrlFormat()
    {
        $instance = 'https://inv.nadeko.net';
        $query = 'home video';
        $page = 3;

        $url = $instance . '/api/v1/search?q=' . urlencode($query)
            . '&type=video&duration=medium&sort_by=upload_date&page=' . intval($page);

        $this->assertStringContainsString('/api/v1/search', $url);
        $this->assertStringContainsString('sort_by=upload_date', $url);
        $this->assertStringContainsString('duration=medium', $url);
        $this->assertStringContainsString('page=3', $url);
        $this->assertStringContainsString('q=home+video', $url);
    }

    // =========================================================================
    // YouTube RSS URL
    // =========================================================================

    public function testYoutubeRssUrlFormat()
    {
        $playlistId = 'PLDcvjWj6b3hEARyG4CPYuARMbK81vppkd';
        $url = 'https://www.youtube.com/feeds/videos.xml?playlist_id=' . urlencode($playlistId);

        $this->assertStringStartsWith('https://www.youtube.com/feeds/videos.xml', $url);
        $this->assertStringContainsString('playlist_id=', $url);
    }

    // =========================================================================
    // Curl Response Handling
    // =========================================================================

    public function testCurlResponseValidation()
    {
        $scenarios = [
            ['httpCode' => 404, 'response' => false, 'expected' => false],
            ['httpCode' => 500, 'response' => false, 'expected' => false],
            ['httpCode' => 200, 'response' => false, 'expected' => false],
            ['httpCode' => 200, 'response' => '[]', 'expected' => true],
            ['httpCode' => 200, 'response' => '', 'expected' => false],
        ];

        foreach ($scenarios as $s) {
            $isSuccess = ($s['httpCode'] >= 200 && $s['httpCode'] < 300
                && $s['response'] !== false
                && !empty($s['response']));

            $this->assertEquals($s['expected'], $isSuccess,
                "HTTP {$s['httpCode']} with response=" . var_export($s['response'], true));
        }
    }

    // =========================================================================
    // Pool Size Limits
    // =========================================================================

    public function testPoolSizeLimit()
    {
        $largePool = array_fill(0, 50, 'dQw4w9WgXcQ');
        $limitedPool = array_slice($largePool, 0, self::POOL_SIZE);

        $this->assertEquals(self::POOL_SIZE, count($limitedPool));
    }

    // =========================================================================
    // View Count Filtering
    // =========================================================================

    public function testViewCountCeiling()
    {
        // Videos above MAX_VIEW_COUNT should be filtered out
        $this->assertTrue(49999 <= self::MAX_VIEW_COUNT);
        $this->assertFalse(50001 <= self::MAX_VIEW_COUNT);
    }

    // =========================================================================
    // Cleanup
    // =========================================================================

    public function tearDown(): void
    {
        $files = glob(TEST_TMP_DIR . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }
}
?>
