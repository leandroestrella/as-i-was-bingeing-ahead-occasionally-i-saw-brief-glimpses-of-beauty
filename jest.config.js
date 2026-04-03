/**
 * Jest Configuration
 * JavaScript testing framework for app.js logic
 */

module.exports = {
  testEnvironment: 'jsdom',
  testMatch: ['**/__tests__/**/*.test.js', '**/tests/**/*.test.js'],
  collectCoverageFrom: [
    'public/js/**/*.js',
    '!public/js/vendor/**'
  ],
  coverageThreshold: {
    global: {
      lines: 70,
      functions: 70,
      branches: 60,
      statements: 70
    }
  },
  setupFilesAfterEnv: ['<rootDir>/tests/jest.setup.js'],
  transform: {
    '^.+\\.js$': 'babel-jest'
  },
  verbose: true,
  bail: false,
  testTimeout: 10000
};
