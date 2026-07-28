import { chromium } from 'file:///C:/Users/meark/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs';

const browser = await chromium.launch({
  executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  headless: true,
});

const results = {};
try {
  const desktop = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  await desktop.goto(`https://mztech-it.de/?browser_test=${Date.now()}`, {
    waitUntil: 'networkidle',
  });
  results.desktop = await desktop.evaluate(() => {
    const section = document.querySelector('#kunden-firmenzugang');
    const links = [...document.querySelectorAll('a')];
    const hasLabel = (label) => links.some((link) => link.textContent.includes(label));
    return {
      https: location.protocol === 'https:',
      sectionPresent: Boolean(section),
      portalNav: Boolean(document.querySelector(
        '#nav-links a[href="#kunden-firmenzugang"]',
      )),
      customerPortal: hasLabel('Kundenportal'),
      businessPortal: hasLabel('Firmenportal'),
      supportTicket: hasLabel('Support-Ticket erstellen'),
      repairStatus: hasLabel('Reparaturstatus'),
      passwordForgot: links.some((link) =>
        link.getAttribute('href') === '/repair_neu/public/portal.php?view=forgot'),
      accessRequest: Boolean(document.querySelector('#portal-access-request')),
      footerPortalLinks: document.querySelectorAll(
        'footer a[href^="/repair_neu/public/portal"]',
      ).length,
      horizontalOverflow:
        document.documentElement.scrollWidth > document.documentElement.clientWidth,
      portalColumns: section
        ? getComputedStyle(section.querySelector('.portal-grid')).gridTemplateColumns
        : null,
    };
  });

  const mobile = await browser.newPage({
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
  });
  await mobile.goto(`https://mztech-it.de/?mobile_test=${Date.now()}`, {
    waitUntil: 'networkidle',
  });
  await mobile.locator('#hamburger').click();
  results.mobile = await mobile.evaluate(() => {
    const nav = document.querySelector('#nav-links');
    const section = document.querySelector('#kunden-firmenzugang');
    return {
      hamburgerVisible:
        getComputedStyle(document.querySelector('#hamburger')).display !== 'none',
      menuOpened: nav?.classList.contains('open') ?? false,
      portalNavVisible: Boolean(
        document.querySelector('#nav-links a[href="#kunden-firmenzugang"]'),
      ),
      horizontalOverflow:
        document.documentElement.scrollWidth > document.documentElement.clientWidth,
      portalColumns: section
        ? getComputedStyle(section.querySelector('.portal-grid')).gridTemplateColumns
        : null,
    };
  });

  const failures = [];
  for (const [name, value] of Object.entries(results.desktop)) {
    if (typeof value === 'boolean' &&
        (name === 'horizontalOverflow' ? value : !value)) {
      failures.push(`desktop.${name}`);
    }
  }
  if (results.desktop.footerPortalLinks < 5) failures.push('desktop.footerPortalLinks');
  if (!results.desktop.portalColumns?.includes('px')) failures.push('desktop.portalColumns');
  for (const [name, value] of Object.entries(results.mobile)) {
    if (typeof value === 'boolean' &&
        (name === 'horizontalOverflow' ? value : !value)) {
      failures.push(`mobile.${name}`);
    }
  }
  if (results.mobile.portalColumns?.split(' ').length !== 1) {
    failures.push('mobile.portalColumns');
  }

  console.log(JSON.stringify({ ...results, failures }, null, 2));
  process.exitCode = failures.length ? 1 : 0;
} finally {
  await browser.close();
}
