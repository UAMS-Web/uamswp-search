const fs=require('node:fs');
const assert=require('node:assert/strict');
const {chromium}=require('playwright');
(async()=>{
 const html=fs.readFileSync(process.argv[2],'utf8');
 const browser=await chromium.launch({headless:true});
 try {
  const context=await browser.newContext();
  await context.route('https://uams-search.test/**',route=>route.fulfill({contentType:'text/html',body:html}));
  const page=await context.newPage(); await page.goto('https://uams-search.test/');
  assert.deepEqual(await page.locator('article h3').allTextContents(),['C. Lowry Barnes, M.D.Adult Reconstructive Orthopaedic Surgeon','Alex ResidentResident Physician','Heart care','Dr. Fallback & "Quoted"']);
  assert.equal(await page.locator('.uams-search-provider-cards').count(),2,'Provider groups must not reorder mixed result types');
  assert.equal(await page.locator('.uams-search-result').count(),1);
  assert.equal(await page.getByText('Existing page result excerpt').count(),1);
  assert.equal(await page.getByText('Provider excerpt must not appear').count(),0);
  assert.equal(await page.locator('.uams-search-provider-placeholder').count(),2);
  assert.equal(await page.locator('.uams-search-provider img').getAttribute('alt'),'C. Lowry Barnes, M.D.');
  assert.equal(await page.locator('.uams-search-provider script,.uams-search-provider [onerror]').count(),0,'Saved provider fields must stay text');
  for(const width of [1440,768,320]) {
   await page.setViewportSize({width,height:1000});
   await page.addScriptTag({path:require.resolve('axe-core/axe.min.js')});
   const violations=await page.evaluate(async()=> (await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa','wcag22aa']}})).violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>n.target)})));
   assert.deepEqual(violations,[],`Accessibility violations at ${width}px`);
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,`Overflow at ${width}px`);
   const photo=await page.locator('.uams-search-provider picture').first().boundingBox();
   assert.ok(Math.abs(photo.height/photo.width-337/253)<.02,'Portraits retain the reference 3:4 crop');
   for(const button of await page.locator('.uams-search-provider a').all()) {
    const box=await button.boundingBox();assert.ok(box.height>=44,'Profile links retain a 44px target');
   }
  }
  const link=page.getByRole('link',{name:'View profile for C. Lowry Barnes, M.D.',exact:true});
  await link.focus(); assert.equal(await link.evaluate(el=>el===document.activeElement),true);
  await Promise.all([page.waitForURL('**/provider/101/'),link.press('Enter')]);
  console.log('PASS: provider portraits, degrees, clinical/resident titles, missing fields/images, escaped text, mixed-result order, keyboard profile link, 44px targets, axe and reflow at 1440/768/320px.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
