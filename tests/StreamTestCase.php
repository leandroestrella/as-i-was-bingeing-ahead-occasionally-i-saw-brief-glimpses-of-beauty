<?php
/**
 * Base test case for Stream-related tests.
 * Provides temp file management and state read/write helpers.
 *
 * Note: StreamPhpTest does not extend this class (it manages its own setup).
 * This base class is available for future test suites that need similar helpers.
 */

namespace Tests;

use PHPUnit\Framework\TestCase;

class StreamTestCase extends TestCase
{
    protected $tmpStateFile;
    protected $tmpPoolCacheFile;
    protected $tmpLockFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpStateFile = TEST_TMP_DIR . '/state.json';
        $this->tmpPoolCacheFile = TEST_TMP_DIR . '/pool-cache.v3.json';
        $this->tmpLockFile = TEST_TMP_DIR . '/state.lock';
    }

    protected function tearDown(): void
    {
        $files = [$this->tmpStateFile, $this->tmpPoolCacheFile, $this->tmpLockFile];
        foreach ($files as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    protected function writeTestState($videoId, $startedAt, $slotDuration)
    {
        $state = [
            'videoId' => $videoId,
            'startedAt' => $startedAt,
            'slotDuration' => $slotDuration
        ];
        file_put_contents($this->tmpStateFile, json_encode($state));
    }

    protected function readTestState()
    {
        if (!file_exists($this->tmpStateFile)) {
            return null;
        }
        return json_decode(file_get_contents($this->tmpStateFile), true);
    }

    protected function writeTestPool($videoIds)
    {
        file_put_contents($this->tmpPoolCacheFile, json_encode($videoIds));
    }
}
?>
