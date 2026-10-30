const fs = require('node:fs');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const html = fs.readFileSync(process.argv[2], 'utf8');
(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    let busyFinal = false, holdFinal = false, releaseFinal, finalStarted, job = null, complete = false, fail = false, startedWithPreview = false, concurrent = 0, maximum = 0;
    const context = await browser.newContext();
    await context.route('https://uams-search.test/**', async route => {
      if (!route.request().url().endsWith('admin-ajax.php')) {
        return route.fulfill({ contentType: 'text/html', body: html.replace('job:null', `job:${JSON.stringify(job)}`) });
      }
      const data = new URLSearchParams(route.request().postData());
      const op = data.get('op'); let busy = false;
      concurrent++; maximum = Math.max(maximum, concurrent);
      await new Promise(r => setTimeout(r, 80));
      concurrent--;
      if (fail && op === 'step') { fail = false; return route.fulfill({ status: 502, body: 'interrupted' }); }
      if (op === 'start') {
        startedWithPreview = data.get('include_noindex') === '1';
        job = { id:'fixture',revision:1,status:'running',phase:'scan',sites:[{id:1,name:'<img src=x onerror=alert(1)>',total:10}],site_index:0,completed_sites:0,checked:0,total:10,indexed:0,failed:0,site_checked:0,error:'' };
      }
      if (op === 'pause') job.status = 'paused';
      if (op === 'cancel') job.status = 'cancelled';
      if (op === 'step') {
        if (holdFinal) { busy = busyFinal; holdFinal = false; finalStarted(); await new Promise(resolve => { releaseFinal = resolve; }); }
        job.status = complete ? 'complete' : 'running'; job.checked++; job.site_checked++; job.revision++;
        if (complete) job.completed_sites = 1;
      }
      await route.fulfill({ json: { success:true,data:{job,busy} } });
    });
    const page = await context.newPage(); page.setDefaultTimeout(5000);
    const button = name => page.getByRole('button', { name, exact:true });
    await page.goto('https://uams-search.test/');
    await page.locator('#uams-rebuild-noindex').check();
    await button('Rebuild index').click();
    await button('Pause rebuild').waitFor();
    await button('Pause rebuild').click();
    await button('Resume rebuild').waitFor();
    assert.equal(startedWithPreview, true);
    assert.equal(job.status, 'paused');
    assert.equal(await page.locator('#uams-rebuild-status img').count(), 0);
    await page.reload();
    await button('Resume rebuild').waitFor();
    fail = true;
    await button('Resume rebuild').click();
    await page.locator('#uams-rebuild-error:not([hidden])').waitFor();
    assert.match(await page.locator('#uams-rebuild-error').textContent(), /interrupted/);
    await button('Cancel rebuild').click();
    await button('Rebuild index').waitFor();
    assert.equal(job.status, 'cancelled');
    complete = true;
    await button('Rebuild index').click();
    await page.getByRole('status').filter({ hasText:'Index rebuilt.' }).waitFor();
    assert.equal(await page.locator('progress').getAttribute('value'), '100');
    for (const [action, busyResponse] of [['Pause rebuild',false],['Cancel rebuild',false],['Pause rebuild',true],['Cancel rebuild',true]]) {
      holdFinal = true; busyFinal = busyResponse;
      const held = new Promise(resolve => { finalStarted = resolve; });
      await button('Rebuild index').click(); await held;
      await button(action).click(); releaseFinal();
      await page.getByRole('status').filter({hasText:'Index rebuilt.'}).waitFor();
      const finished = page.waitForResponse(r => r.url().endsWith('admin-ajax.php') && new URLSearchParams(r.request().postData()).get('op') === 'step');
      await button('Rebuild index').click(); await finished;
      await page.getByRole('status').filter({hasText:'Index rebuilt.'}).waitFor();
      assert.equal(job.status, 'complete', 'A late ' + action + ' must not control the next rebuild');
    }
    assert.equal(maximum, 1, 'Requests must stay sequential');
    for (const width of [1280,320]) {
      await page.setViewportSize({width,height:900});
      await page.addScriptTag({path:require.resolve('axe-core/axe.min.js')});
      const issues = await page.evaluate(async () => (await axe.run(document, {runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa','wcag22aa']}})).violations.map(v => v.id));
      assert.deepEqual(issues, []);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    }
    console.log('PASS: rebuild start, preview option, pause, saved-page resume, interrupted response, cancel, completion, sequential requests, text safety, axe and reflow.');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exit(1); });
