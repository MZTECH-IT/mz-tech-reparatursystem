const port = Number(process.env.MZTECH_CDP_PORT || '9223');
const recipient = String(process.env.MZTECH_TEST_RECIPIENT || '').trim().toLowerCase();
const command = process.argv[2] || 'inspect';

if (!recipient || !recipient.includes('@')) {
  throw new Error('Die bestaetigte TEST-Empfaengeradresse fehlt.');
}

async function pages() {
  const response = await fetch(`http://127.0.0.1:${port}/json`);
  if (!response.ok) throw new Error(`CDP HTTP ${response.status}`);
  return (await response.json()).filter((target) => target.type === 'page');
}

async function withCdp(target, callback) {
  const socket = new WebSocket(target.webSocketDebuggerUrl);
  const pending = new Map();
  let nextId = 1;
  await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
  });
  socket.addEventListener('message', (event) => {
    const message = JSON.parse(String(event.data));
    const waiter = pending.get(message.id);
    if (!waiter) return;
    pending.delete(message.id);
    if (message.error) waiter.reject(new Error(message.error.message));
    else waiter.resolve(message.result);
  });
  const invoke = (method, params = {}) => new Promise((resolve, reject) => {
    const id = nextId++;
    pending.set(id, { resolve, reject });
    socket.send(JSON.stringify({ id, method, params }));
  });
  try {
    await invoke('Runtime.enable');
    await invoke('Page.enable');
    return await callback(invoke);
  } finally {
    socket.close();
  }
}

const targets = await pages();
const target = targets.find((page) => page.url.includes('/repair_neu/public/'));
if (!target) throw new Error('Kein angemeldetes Produktiv-Browserfenster gefunden.');

const evaluate = async (expression) => withCdp(target, async (invoke) => {
  const result = await invoke('Runtime.evaluate', {
    expression,
    awaitPromise: true,
    returnByValue: true,
  });
  if (result.exceptionDetails) throw new Error('Browserauswertung fehlgeschlagen.');
  return result.result?.value;
});

const navigate = async (url) => withCdp(target, async (invoke) => {
  await invoke('Page.navigate', { url });
  await new Promise((resolve) => setTimeout(resolve, 1800));
});

await navigate('https://mztech-it.de/repair_neu/public/portal_access.php');

const inspectExpression = `(() => {
  const recipient = ${JSON.stringify(recipient)};
  return [...document.querySelectorAll('form')]
    .filter((form) => form.querySelector('[name="action"][value="send_activation"]'))
    .map((form) => {
      const row = form.closest('tr, article, .card');
      const text = (row?.innerText || '').toLowerCase();
      return {
        type: form.querySelector('[name="account_type"]')?.value || '',
        id: form.querySelector('[name="account_id"]')?.value || '',
        isTest: /\\btest\\b/i.test(row?.innerText || ''),
        recipientMatches: text.includes(recipient),
      };
    })
    .filter((entry) => entry.isTest && entry.recipientMatches);
})()`;

const matches = await evaluate(inspectExpression);
const byType = new Map(matches.map((entry) => [entry.type, entry]));

