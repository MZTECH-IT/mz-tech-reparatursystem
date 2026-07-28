import { Buffer } from 'node:buffer';

const port = Number(process.env.MZTECH_CDP_PORT || '9223');
const command = process.argv[2] || 'list';

async function targets() {
  const response = await fetch(`http://127.0.0.1:${port}/json`);
  if (!response.ok) throw new Error(`CDP HTTP ${response.status}`);
  return (await response.json()).filter((target) => target.type === 'page');
}

async function callCdp(target, method, params = {}) {
  const socket = new WebSocket(target.webSocketDebuggerUrl);
  let nextId = 1;
  const pending = new Map();
  await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
  });
  socket.addEventListener('message', (event) => {
    const message = JSON.parse(String(event.data));
    if (!message.id || !pending.has(message.id)) return;
    const { resolve, reject } = pending.get(message.id);
    pending.delete(message.id);
    if (message.error) reject(new Error(message.error.message));
    else resolve(message.result);
  });
  const invoke = (name, values = {}) => new Promise((resolve, reject) => {
    const id = nextId++;
    pending.set(id, { resolve, reject });
    socket.send(JSON.stringify({ id, method: name, params: values }));
  });
  try {
    await invoke('Runtime.enable');
    await invoke('Page.enable');
    return await method(invoke, params);
  } finally {
    socket.close();
  }
}

const pages = await targets();
if (command === 'list' || command === 'list-redacted') {
  const redactUrl = (value) => {
    if (command === 'list') return value;
    try {
      const parsed = new URL(value);
      return `${parsed.origin}${parsed.pathname}${parsed.hash}`;
    } catch {
      return '[unavailable]';
    }
  };
  if (command === 'list') {
    process.stdout.write(JSON.stringify(pages.map(({ id, title, url }) => ({ id, title, url }))));
  } else {
    process.stdout.write(JSON.stringify(pages.map(({ id, title, url }) => ({
      id,
      title,
      url: redactUrl(url),
    }))));
  }
  process.exit(0);
}

const targetId = process.argv[3] || pages[0]?.id;
const target = pages.find((page) => page.id === targetId);
if (!target) throw new Error('Kein passendes Browserziel gefunden.');

if (command === 'eval') {
  const expression = Buffer.from(process.argv[4] || '', 'base64').toString('utf8');
  const result = await callCdp(target, async (invoke) => invoke('Runtime.evaluate', {
    expression,
    awaitPromise: true,
    returnByValue: true,
  }));
  process.stdout.write(JSON.stringify(result.result?.value ?? null));
} else if (command === 'navigate') {
  const url = Buffer.from(process.argv[4] || '', 'base64').toString('utf8');
  await callCdp(target, async (invoke) => {
    await invoke('Page.navigate', { url });
    await new Promise((resolve) => setTimeout(resolve, 1500));
    return null;
  });
  process.stdout.write('OK');
} else if (command === 'set-file') {
  const selector = Buffer.from(process.argv[4] || '', 'base64').toString('utf8');
  const filePath = Buffer.from(process.argv[5] || '', 'base64').toString('utf8');
  await callCdp(target, async (invoke) => {
    const document = await invoke('DOM.getDocument');
    const match = await invoke('DOM.querySelector', {
      nodeId: document.root.nodeId,
      selector,
    });
    if (!match.nodeId) throw new Error('Dateieingabefeld nicht gefunden.');
    await invoke('DOM.setFileInputFiles', {
      nodeId: match.nodeId,
      files: [filePath],
    });
    return null;
  });
  process.stdout.write('OK');
} else if (command === 'mobile') {
  await callCdp(target, async (invoke) => {
    await invoke('Emulation.setDeviceMetricsOverride', {
      width: 390,
      height: 844,
      deviceScaleFactor: 2,
      mobile: true,
    });
    await invoke('Emulation.setTouchEmulationEnabled', {
      enabled: true,
      maxTouchPoints: 5,
    });
    return null;
  });
  process.stdout.write('OK');
} else {
  throw new Error('Unbekannter CDP-Befehl.');
}
