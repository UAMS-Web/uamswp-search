const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const html = fs.readFileSync(process.argv[2] || path.join(__dirname, '.preview.html'), 'utf8');
const axe = process.env.AXE_SOURCE || require.resolve('axe-core/axe.min.js');

(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext();
    await context.route('https://uams-search.test/**', async route => {
      const url = new URL(route.request().url());
      if (url.pathname.includes('/wp-json/')) {
        const term = url.searchParams.get('s');
        if (term === 'older') await new Promise(r => setTimeout(r, 700));
        const data = { items: [
          { title: `${term} clinic`, url: 'https://uams-search.test/clinic/' },
          { title: '<img src=x onerror=alert(1)>', url: 'https://uams-search.test/provider/' }
        ], total: 2 };
        try { await route.fulfill({ json: data }); } catch {}
      } else await route.fulfill({ contentType: 'text/html', body: html });
    });
    const page = await context.newPage();
    await page.goto('https://uams-search.test/');
    const input = page.getByRole('combobox');
    await input.fill('heart');
    await page.getByRole('option').first().waitFor();
    assert.equal(await input.getAttribute('aria-expanded'), 'true');
    assert.equal(await page.locator('.uams-search-options img').count(), 0, 'Response titles must be text, never HTML');
    await input.press('ArrowDown');
    assert.equal(await page.getByRole('option').first().getAttribute('aria-selected'), 'true');
    assert.equal(await input.getAttribute('aria-activedescendant'), await page.getByRole('option').first().getAttribute('id'));
    await input.press('ArrowUp');
    assert.equal(await page.getByRole('option').last().getAttribute('aria-selected'), 'true');
    await input.press('Escape');
    assert.equal(await input.getAttribute('aria-expanded'), 'false');
    assert.equal(await input.getAttribute('aria-activedescendant'), null);
    await input.fill('older');
    await page.waitForTimeout(300);
    await input.fill('newer');
    await page.getByRole('option').filter({ hasText: 'newer clinic' }).waitFor();
    await page.waitForTimeout(750);
    assert.equal(await page.getByRole('option').first().textContent(), 'newer clinic', 'A late response must not replace newer suggestions');
    await input.press('Tab');
    assert.equal(await input.getAttribute('aria-expanded'), 'false');
    assert.equal(await page.evaluate(() => document.activeElement.tagName), 'BUTTON');
    for (const width of [1280, 320]) {
      await page.setViewportSize({ width, height: 900 });
      await page.addScriptTag({ path: axe });
      const results = await page.evaluate(async () => axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa'] } }));
      assert.deepEqual(results.violations.map(v => ({ id: v.id, nodes: v.nodes.map(n => n.target) })), [], `Accessibility violations at ${width}px`);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `Horizontal overflow at ${width}px`);
    }
    await input.fill('heart');
    await page.getByRole('option').first().waitFor();
    await input.press('ArrowDown');
    await Promise.all([page.waitForURL('**/clinic/'), input.press('Enter')]);
    const plain = await browser.newContext({ javaScriptEnabled: false });
    await plain.route('https://uams-search.test/**', r => r.fulfill({ contentType: 'text/html', body: html }));
    const nojs = await plain.newPage();
    await nojs.goto('https://uams-search.test/');
    assert.equal(await nojs.locator('form[role=search]').getAttribute('method'), 'get');
    assert.equal(await nojs.getByRole('button', { name: 'Search', exact: true }).count(), 1);
    console.log('PASS: keyboard navigation, Escape, Tab, Enter, late responses, text-only titles, no-JS form, axe at 1280px/320px, and horizontal reflow.');
    await page.screenshot({ path: path.resolve('docs/search-preview.png'), fullPage: true });
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