if (command === 'create-company-test') {
  if (byType.has('company_contact')) {
    process.stdout.write(JSON.stringify({ companyTestRecipientAligned: true, alreadyPresent: true }));
    process.exit(0);
  }
  const companyAddressInUse = await evaluate(`(() => {
    const recipient = ${JSON.stringify(recipient)};
    return [...document.querySelectorAll('form')]
      .filter((form) => form.querySelector('[name="account_type"]')?.value === 'company_contact')
      .some((form) => (form.closest('tr, article, .card')?.innerText || '').toLowerCase().includes(recipient));
  })()`);
  if (companyAddressInUse) throw new Error('Die TEST-Adresse wird bereits von einem Firmenzugang verwendet.');

  await navigate('https://mztech-it.de/repair_neu/public/companies.php');
  const companyPath = await evaluate(`(() => {
    const row = [...document.querySelectorAll('tr')].find((candidate) =>
      /\\bTEST\\b/i.test(candidate.innerText || '') && /Firma Portal/i.test(candidate.innerText || ''));
    const link = row?.querySelector('a[href*="companies_form.php?id="]');
    return link ? new URL(link.href).href : '';
  })()`);
  if (!companyPath) throw new Error('Die TEST-Firma wurde nicht gefunden.');
  await navigate(companyPath);
  const created = await evaluate(`(() => {
    const recipient = ${JSON.stringify(recipient)};
    const form = [...document.querySelectorAll('form')].find((candidate) =>
      candidate.querySelector('[name="action"]')?.value === 'add_contact');
    if (!form) return false;
    form.querySelector('[name="first_name"]').value = 'TEST';
    form.querySelector('[name="last_name"]').value = 'Aktivierung E-Mail 20260801';
    form.querySelector('[name="email"]').value = recipient;
    const role = form.querySelector('[name="role_title"]');
    if (role) role.value = 'TEST Portalaktivierung';
    const portalRole = form.querySelector('[name="portal_role"]');
    if (portalRole) portalRole.value = 'admin';
    const primary = form.querySelector('[name="is_primary"]');
    if (primary) primary.checked = false;
    form.requestSubmit();
    return true;
  })()`);
  if (!created) throw new Error('Der TEST-Firmenkontakt konnte nicht eindeutig angelegt werden.');
  await new Promise((resolve) => setTimeout(resolve, 2200));
  const success = await evaluate(`(() =>
    [...document.querySelectorAll('.alert-success,.success')].some((node) =>
      node.textContent.includes('Ansprechpartner wurde angelegt.'))
  )()`);
  if (!success) throw new Error('Das Anlegen des TEST-Firmenkontakts wurde nicht bestaetigt.');
  process.stdout.write(JSON.stringify({ companyTestRecipientAligned: true, alreadyPresent: false }));
  process.exit(0);
}

if (command === 'inspect') {
  const allMatches = await evaluate(`(() => {
    const recipient = ${JSON.stringify(recipient)};
    return [...document.querySelectorAll('form')]
      .filter((form) => form.querySelector('[name="action"][value="send_activation"]'))
      .filter((form) => (form.closest('tr, article, .card')?.innerText || '').toLowerCase().includes(recipient))
      .map((form) => ({
        type: form.querySelector('[name="account_type"]')?.value || '',
        isTest: /\\bTEST\\b/i.test(form.closest('tr, article, .card')?.innerText || '')
      }));
  })()`);
  process.stdout.write(JSON.stringify({
    customerTestMatched: byType.has('customer'),
    companyTestMatched: byType.has('company_contact'),
    customerAddressInUse: allMatches.some((entry) => entry.type === 'customer'),
    companyAddressInUse: allMatches.some((entry) => entry.type === 'company_contact'),
    addressBelongsOnlyToTestRows: allMatches.every((entry) => entry.isTest),
  }));
  process.exit(0);
}

if (command === 'rate-limit') {
  const submitted = await evaluate(`(() => {
    const recipient = ${JSON.stringify(recipient)};
    const form = [...document.querySelectorAll('form')].find((candidate) => {
      if (candidate.querySelector('[name="action"]')?.value !== 'send_activation') return false;
      if (candidate.querySelector('[name="account_type"]')?.value !== 'company_contact') return false;
      const text = candidate.closest('tr, article, .card')?.innerText || '';
      return /\\bTEST\\b/i.test(text) && text.toLowerCase().includes(recipient);
    });
    if (!form) return false;
    window.confirm = () => true;
    (form.querySelector('button[type="submit"], input[type="submit"]'))?.click();
    return true;
  })()`);
  if (!submitted) throw new Error('Das Firmen-TEST-Konto wurde nicht gefunden.');
  await new Promise((resolve) => setTimeout(resolve, 2200));
  const blocked = await evaluate(`document.body.innerText.includes('Bitte warten Sie noch')`);
  if (!blocked) throw new Error('Das Fuenf-Minuten-Rate-Limit wurde nicht angezeigt.');
  process.stdout.write(JSON.stringify({ rateLimitBlocked: true }));
  process.exit(0);
}

