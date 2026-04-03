/**
 * Jest Setup File
 * Configures test environment and provides test utilities
 */

// Mock fetch API
global.fetch = jest.fn();

// Mock YouTube API
global.YT = {
  PlayerState: {
    UNSTARTED: -1,
    ENDED: 0,
    PLAYING: 1,
    PAUSED: 2,
    BUFFERING: 3,
    CUED: 5
  },
  Player: jest.fn(function (elementId, options) {
    this.elementId = elementId;
    this.options = options;
    this.videoId = null;
    this.currentTime = 0;
    this.isPlaying = false;

    // Mock methods
    this.loadVideoById = jest.fn((config) => {
      this.videoId = config.videoId;
      this.currentTime = config.startSeconds || 0;
    });

    this.getVideoData = jest.fn(() => {
      return { video_id: this.videoId };
    });

    this.getCurrentTime = jest.fn(() => {
      return this.currentTime;
    });

    this.seekTo = jest.fn((time) => {
      this.currentTime = time;
    });

    this.playVideo = jest.fn(() => {
      this.isPlaying = true;
    });

    this.pauseVideo = jest.fn(() => {
      this.isPlaying = false;
    });

    // Trigger onReady callback after a microtask
    if (this.options && this.options.events && this.options.events.onReady) {
      setTimeout(() => {
        this.options.events.onReady({ target: this });
      }, 0);
    }
  })
};

// Suppress console errors in tests (unless explicitly testing error handling)
const originalError = console.error;
beforeAll(() => {
  console.error = (...args) => {
    if (typeof args[0] === 'string' && args[0].includes('[')) {
      // Allow test framework errors
      originalError.call(console, ...args);
    }
  };
});

afterAll(() => {
  console.error = originalError;
});

// Reset mocks before each test
beforeEach(() => {
  fetch.mockClear();
});

// Utility: Create mock fetch response
global.createMockFetchResponse = (data, ok = true, status = 200) => {
  return Promise.resolve({
    ok,
    status,
    json: () => Promise.resolve(data),
    text: () => Promise.resolve(JSON.stringify(data))
  });
};

// Utility: Create mock stream state
global.createMockStreamState = (videoId = 'dQw4w9WgXcQ', offsetSeconds = 0) => {
  const now = Date.now();
  return {
    videoId,
    startedAt: now - (offsetSeconds * 1000),
    slotDuration: 300
  };
};

// Utility: Advance time (for testing polling intervals)
global.advanceTime = (ms) => {
  jest.advanceTimersByTime(ms);
};

// Utility: Run all pending timers
global.runAllTimers = () => {
  jest.runAllTimers();
};
