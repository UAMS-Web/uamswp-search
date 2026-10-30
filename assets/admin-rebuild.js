(() => {
  'use strict';
  const config = window.UAMSRebuild;
  const root = document.getElementById('uams-search-rebuild');
  if (!config || !root) return;
  const get = name => document.getElementById(`uams-rebuild-${name}`);
  const start = get('start'), resume = get('resume'), pause = get('pause'), cancel = get('cancel');
  const progress = get('progress'), status = get('status'), counts = get('counts'), error = get('error'), include = get('noindex');
  let job = config.job, running = false, pumping = false, pending = null;
  const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
  const active = () => job && ['running', 'paused'].includes(job.status);
  const say = text => { if (status.textContent !== text) status.textContent = text; };
  const failure = message => { error.textContent = message; error.hidden = !message; };
  const percent = () => {
    if (!job) return 0;
    if (job.status === 'complete') return 100;
    const weights = job.sites.map(s => Math.max(1, s.total));
    const finished = weights.slice(0, job.site_index).reduce((a, b) => a + b, 0);
    const site = job.sites[job.site_index];
    if (!site) return 0;
    const fractions = { prepare: 0, scan: Math.min(1, (job.site_checked || 0) / Math.max(1, site.total)) * 0.65, promote: 0.66, dictionary_cleanup: 0.7, dictionary: 0.7 + Math.min(1, job.dictionary_done / Math.max(1, job.dictionary_total)) * 0.28, finish: 0.99 };
    return Math.min(99, Math.floor(100 * (finished + weights[job.site_index] * (fractions[job.phase] || 0)) / weights.reduce((a, b) => a + b, 0)));
  };
  const render = () => {
    start.hidden = !!active(); resume.hidden = !active() || running;
    pause.hidden = !active() || !running; cancel.hidden = !active();
    include.disabled = !!active();
    pause.disabled = cancel.disabled = !!pending;
    progress.value = percent(); progress.setAttribute('aria-valuetext', `${progress.value}% complete`);
    if (!job) { say('Ready to rebuild.'); counts.textContent = ''; return; }
    const n = value => Number(value).toLocaleString();
    counts.textContent = `Checked ${n(job.checked)} posts of about ${n(job.total)}. Prepared ${n(job.indexed)} searchable items. Failed: ${n(job.failed)}. Sites finished: ${n(job.completed_sites)} of ${n(job.sites.length)}.`;
    if (job.dictionary_skipped) counts.textContent += ` Spelling lists skipped after repeated interruptions: ${n(job.dictionary_skipped)}.`;
    if (job.status === 'complete') { say('Index rebuilt. Reload this page to see the updated status counts.'); return; }
    if (job.status === 'cancelled') { say('Rebuild cancelled. Search keeps the most recent completed content for each site.'); return; }
    if (pending) { say(`${pending === 'pause' ? 'Pausing' : 'Cancelling'} after the current batch finishes…`); return; }
    if (!running || job.status === 'paused') { say('Rebuild paused. Your progress is saved. Choose Resume rebuild to continue.'); return; }
    const phases = { prepare: 'Preparing', scan: 'Reading content on', promote: 'Making content searchable on', dictionary_cleanup: 'Updating spelling lists on', dictionary: 'Building spelling lists on', finish: 'Finishing' };
    say(`${phases[job.phase] || 'Working on'} ${job.sites[job.site_index]?.name || 'this site'}.`);
  };
  const request = async op => {
    const data = new URLSearchParams({ action: 'uams_search_rebuild', nonce: config.nonce, op, job_id: job?.id || '', revision: String(job?.revision || 0), include_noindex: include.checked ? '1' : '0' });
    const response = await fetch(config.url, { method: 'POST', credentials: 'same-origin', body: data });
    let result;
    try { result = await response.json(); } catch { throw new Error('The server interrupted this request. Progress is saved. Reload this page and resume.'); }
    if (!response.ok || !result.success) throw new Error(result.data?.message || 'This request was refused. Reload this page, check your sign-in, and resume.');
    if (result.data.job) job = result.data.job;
    return result.data;
  };
  const pump = async () => {
    if (pumping) return;
    pumping = true;
    try {
      while (running && active()) {
        const op = pending || 'step';
        const result = await request(op);
        if (result.busy) { say('Another batch is still running. Waiting…'); await wait(1000); continue; }
        if (op !== 'step') { pending = null; running = false; }
        if (job.error || job.status !== 'running') running = false;
        failure(job.error || ''); render();
        if (running) await wait(150);
      }
    } catch (e) { running = false; pending = null; failure(e.message); render(); }
    finally { pumping = false; render(); }
  };
  start.addEventListener('click', async () => {
    start.disabled = true; failure('');
    try {
      const result = await request('start');
      if (result.busy) { render(); failure('Another indexer is running. Try again when it finishes.'); return; }
      running = !!active(); render(); await pump();
    } catch (e) { failure(e.message); }
    finally { start.disabled = false; }
  });
  resume.addEventListener('click', async () => {
    resume.disabled = true; failure('');
    try { await request('status'); running = !!active(); render(); await pump(); }
    catch (e) { running = false; failure(e.message); render(); }
    finally { resume.disabled = false; }
  });
  pause.addEventListener('click', () => { pending = 'pause'; render(); });
  cancel.addEventListener('click', () => { pending = 'cancel'; running = true; render(); pump(); });
  failure(job?.error || ''); render();
})();
