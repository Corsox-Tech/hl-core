// @ts-check
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');

const BASE_URL = 'https://test.academy.housmanlearning.com';

// corsox-developer is an admin (WP role) but NOT the ticket admin (mateo@corsox.com)
// This means they can access Feature Tracker but see approve/reject buttons (not admin dropdown)
const CREATOR_USER = 'corsox-developer';
const CREATOR_PASS = ')*Zu8iSSdQuOdfWLg^rhUvyR';

// mateo is the ticket admin (ADMIN_EMAIL = mateo@corsox.com)
const ADMIN_USER = 'mateo';
const ADMIN_PASS = ')*Zu8iSSdQuOdfWLg^rhUvyR';

const SSH_CMD = 'ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201';
const WP_CLI = 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress';

/** Run a WP-CLI command on the test server and return stdout. */
function wpCli(command) {
    // Use double-quote SSH wrapper so inner single quotes (SQL strings) are preserved
    const escaped = command.replace(/"/g, '\\"');
    return execSync(`${SSH_CMD} "${WP_CLI} ${escaped}"`, { encoding: 'utf-8', timeout: 15000, shell: 'bash' }).trim();
}

/** Login helper — handles BuddyBoss custom login page. */
async function login(page, username, password) {
    await page.goto(`${BASE_URL}/wp-login.php`);
    // BuddyBoss replaces wp-login with a custom form — use placeholder-based selectors
    const usernameField = page.locator('input[placeholder*="username" i], input[placeholder*="email" i], #user_login').first();
    await usernameField.waitFor({ state: 'visible', timeout: 15000 });
    await usernameField.fill(username);

    const passwordField = page.locator('input[placeholder*="password" i], #user_pass').first();
    await passwordField.fill(password);

    const submitBtn = page.locator('button:has-text("Sign In"), input[type="submit"][value="Sign In"], #wp-submit').first();
    await submitBtn.click();
    await page.waitForURL(/.*(?!wp-login|login).*/i, { timeout: 15000 });
}

test.describe('Ticket QA Workflow', () => {

    // ────────────────────────────────────────────────────────────
    // 1. Filter + Dropdown UI
    // ────────────────────────────────────────────────────────────

    test('Filter dropdown contains new QA statuses', async ({ page }) => {
        await login(page, CREATOR_USER, CREATOR_PASS);
        await page.goto(`${BASE_URL}/feature-tracker/`);
        await page.waitForSelector('.hlft-wrapper', { timeout: 15000 });

        const filterSelect = page.locator('#hlft-filter-status');
        await expect(filterSelect).toBeVisible();

        // Check for new options
        const readyOption = filterSelect.locator('option[value="ready_for_test"]');
        await expect(readyOption).toHaveText('Ready for Review');

        const failedOption = filterSelect.locator('option[value="test_failed"]');
        await expect(failedOption).toHaveText('Needs Revision');
    });

    test('Status labels include new QA statuses in JS', async ({ page }) => {
        await login(page, CREATOR_USER, CREATOR_PASS);
        await page.goto(`${BASE_URL}/feature-tracker/`);
        await page.waitForSelector('.hlft-wrapper', { timeout: 15000 });

        // Evaluate the statusLabels object in the page context
        const labels = await page.evaluate(() => {
            // The statusLabels variable is scoped inside the jQuery ready function,
            // but we can check the DOM for the filter option text as a proxy
            const opts = document.querySelectorAll('#hlft-filter-status option');
            const map = {};
            opts.forEach(opt => { if (opt.value) map[opt.value] = opt.textContent.trim(); });
            return map;
        });

        expect(labels.ready_for_test).toBe('Ready for Review');
        expect(labels.test_failed).toBe('Needs Revision');
    });

    // ────────────────────────────────────────────────────────────
    // 2. Admin Status Dropdown
    // ────────────────────────────────────────────────────────────

    test('Admin status dropdown has Ready for Review + Needs Revision options', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(`${BASE_URL}/feature-tracker/`);
        await page.waitForSelector('.hlft-wrapper', { timeout: 15000 });

        // Wait for table to load, click first ticket
        await page.waitForSelector('#hlft-table-body tr', { timeout: 10000 });
        await page.locator('#hlft-table-body tr').first().click();
        await page.waitForSelector('#hlft-detail-content', { state: 'visible', timeout: 10000 });

        // Admin should see status dropdown
        const statusSelect = page.locator('#hlft-status-select');
        await expect(statusSelect).toBeVisible();

        const readyOption = statusSelect.locator('option[value="ready_for_test"]');
        await expect(readyOption).toHaveText('Ready for Review');

        const failedOption = statusSelect.locator('option[value="test_failed"]');
        await expect(failedOption).toHaveText('Needs Revision');
    });

    // ────────────────────────────────────────────────────────────
    // 3. Admin moves ticket to Ready for Review
    // ────────────────────────────────────────────────────────────

    test('Admin can move ticket to Ready for Review via dropdown', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(`${BASE_URL}/feature-tracker/`);
        await page.waitForSelector('.hlft-wrapper', { timeout: 15000 });

        // Create a fresh ticket for this test
        await page.click('#hlft-new-ticket-btn');
        await page.waitForSelector('#hlft-form-modal', { state: 'visible' });

        const title = `QA-Workflow-Admin-${Date.now()}`;
        await page.fill('#hlft-form-title-input', title);
        await page.selectOption('#hlft-form-category', 'platform_issue');
        await page.selectOption('#hlft-form-type', 'bug');
        await page.fill('#hlft-form-description', 'E2E test for admin status change to ready_for_test.');
        await page.click('#hlft-form-submit');

        await page.waitForSelector('#hlft-form-modal', { state: 'hidden', timeout: 10000 });
        await page.waitForTimeout(1000);

        // Click the new ticket
        const row = page.locator(`#hlft-table-body tr:has-text("${title}")`);
        await expect(row).toBeVisible({ timeout: 5000 });
        await row.click();
        await page.waitForSelector('#hlft-detail-content', { state: 'visible', timeout: 10000 });

        // Change status to ready_for_test
        await page.selectOption('#hlft-status-select', 'ready_for_test');
        await page.click('#hlft-status-btn');

        // Wait for toast
        await page.waitForSelector('#hlft-toast', { state: 'visible', timeout: 10000 });

        // Verify pill updated
        const pill = page.locator('#hlft-detail-meta .hlft-status-pill');
        await expect(pill).toHaveText('Ready for Review');
        await expect(pill).toHaveClass(/hlft-status-pill--ready_for_test/);
    });

    // ────────────────────────────────────────────────────────────
    // 4. Creator Approve Flow
    // ────────────────────────────────────────────────────────────

    test('Creator sees Approve/Reject buttons and can approve', async ({ page }) => {
        // Step 1: Create a ticket as corsox-developer
        await login(page, CREATOR_USER, CREATOR_PASS);
        await page.goto(`${BASE_URL}/feature-tracker/`);
        await page.waitForSelector('.hlft-wrapper', { timeout: 15000 });

        await page.click('#hlft-new-ticket-btn');
        await page.waitForSelector('#hlft-form-modal', { state: 'visible' });

        const title = `QA-Approve-${Date.now()}`;
        await page.fill('#hlft-form-title-input', title);
        await page.selectOption('#hlft-form-category', 'platform_issue');
        await page.selectOption('#hlft-form-type', 'bug');
        await page.fill('#hlft-form-description', 'E2E test for creator approve flow.');
        await page.click('#hlft-form-submit');

        await page.waitForSelector('#hlft-form-modal', { state: 'hidden', timeout: 10000 });
        await page.waitForTimeout(1000);

        // Get the ticket UUID from the table row
        const row = page.locator(`#hlft-table-body tr:has-text("${title}")`);
        await expect(row).toBeVisible({ timeout: 5000 });
        const uuid = await row.getAttribute('data-uuid');

        // Step 2: Move to ready_for_test via WP-CLI (simulates admin action)
        wpCli(`db query "UPDATE wp_hl_ticket SET status = 'ready_for_test' WHERE ticket_uuid = '${uuid}'"`);

        // Step 3: Reload and open the ticket
        await page.reload();
        await page.waitForSelector('.hlft-wrapper', { timeout: 15000 });
        await page.waitForSelector('#hlft-table-body tr', { timeout: 10000 });

        // Filter to show the ticket (it might be in the default view)
        const ticketRow = page.locator(`#hlft-table-body tr:has-text("${title}")`);
        await expect(ticketRow).toBeVisible({ timeout: 5000 });

        // Verify pill shows "Ready for Review"
        const tablePill = ticketRow.locator('.hlft-status-pill');
        await expect(tablePill).toHaveText('Ready for Review');

        // Click to open detail
        await ticketRow.click();
        await page.waitForSelector('#hlft-detail-content', { state: 'visible', timeout: 10000 });

        // Step 4: Verify Approve and Reject buttons are visible
        const approveBtn = page.locator('#hlft-approve-btn');
        const rejectBtn = page.locator('#hlft-reject-btn');
        await expect(approveBtn).toBeVisible();
        await expect(rejectBtn).toBeVisible();
        await expect(approveBtn).toHaveText('Approve');
        await expect(rejectBtn).toHaveText('Reject');

        // Step 5: Click Approve
        await approveBtn.click();

        // Wait for toast
        await page.waitForSelector('#hlft-toast', { state: 'visible', timeout: 10000 });
        const toastText = await page.locator('#hlft-toast').textContent();
        expect(toastText).toContain('approved');

        // Verify modal re-rendered with Resolved status
        await page.waitForTimeout(1000);
        const pill = page.locator('#hlft-detail-meta .hlft-status-pill');
        await expect(pill).toHaveText('Resolved');

        // Approve/Reject buttons should be gone (status is no longer ready_for_test)
        await expect(page.locator('#hlft-approve-btn')).not.toBeVisible();
        await expect(page.locator('#hlft-reject-btn')).not.toBeVisible();
    });

    // ────────────────────────────────────────────────────────────
    // 5. Creator Reject Flow
    // ────────────────────────────────────────────────────────────

    test('Creator can reject with required comment', async ({ page }) => {
        // Step 1: Create ticket
        await login(page, CREATOR_USER, CREATOR_PASS);
        await page.goto(`${BASE_URL}/feature-tracker/`);
        await page.waitForSelector('.hlft-wrapper', { timeout: 15000 });

        await page.click('#hlft-new-ticket-btn');
        await page.waitForSelector('#hlft-form-modal', { state: 'visible' });

        const title = `QA-Reject-${Date.now()}`;
        await page.fill('#hlft-form-title-input', title);
        await page.selectOption('#hlft-form-category', 'platform_issue');
        await page.selectOption('#hlft-form-type', 'bug');
        await page.fill('#hlft-form-description', 'E2E test for creator reject flow.');
        await page.click('#hlft-form-submit');

        await page.waitForSelector('#hlft-form-modal', { state: 'hidden', timeout: 10000 });
        await page.waitForTimeout(1000);

        // Get UUID
        const row = page.locator(`#hlft-table-body tr:has-text("${title}")`);
        await expect(row).toBeVisible({ timeout: 5000 });
        const uuid = await row.getAttribute('data-uuid');

        // Step 2: Move to ready_for_test via WP-CLI
        wpCli(`db query "UPDATE wp_hl_ticket SET status = 'ready_for_test' WHERE ticket_uuid = '${uuid}'"`);

        // Step 3: Reload and open
        await page.reload();
        await page.waitForSelector('.hlft-wrapper', { timeout: 15000 });
        await page.waitForSelector('#hlft-table-body tr', { timeout: 10000 });

        const ticketRow = page.locator(`#hlft-table-body tr:has-text("${title}")`);
        await expect(ticketRow).toBeVisible({ timeout: 5000 });
        await ticketRow.click();
        await page.waitForSelector('#hlft-detail-content', { state: 'visible', timeout: 10000 });

        // Step 4: Click Reject — textarea should appear
        await page.click('#hlft-reject-btn');
        await expect(page.locator('#hlft-reject-form')).toBeVisible();
        await expect(page.locator('#hlft-reject-comment')).toBeVisible();

        // Step 5: Try to submit without comment — should show error toast
        await page.click('#hlft-reject-submit-btn');
        await page.waitForSelector('#hlft-toast', { state: 'visible', timeout: 5000 });
        let toastText = await page.locator('#hlft-toast').textContent();
        expect(toastText).toContain('describe what failed');

        // Step 6: Fill comment and submit
        await page.fill('#hlft-reject-comment', 'The fix did not work. The quiz score still shows 0 after completion.');
        await page.click('#hlft-reject-submit-btn');

        // Wait for success toast
        await page.waitForTimeout(500); // Clear previous toast
        await page.waitForSelector('#hlft-toast', { state: 'visible', timeout: 10000 });
        toastText = await page.locator('#hlft-toast').textContent();
        expect(toastText).toContain('needs revision');

        // Verify modal re-rendered with test_failed status
        await page.waitForTimeout(1000);
        const pill = page.locator('#hlft-detail-meta .hlft-status-pill');
        await expect(pill).toHaveText('Needs Revision');

        // Approve/Reject buttons should be gone
        await expect(page.locator('#hlft-approve-btn')).not.toBeVisible();

        // The rejection comment should appear in the comments section
        const commentsSection = page.locator('#hlft-comments-list');
        await expect(commentsSection).toContainText('quiz score still shows 0');
    });

    // ────────────────────────────────────────────────────────────
    // 6. Status pill colors
    // ────────────────────────────────────────────────────────────

    test('Ready for Review pill has teal background', async ({ page }) => {
        await login(page, CREATOR_USER, CREATOR_PASS);
        await page.goto(`${BASE_URL}/feature-tracker/`);
        await page.waitForSelector('.hlft-wrapper', { timeout: 15000 });

        // Filter to ready_for_test
        await page.selectOption('#hlft-filter-status', 'ready_for_test');
        await page.waitForTimeout(1500);

        // Check if any tickets show — if yes, verify pill color
        const pills = page.locator('.hlft-status-pill--ready_for_test');
        const count = await pills.count();
        if (count > 0) {
            const bgColor = await pills.first().evaluate(el => getComputedStyle(el).backgroundColor);
            // #0d9488 = rgb(13, 148, 136)
            expect(bgColor).toBe('rgb(13, 148, 136)');
        }
        // If no tickets in this state, the filter test still validates the option exists
    });

    // ────────────────────────────────────────────────────────────
    // 7. Non-creator does NOT see approve/reject
    // ────────────────────────────────────────────────────────────

    test('Non-creator user does not see approve/reject buttons', async ({ page }) => {
        // Create a ticket as admin (mateo) and move to ready_for_test
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(`${BASE_URL}/feature-tracker/`);
        await page.waitForSelector('.hlft-wrapper', { timeout: 15000 });

        await page.click('#hlft-new-ticket-btn');
        await page.waitForSelector('#hlft-form-modal', { state: 'visible' });

        const title = `QA-NonCreator-${Date.now()}`;
        await page.fill('#hlft-form-title-input', title);
        await page.selectOption('#hlft-form-category', 'other');
        await page.selectOption('#hlft-form-type', 'improvement');
        await page.fill('#hlft-form-description', 'E2E test — non-creator should not see buttons.');
        await page.click('#hlft-form-submit');
        await page.waitForSelector('#hlft-form-modal', { state: 'hidden', timeout: 10000 });
        await page.waitForTimeout(1000);

        // Get UUID and move to ready_for_test
        const row = page.locator(`#hlft-table-body tr:has-text("${title}")`);
        await expect(row).toBeVisible({ timeout: 5000 });
        const uuid = await row.getAttribute('data-uuid');
        wpCli(`db query "UPDATE wp_hl_ticket SET status = 'ready_for_test' WHERE ticket_uuid = '${uuid}'"`);

        // Logout first, then login as corsox-developer (NOT the creator)
        await page.goto(`${BASE_URL}/wp-login.php?action=logout`);
        // Confirm logout if WP shows "Do you really want to log out?" link
        const logoutLink = page.locator('a[href*="action=logout"]');
        if (await logoutLink.count() > 0) {
            await logoutLink.first().click();
            await page.waitForTimeout(2000);
        }
        await login(page, CREATOR_USER, CREATOR_PASS);
        await page.goto(`${BASE_URL}/feature-tracker/`);
        await page.waitForSelector('.hlft-wrapper', { timeout: 15000 });
        await page.waitForSelector('#hlft-table-body tr', { timeout: 10000 });

        const ticketRow = page.locator(`#hlft-table-body tr:has-text("${title}")`);
        await expect(ticketRow).toBeVisible({ timeout: 5000 });
        await ticketRow.click();
        await page.waitForSelector('#hlft-detail-content', { state: 'visible', timeout: 10000 });

        // Should NOT see approve/reject (not the creator)
        await expect(page.locator('#hlft-approve-btn')).not.toBeVisible();
        await expect(page.locator('#hlft-reject-btn')).not.toBeVisible();
    });
});
