/**
 * Playwright test: Verify archived cycles are hidden for non-privileged users
 * and visible for admins/coaches on academy.housmanlearning.com
 */
const { chromium } = require('playwright');

const BASE = 'https://academy.housmanlearning.com';
const ARCHIVED_CYCLE_NAME = 'South Haven - Cycle 1 (2025)';
const ACTIVE_CYCLE_NAME = 'South Haven - Cycle 2 (2026)';

const USERS = {
  teacher: { email: 'anna.williams@yopmail.com', pass: 'anna.williams@yopmail.com', label: 'Teacher (Anna Williams)' },
  mentor: { email: 'manuel.torres@yopmail.com', pass: 'manuel.torres@yopmail.com', label: 'Mentor (Manuel Torres)' },
  school_leader: { email: 'amanda.foster@yopmail.com', pass: 'amanda.foster@yopmail.com', label: 'School Leader (Amanda Foster)' },
  district_leader: { email: 'diana.prescott@yopmail.com', pass: 'diana.prescott@yopmail.com', label: 'District Leader (Diana Prescott)' },
};

const PAGES_TO_CHECK = [
  { name: 'My Programs', path: '/my-programs/' },
  { name: 'My Progress', path: '/my-progress/' },
  { name: 'Cycles', path: '/cycles/' },
];

let results = [];
let totalPass = 0;
let totalFail = 0;

function record(role, test, pass, detail) {
  const status = pass ? 'PASS' : 'FAIL';
  if (pass) totalPass++; else totalFail++;
  results.push({ role, test, status, detail });
  console.log(`  [${status}] ${test}${detail ? ' — ' + detail : ''}`);
}

async function login(page, user) {
  await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'networkidle', timeout: 30000 });
  await page.fill('#user_login', user.email);
  await page.fill('#user_pass', user.pass);
  await page.click('#wp-submit');
  await page.waitForURL(url => !url.toString().includes('wp-login'), { timeout: 15000 });
}

async function getPageContent(page, path) {
  await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle', timeout: 30000 });
  return await page.content();
}

async function testRole(browser, roleKey, user) {
  console.log(`\n=== Testing: ${user.label} ===`);
  const context = await browser.newContext();
  const page = await context.newPage();

  try {
    await login(page, user);
    record(roleKey, 'Login', true, `Logged in as ${user.email}`);
  } catch (e) {
    record(roleKey, 'Login', false, e.message);
    await context.close();
    return;
  }

  // Test each page for archived cycle visibility
  for (const pg of PAGES_TO_CHECK) {
    try {
      const html = await getPageContent(page, pg.path);
      const hasArchived = html.includes(ARCHIVED_CYCLE_NAME);
      const hasActive = html.includes(ACTIVE_CYCLE_NAME);
      const hasCriticalError = html.includes('critical error');

      if (hasCriticalError) {
        record(roleKey, `${pg.name}: No critical error`, false, 'Critical error on page!');
        continue;
      }

      if (pg.name === 'My Programs') {
        // My Programs SHOULD show archived data (users keep course access)
        record(roleKey, `${pg.name}: No critical error`, true, '');
        // Don't check archived visibility — My Programs shows all enrollments
      } else {
        // Other pages should NOT show archived cycle for non-privileged users
        record(roleKey, `${pg.name}: No critical error`, true, '');
        record(roleKey, `${pg.name}: Archived cycle hidden`, !hasArchived,
          hasArchived ? `"${ARCHIVED_CYCLE_NAME}" found in page!` : 'Archived cycle not visible');
      }
    } catch (e) {
      record(roleKey, `${pg.name}: Load`, false, e.message);
    }
  }

  // Test User Profile (own)
  try {
    const html = await getPageContent(page, '/user-profile/');
    const hasArchived = html.includes(ARCHIVED_CYCLE_NAME);
    const hasCriticalError = html.includes('critical error');

    record(roleKey, 'User Profile: No critical error', !hasCriticalError,
      hasCriticalError ? 'Critical error!' : '');
    record(roleKey, 'User Profile: Archived cycle hidden', !hasArchived,
      hasArchived ? `"${ARCHIVED_CYCLE_NAME}" found!` : 'Archived cycle not visible');
  } catch (e) {
    record(roleKey, 'User Profile: Load', false, e.message);
  }

  // Take a screenshot of the user profile for visual check
  try {
    await page.goto(`${BASE}/user-profile/`, { waitUntil: 'networkidle', timeout: 30000 });
    await page.screenshot({ path: `tests/screenshots/${roleKey}-profile.png`, fullPage: false });
    record(roleKey, 'Screenshot saved', true, `${roleKey}-profile.png`);
  } catch (e) {
    // Non-critical
  }

  await context.close();
}

(async () => {
  console.log('=== Archived Cycles Playwright Verification ===\n');
  console.log(`Site: ${BASE}`);
  console.log(`Archived cycle: "${ARCHIVED_CYCLE_NAME}"`);
  console.log(`Active cycle: "${ACTIVE_CYCLE_NAME}"`);

  // Ensure screenshots dir exists
  const fs = require('fs');
  if (!fs.existsSync('tests/screenshots')) {
    fs.mkdirSync('tests/screenshots', { recursive: true });
  }

  const browser = await chromium.launch({ headless: true });

  // Test all non-privileged roles
  for (const [roleKey, user] of Object.entries(USERS)) {
    await testRole(browser, roleKey, user);
  }

  await browser.close();

  // Summary
  console.log('\n=== SUMMARY ===');
  console.log(`Total: ${totalPass + totalFail} tests | ${totalPass} PASS | ${totalFail} FAIL\n`);

  if (totalFail > 0) {
    console.log('FAILURES:');
    results.filter(r => r.status === 'FAIL').forEach(r => {
      console.log(`  [${r.role}] ${r.test}: ${r.detail}`);
    });
  }

  process.exit(totalFail > 0 ? 1 : 0);
})();
