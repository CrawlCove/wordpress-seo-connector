// Captures screenshot-1.png from the seeded harness site (see screenshot-setup.sh).
// PLAYWRIGHT=/path/to/node_modules/playwright if it is not installed here.
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  await p.goto('http://127.0.0.1:8987/wp-login.php');
  await p.fill('#user_login', 'admin'); await p.fill('#user_pass', 'admin');
  await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
  await p.goto('http://127.0.0.1:8987/wp-admin/tools.php?page=crawl-cove-connector');
  await p.screenshot({ path: process.argv[2], fullPage: true });
  console.log(await p.locator('#wpadminbar').innerText());
  await b.close();
})();
