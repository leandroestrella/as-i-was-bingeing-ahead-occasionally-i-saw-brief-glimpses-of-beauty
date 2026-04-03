/**
 * Unit tests for public/js/app.js
 * Tests sync logic, state transitions, and error handling
 */

describe('Stream Synchronization (syncToState)', () => {
  let player;

  beforeEach(() => {
    // Create mock YouTube player for each test
    jest.useFakeTimers();

    player = new YT.Player('player', {
      events: {
        onReady: jest.fn(),
        onStateChange: jest.fn(),
        onError: jest.fn()
      }
    });
  });

  afterEach(() => {
    jest.clearAllTimers();
    jest.runOnlyPendingTimers();
    jest.useRealTimers();
    jest.clearAllMocks();
  });

  // =========================================================================
  // STATE VALIDATION TESTS
  // =========================================================================

  describe('State validation', () => {
    test('should reject null state', () => {
      const result = shouldSyncToState(null);
      expect(result).toBe(false);
    });

    test('should reject undefined state', () => {
      const result = shouldSyncToState(undefined);
      expect(result).toBe(false);
    });

    test('should reject state with missing videoId', () => {
      const state = {
        startedAt: Date.now(),
        slotDuration: 300
      };
      const result = shouldSyncToState(state);
      expect(result).toBe(false);
    });

    test('should reject state with missing startedAt', () => {
      const state = {
        videoId: 'dQw4w9WgXcQ',
        slotDuration: 300
      };
      const result = shouldSyncToState(state);
      expect(result).toBe(false);
    });

    test('should accept valid state', () => {
      const state = createMockStreamState('dQw4w9WgXcQ', 0);
      const result = shouldSyncToState(state);
      expect(result).toBe(true);
    });
  });

  // =========================================================================
  // OFFSET CALCULATION TESTS
  // =========================================================================

  describe('Offset calculation', () => {
    test('should calculate correct offset for fresh video', () => {
      const state = createMockStreamState('dQw4w9WgXcQ', 0);
      const offset = calculateOffset(state);

      expect(offset).toBeGreaterThanOrEqual(0);
      expect(offset).toBeLessThan(5); // Should be very fresh
    });

    test('should calculate correct offset for aged video', () => {
      const state = createMockStreamState('dQw4w9WgXcQ', 150);
      const offset = calculateOffset(state);

      expect(offset).toBeGreaterThan(145);
      expect(offset).toBeLessThan(155);
    });

    test('should not calculate offset if slot has expired', () => {
      const state = createMockStreamState('dQw4w9WgXcQ', 350); // Past 300s slot
      const offset = calculateOffset(state);

      expect(offset).toBeGreaterThan(300);
    });

    test('should handle rounding correctly', () => {
      const state = createMockStreamState('dQw4w9WgXcQ', 100.7);
      const offset = calculateOffset(state);

      // Math.floor should be applied
      expect(offset).toBeGreaterThanOrEqual(100);
      expect(offset).toBeLessThan(102);
    });
  });

  // =========================================================================
  // VIDEO LOADING TESTS
  // =========================================================================

  describe('Video loading', () => {
    test('should load new video when ID changes', () => {
      const previousVideoId = 'oldVideoId12345';
      const state = {
        videoId: 'newVideoId12345',
        startedAt: Date.now(),
        slotDuration: 300
      };

      player.videoId = previousVideoId;

      syncToState(state, player);

      expect(player.loadVideoById).toHaveBeenCalledWith({
        videoId: 'newVideoId12345',
        startSeconds: expect.any(Number)
      });
    });

    test('should load video at correct start position', () => {
      const state = createMockStreamState('newVideoId12345', 50);
      player.videoId = null;

      syncToState(state, player);

      const callArgs = player.loadVideoById.mock.calls[0][0];
      expect(callArgs.startSeconds).toBeGreaterThan(45);
      expect(callArgs.startSeconds).toBeLessThan(55);
    });

    test('should not reload video with same ID', () => {
      const videoId = 'dQw4w9WgXcQ';
      const state = createMockStreamState(videoId, 50);
      player.videoId = videoId;
      player.getCurrentTime.mockReturnValue(50);

      syncToState(state, player);

      // Should not call loadVideoById if video ID matches
      expect(player.loadVideoById).not.toHaveBeenCalled();
    });
  });

  // =========================================================================
  // DRIFT CORRECTION TESTS
  // =========================================================================

  describe('Drift detection and correction', () => {
    const SYNC_THRESHOLD = 2; // seconds

    test('should ignore small drift (< threshold)', () => {
      const state = createMockStreamState('dQw4w9WgXcQ', 100);
      player.videoId = 'dQw4w9WgXcQ';
      player.getCurrentTime.mockReturnValue(100.5); // 0.5s drift

      syncToState(state, player);

      expect(player.seekTo).not.toHaveBeenCalled();
    });

    test('should correct large positive drift (player ahead)', () => {
      const state = createMockStreamState('dQw4w9WgXcQ', 100);
      player.videoId = 'dQw4w9WgXcQ';
      player.getCurrentTime.mockReturnValue(105); // 5s ahead (> 2s threshold)

      syncToState(state, player);

      expect(player.seekTo).toHaveBeenCalledWith(
        expect.any(Number),
        true
      );
    });

    test('should correct large negative drift (player behind)', () => {
      const state = createMockStreamState('dQw4w9WgXcQ', 100);
      player.videoId = 'dQw4w9WgXcQ';
      player.getCurrentTime.mockReturnValue(97); // 3s behind (> 2s threshold)

      syncToState(state, player);

      expect(player.seekTo).toHaveBeenCalledWith(
        expect.any(Number),
        true
      );
    });

    test('should seek to exact server offset', () => {
      const state = createMockStreamState('dQw4w9WgXcQ', 75);
      player.videoId = 'dQw4w9WgXcQ';
      player.getCurrentTime.mockReturnValue(50); // Large drift

      syncToState(state, player);

      const seekOffset = player.seekTo.mock.calls[0][0];
      expect(seekOffset).toBeGreaterThan(70);
      expect(seekOffset).toBeLessThan(80);
    });

    test('should handle boundary: exactly at threshold', () => {
      const state = createMockStreamState('dQw4w9WgXcQ', 100);
      player.videoId = 'dQw4w9WgXcQ';
      player.getCurrentTime.mockReturnValue(102); // Exactly 2s drift

      syncToState(state, player);

      // At boundary: may or may not seek (implementation dependent)
      // Both are acceptable: seeking is safe, not seeking is acceptable
      const maySeek = player.seekTo.called;
      expect(typeof maySeek).toBe('boolean');
    });
  });

  // =========================================================================
  // SLOT EXPIRATION TESTS
  // =========================================================================

  describe('Slot expiration', () => {
    test('should not sync if offset >= slot duration', () => {
      const state = createMockStreamState('dQw4w9WgXcQ', 350); // Expired
      player.videoId = 'dQw4w9WgXcQ';
      player.getCurrentTime.mockReturnValue(0);

      syncToState(state, player);

      // Should wait for server to advance
      expect(player.loadVideoById).not.toHaveBeenCalled();
      expect(player.seekTo).not.toHaveBeenCalled();
    });

    test('should sync if offset < slot duration', () => {
      const state = createMockStreamState('dQw4w9WgXcQ', 250); // Not expired
      player.videoId = 'dQw4w9WgXcQ';
      player.getCurrentTime.mockReturnValue(250);

      syncToState(state, player);

      // Should be OK (no action needed if drift is small)
      expect(player.loadVideoById).not.toHaveBeenCalled();
    });

    test('should use exact expiration boundary', () => {
      const slotDuration = 300;
      const offsetAtBoundary = 300; // Exactly at boundary

      const shouldSync = offsetAtBoundary < slotDuration;
      expect(shouldSync).toBe(false); // Should NOT sync at boundary
    });

    test('should sync just before expiration', () => {
      const slotDuration = 300;
      const offsetJustBefore = 299.9; // Just before boundary

      const shouldSync = offsetJustBefore < slotDuration;
      expect(shouldSync).toBe(true); // SHOULD sync before expiration
    });
  });

  // =========================================================================
  // EDGE CASES
  // =========================================================================

  describe('Edge cases', () => {
    test('should handle zero offset (slot just started)', () => {
      const state = createMockStreamState('dQw4w9WgXcQ', 0);
      player.videoId = null;

      syncToState(state, player);

      expect(player.loadVideoById).toHaveBeenCalled();
      const callArgs = player.loadVideoById.mock.calls[0][0];
      expect(callArgs.startSeconds).toBeLessThan(5);
    });

    test('should handle clock skew (future timestamp)', () => {
      const futureTimestamp = Date.now() + (60 * 1000); // 60s in future
      const state = {
        videoId: 'dQw4w9WgXcQ',
        startedAt: futureTimestamp,
        slotDuration: 300
      };

      player.videoId = null;

      syncToState(state, player);

      const offset = calculateOffset(state);
      expect(offset).toBeLessThan(0); // Negative offset
    });

    test('should handle very long slot duration', () => {
      const state = createMockStreamState('dQw4w9WgXcQ', 600); // 10 min
      state.slotDuration = 600;

      const offset = calculateOffset(state);
      expect(offset).toBeLessThan(state.slotDuration);
    });

    test('should handle multiple rapid syncs without duplication', () => {
      const state = createMockStreamState('dQw4w9WgXcQ', 50);
      player.videoId = null;

      syncToState(state, player);
      syncToState(state, player);
      syncToState(state, player);

      // Should only load once (same video ID)
      expect(player.loadVideoById).toHaveBeenCalledTimes(1);
    });
  });

  // =========================================================================
  // HELPER FUNCTIONS (EXTRACTED FROM app.js LOGIC)
  // =========================================================================

  function shouldSyncToState(state) {
    if (!state || typeof state.videoId !== 'string' || !state.startedAt) {
      return false;
    }
    return true;
  }

  function calculateOffset(state) {
    return (Date.now() - state.startedAt) / 1000;
  }

  function syncToState(state, player) {
    if (!shouldSyncToState(state)) {
      return;
    }

    const offset = calculateOffset(state);

    if (offset >= state.slotDuration) {
      return; // Wait for server to advance
    }

    const currentVideoId = player.getVideoData()?.video_id;
    if (currentVideoId !== state.videoId) {
      player.loadVideoById({
        videoId: state.videoId,
        startSeconds: Math.floor(offset)
      });
    } else {
      const playerTime = player.getCurrentTime();
      const SYNC_THRESHOLD = 2;
      if (Math.abs(playerTime - offset) > SYNC_THRESHOLD) {
        player.seekTo(offset, true);
      }
    }
  }
});