if (command === 'verify-activated') {
  const status = await evaluate(`(() => {
    const recipient = ${JSON.stringify(recipient)};
    const rows = [...document.querySelectorAll('tr')].filter((row) => {
      const text = row.innerText || '';
      return /\\bTEST\\b/i.test(text) && text.toLowerCase().includes(recipient);
    });
    const result = { customer: false, company: false, openActivationActions: 0, matchedRows: 0 };
    for (const row of rows) {
      const type = row.querySelector('form [name="account_type"]')?.value || '';
      if (!['customer', 'company_contact'].includes(type)) continue;
      result.matchedRows++;
      const actions = [...row.querySelectorAll('form [name="action"]')].map((input) => input.value);
      const open = actions.filter((action) =>
        ['send_activation', 'verify_account', 'create_activation', 'revoke_activation'].includes(action));
      result.openActivationActions += open.length;
      if (open.length === 0 && actions.includes('deactivate_account')) {
        if (type === 'customer') result.customer = true;
        if (type === 'company_contact') result.company = true;
      }
    }
    return result;
  })()`);
  if (!status.customer || !status.company || status.openActivationActions !== 0) {
    process.stdout.write(JSON.stringify({
      customerActivated: status.customer,
      companyActivated: status.company,
      openActivationActions: status.openActivationActions,
      matchedRows: status.matchedRows,
    }));
    process.exitCode = 2;
    process.exit();
  }
  process.stdout.write(JSON.stringify({ customerActivated: true, companyActivated: true, openActivationActions: 0 }));
  process.exit(0);
}

if (!['send', 'send-company'].includes(command)) throw new Error('Unbekannter Befehl.');
if (!byType.has('customer') || !byType.has('company_contact')) {
  if (command === 'send' || !byType.has('company_contact')) {
    throw new Error('Die benoetigten TEST-Konten stimmen nicht mit der bestaetigten TEST-Adresse ueberein.');
  }
}

const results = [];
const sendTypes = command === 'send-company' ? ['company_contact'] : ['customer', 'company_contact'];
for (const type of sendTypes) {
  await navigate('https://mztech-it.de/repair_neu/public/portal_access.php');
  const submitExpression = `(() => {
    const recipient = ${JSON.stringify(recipient)};
    const type = ${JSON.stringify(type)};
    const form = [...document.querySelectorAll('form')].find((candidate) => {
      if (candidate.querySelector('[name="action"]')?.value !== 'send_activation') return false;
      if (candidate.querySelector('[name="account_type"]')?.value !== type) return false;
      const row = candidate.closest('tr, article, .card');
      const text = (row?.innerText || '');
      return /\\bTEST\\b/i.test(text) && text.toLowerCase().includes(recipient);
    });
    if (!form) return false;
    window.confirm = () => true;
    const button = form.querySelector('button[type="submit"], input[type="submit"]');
    if (button) button.click(); else form.requestSubmit();
    return true;
  })()`;
  if (!(await evaluate(submitExpression))) throw new Error(`TEST-Konto nicht gefunden: ${type}`);
  await new Promise((resolve) => setTimeout(resolve, 2500));
  const outcome = await evaluate(`(() => ({
    success: [...document.querySelectorAll('.alert-success,.success')].some((node) =>
      node.textContent.includes('Der Aktivierungslink wurde erfolgreich per E-Mail versendet.')),
    rateLimited: document.body.innerText.includes('Bitte warten Sie noch'),
    error: document.body.innerText.includes('Die E-Mail konnte nicht versendet werden.')
  }))()`);
  if (!outcome.success) {
    if (outcome.rateLimited) throw new Error(`Rate-Limit aktiv: ${type}`);
    if (outcome.error) throw new Error(`Versandfehler: ${type}`);
    throw new Error(`Erfolgsmeldung fehlt: ${type}`);
  }
  results.push({ type, sent: true });
}

process.stdout.write(JSON.stringify({ sent: results }));
