// @ts-check
const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    testDir: './tests/e2e',
    timeout: 60000,
    expect: { timeout: 10000 },
    use: {
        baseURL: 'https://test.academy.housmanlearning.com',
        ignoreHTTPSErrors: true,
        screenshot: 'only-on-failure',
        trace: 'on-first-retry',
    },
    retries: 1,
});
