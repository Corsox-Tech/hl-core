// @ts-check
const { test, expect } = require('@playwright/test');

const BASE_URL = 'https://test.academy.housmanlearning.com';
const USERNAME = 'corsox-developer';
const PASSWORD = ')*Zu8iSSdQuOdfWLg^rhUvyR';

test.describe('Feature Tracker Enhancements', () => {

    test.beforeEach(async ({ page }) => {
        // Login to WordPress
        await page.goto(`${BASE_URL}/wp-login.php`);
        await page.fill('#user_login', USERNAME);
        await page.fill('#user_pass', PASSWORD);
        await page.click('#wp-submit');
        await page.waitForURL(/.*(?!wp-login).*/);

        // Navigate to Feature Tracker page
        await page.goto(`${BASE_URL}/feature-tracker/`);
        await page.waitForSelector('.hlft-wrapper', { timeout: 15000 });
    });

    test('Feature Tracker page loads with table', async ({ page }) => {
        await expect(page.locator('.hlft-wrapper')).toBeVisible();
        await expect(page.locator('#hlft-new-ticket-btn')).toBeVisible();
        await expect(page.locator('.hlft-table')).toBeVisible();
    });

    test('New Ticket form has category, department, and context fields', async ({ page }) => {
        // Click New Ticket
        await page.click('#hlft-new-ticket-btn');
        await page.waitForSelector('#hlft-form-modal', { state: 'visible' });

        // Verify category dropdown exists and is required
        const categorySelect = page.locator('#hlft-form-category');
        await expect(categorySelect).toBeVisible();
        await expect(categorySelect).toHaveAttribute('required', '');

        // Verify category has all 6 options + placeholder
        const options = categorySelect.locator('option');
        await expect(options).toHaveCount(7); // 6 categories + 1 placeholder

        // Verify department read-only field exists
        await expect(page.locator('#hlft-form-department')).toBeVisible();
        await expect(page.locator('#hlft-form-department')).toHaveClass(/hlft-dept-readonly/);

        // Verify "Encountered as" dropdown exists
        const contextMode = page.locator('#hlft-form-context-mode');
        await expect(contextMode).toBeVisible();

        // Verify context user wrap is hidden by default
        await expect(page.locator('#hlft-context-user-wrap')).toBeHidden();
    });

    test('Context mode toggle shows/hides user search', async ({ page }) => {
        await page.click('#hlft-new-ticket-btn');
        await page.waitForSelector('#hlft-form-modal', { state: 'visible' });

        // Select "Viewing as another user"
        await page.selectOption('#hlft-form-context-mode', 'view_as');

        // User search wrap should be visible
        await expect(page.locator('#hlft-context-user-wrap')).toBeVisible();
        await expect(page.locator('#hlft-user-search-input')).toBeVisible();

        // Switch back to "Myself"
        await page.selectOption('#hlft-form-context-mode', 'self');

        // User search wrap should be hidden
        await expect(page.locator('#hlft-context-user-wrap')).toBeHidden();
    });

    test('User search autocomplete returns results', async ({ page }) => {
        await page.click('#hlft-new-ticket-btn');
        await page.waitForSelector('#hlft-form-modal', { state: 'visible' });

        // Select "Viewing as another user"
        await page.selectOption('#hlft-form-context-mode', 'view_as');

        // Type a search query (3+ chars)
        await page.fill('#hlft-user-search-input', 'Lau');

        // Wait for search results to appear
        await page.waitForSelector('.hlft-user-search-item', { timeout: 10000 });

        // Verify results are visible
        const results = page.locator('.hlft-user-search-item');
        const count = await results.count();
        expect(count).toBeGreaterThan(0);

        // Click first result — chip should appear
        await results.first().click();
        await expect(page.locator('#hlft-context-user-chip')).toBeVisible();
        await expect(page.locator('#hlft-user-search-input')).toBeHidden();

        // Verify hidden field has a value
        const userId = await page.locator('#hlft-form-context-user-id').inputValue();
        expect(userId).not.toBe('');
    });

    test('Create ticket with category and verify in detail', async ({ page }) => {
        await page.click('#hlft-new-ticket-btn');
        await page.waitForSelector('#hlft-form-modal', { state: 'visible' });

        // Fill required fields
        const timestamp = Date.now();
        const title = `E2E Test Ticket ${timestamp}`;
        await page.fill('#hlft-form-title-input', title);
        await page.selectOption('#hlft-form-category', 'platform_issue');
        await page.selectOption('#hlft-form-type', 'bug');
        await page.selectOption('#hlft-form-priority', 'medium');
        await page.fill('#hlft-form-description', 'This is an automated E2E test ticket.');

        // Submit
        await page.click('#hlft-form-submit');

        // Wait for toast confirmation
        await page.waitForSelector('#hlft-toast', { state: 'visible', timeout: 10000 });

        // Wait for form modal to close
        await page.waitForSelector('#hlft-form-modal', { state: 'hidden', timeout: 5000 });

        // Click the newly created ticket in the table
        await page.waitForTimeout(1000); // Wait for table reload
        const row = page.locator(`#hlft-table-body tr:has-text("${title}")`);
        await expect(row).toBeVisible({ timeout: 5000 });
        await row.click();

        // Wait for detail modal
        await page.waitForSelector('#hlft-detail-content', { state: 'visible', timeout: 10000 });

        // Verify category badge appears in detail
        const categoryBadge = page.locator('.hlft-meta-category');
        await expect(categoryBadge).toBeVisible();
        await expect(categoryBadge).toContainText('Platform Issue');

        // Verify department shows in detail
        const metaRow = page.locator('#hlft-detail-meta');
        const metaText = await metaRow.textContent();
        // Department should be either a real value or "Not assigned"
        expect(metaText).toBeTruthy();
    });

    test('Submission guard prevents view_as without user', async ({ page }) => {
        await page.click('#hlft-new-ticket-btn');
        await page.waitForSelector('#hlft-form-modal', { state: 'visible' });

        // Fill minimum fields
        await page.fill('#hlft-form-title-input', 'Guard test');
        await page.selectOption('#hlft-form-category', 'other');
        await page.selectOption('#hlft-form-type', 'bug');
        await page.fill('#hlft-form-description', 'Testing guard');

        // Select view_as mode WITHOUT selecting a user
        await page.selectOption('#hlft-form-context-mode', 'view_as');

        // Try to submit
        await page.click('#hlft-form-submit');

        // Toast should show error
        await page.waitForSelector('#hlft-toast', { state: 'visible', timeout: 5000 });
        const toastText = await page.locator('#hlft-toast').textContent();
        expect(toastText).toContain('select the user');

        // Modal should still be open (not submitted)
        await expect(page.locator('#hlft-form-modal')).toBeVisible();
    });

    test('Enter key in user search does NOT submit form', async ({ page }) => {
        await page.click('#hlft-new-ticket-btn');
        await page.waitForSelector('#hlft-form-modal', { state: 'visible' });

        // Select view_as
        await page.selectOption('#hlft-form-context-mode', 'view_as');

        // Type in search and press Enter
        await page.fill('#hlft-user-search-input', 'Test');
        await page.press('#hlft-user-search-input', 'Enter');

        // Modal should still be open (Enter didn't submit the form)
        await expect(page.locator('#hlft-form-modal')).toBeVisible();
        // The submit button should NOT be disabled (form wasn't triggered)
        await expect(page.locator('#hlft-form-submit')).not.toBeDisabled();
    });

    test('Chip remove restores search input', async ({ page }) => {
        await page.click('#hlft-new-ticket-btn');
        await page.waitForSelector('#hlft-form-modal', { state: 'visible' });

        // Select view_as and search
        await page.selectOption('#hlft-form-context-mode', 'view_as');
        await page.fill('#hlft-user-search-input', 'Lau');
        await page.waitForSelector('.hlft-user-search-item', { timeout: 10000 });
        await page.locator('.hlft-user-search-item').first().click();

        // Chip visible, search hidden
        await expect(page.locator('#hlft-context-user-chip')).toBeVisible();
        await expect(page.locator('#hlft-user-search-input')).toBeHidden();

        // Click remove button on chip
        await page.click('.hlft-chip-remove');

        // Search restored, chip gone
        await expect(page.locator('#hlft-user-search-input')).toBeVisible();
        await expect(page.locator('#hlft-context-user-chip')).toBeHidden();

        // Hidden field should be cleared
        const userId = await page.locator('#hlft-form-context-user-id').inputValue();
        expect(userId).toBe('');
    });
});