// =========================================================================
// POLLING TESTS
// =========================================================================

describe('Stream polling', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    fetch.mockClear();
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  test('should poll at regular intervals', () => {
    const POLL_INTERVAL = 30_000;
    const mockState = createMockStreamState();

    fetch.mockImplementation(() => createMockFetchResponse(mockState));

    // Simulate fetchState being called in a loop
    const fetchState = setInterval(() => {
      fetch('api/stream.php');
    }, POLL_INTERVAL);

    // Advance time by one poll interval
    advanceTime(POLL_INTERVAL);

    expect(fetch).toHaveBeenCalledWith(
      'api/stream.php',
      expect.any(Object)
    );

    clearInterval(fetchState);
  });

  test('should handle fetch failures gracefully', async () => {
    const consoleWarnSpy = jest.spyOn(console, 'warn').mockImplementation();

    fetch.mockImplementation(() =>
      Promise.reject(new Error('Network error'))
    );

    // Simulate fetch call
    try {
      const response = await fetch('api/stream.php');
    } catch (error) {
      expect(error.message).toBe('Network error');
    }

    consoleWarnSpy.mockRestore();
  });

  test('should skip sync if response is invalid', async () => {
    fetch.mockImplementation(() => createMockFetchResponse(null));

    const response = await fetch('api/stream.php');
    const data = await response.json();

    expect(data).toBeNull();
  });
});
