'use strict';
// Records V8 precise coverage of the application's own scripts while another browser harness drives the same Chrome tab.
// Usage: node tests/Browser/js-coverage.cjs <devtoolsUrl> <out.lcov>   (start it first; it stops when Chrome closes or on SIGINT/SIGTERM).
// Counters reset on every poll, so per-line hits are summed across polls; a page swapped out between polls loses at most one interval.
const fs = require('node:fs');
const path = require('node:path');
const devtools = (process.argv[2] || 'http://127.0.0.1:9225').replace(/\/$/, '');
const out = process.argv[3] || 'var/coverage/lcov-browser.info';
const root = path.resolve(__dirname, '../..');
const hits = new Map(); // repo-relative file -> Map(line -> hits)
const sources = new Map();

function localFile(url) {
    let pathname;
    try { pathname = new URL(url).pathname; } catch { return null; }
    if (!/^\/assets\/js\/[\w-]+\.js$/.test(pathname)) return null;
    const file = 'public' + pathname;
    if (!sources.has(file)) sources.set(file, fs.existsSync(path.join(root, file)) ? fs.readFileSync(path.join(root, file), 'utf8') : null);
    return sources.get(file) === null ? null : file;
}

// Line hits = count of the innermost V8 range containing the line's first non-blank character (the line start when blank),
// the same per-line convention as Node's lcov reporter, which also reports every physical line.
function record(script) {
    const file = localFile(script.url);
    if (!file) return;
    const source = sources.get(file);
    const ranges = script.functions.flatMap(fn => fn.ranges);
    const lines = hits.get(file) || new Map();
    let offset = 0;
    const physical = source.split('\n');
    if (source.endsWith('\n')) physical.pop(); // the terminating newline does not start another line
    physical.forEach((text, index) => {
        const indent = text.search(/\S/);
        const position = offset + (indent < 0 ? 0 : indent);
        let best = null;
        for (const range of ranges) {
            if (range.startOffset <= position && position < range.endOffset
                && (!best || range.endOffset - range.startOffset < best.endOffset - best.startOffset)) best = range;
        }
        lines.set(index + 1, (lines.get(index + 1) || 0) + (best ? best.count : 0));
        offset += text.length + 1;
    });
    hits.set(file, lines);
}

function write() {
    const report = [...hits].sort(([a], [b]) => a.localeCompare(b)).map(([file, lines]) => {
        const rows = [...lines].map(([line, count]) => `DA:${line},${count}`);
        const covered = [...lines.values()].filter(count => count > 0).length;
        return [`SF:${file}`, ...rows, `LH:${covered}`, `LF:${lines.size}`, 'end_of_record'].join('\n');
    });
    fs.mkdirSync(path.dirname(out), { recursive: true });
    fs.writeFileSync(out, report.join('\n') + '\n');
    console.log(JSON.stringify({ written: out, files: hits.size }));
}

(async () => {
    const tabs = await (await fetch(devtools + '/json')).json();
    const ws = new WebSocket(tabs.find(tab => tab.type === 'page').webSocketDebuggerUrl);
    await new Promise(resolve => { ws.onopen = resolve; });
    let id = 0;
    const pending = new Map();
    ws.onmessage = event => {
        const message = JSON.parse(event.data);
        if (!message.id || !pending.has(message.id)) return;
        const request = pending.get(message.id);
        pending.delete(message.id);
        message.error ? request.reject(Error(message.error.message)) : request.resolve(message.result);
    };
    const call = (method, params = {}) => new Promise((resolve, reject) => { const n = ++id; pending.set(n, { resolve, reject }); ws.send(JSON.stringify({ id: n, method, params })); });
    let finished = false;
    const finish = () => { if (finished) return; finished = true; write(); process.exit(0); };
    const start = async () => { await call('Profiler.enable'); await call('Profiler.startPreciseCoverage', { callCount: true, detailed: true }); };
    // A renderer swap can drop the profiler state; restart it so later pages are still recorded.
    const poll = async () => { try { (await call('Profiler.takePreciseCoverage')).result.forEach(record); } catch { await start().catch(() => null); } };
    ws.onclose = finish;
    for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, async () => { await Promise.race([poll(), new Promise(r => setTimeout(r, 1000))]); finish(); });

    await start();
    console.log('recording');
    while (!finished) {
        await poll();
        await new Promise(resolve => setTimeout(resolve, 100));
    }
})().catch(error => { console.error(error.message); process.exit(1); });
