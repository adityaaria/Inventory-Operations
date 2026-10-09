'use strict';
// Merges LCOV line data (DA records) from several reports by summing hits per file and line.
// Usage: node scripts/merge-lcov.cjs <out.info> <in.info>...
const fs = require('node:fs');
const [out, ...inputs] = process.argv.slice(2);
if (!out || inputs.length === 0) throw new Error('Usage: node scripts/merge-lcov.cjs <out.info> <in.info>...');
const files = new Map();

for (const input of inputs) {
    let lines = null;
    for (const row of fs.readFileSync(input, 'utf8').split('\n')) {
        if (row.startsWith('SF:')) {
            const file = row.slice(3);
            lines = files.get(file) || new Map();
            files.set(file, lines);
        } else if (row.startsWith('DA:') && lines) {
            const [line, count] = row.slice(3).split(',').map(Number);
            lines.set(line, (lines.get(line) || 0) + count);
        } else if (row === 'end_of_record') {
            lines = null;
        }
    }
}

const report = [...files].sort(([a], [b]) => a.localeCompare(b)).map(([file, lines]) => {
    const rows = [...lines].sort(([a], [b]) => a - b).map(([line, count]) => `DA:${line},${count}`);
    const covered = [...lines.values()].filter(count => count > 0).length;
    return [`SF:${file}`, ...rows, `LH:${covered}`, `LF:${lines.size}`, 'end_of_record'].join('\n');
});
fs.writeFileSync(out, report.join('\n') + '\n');
console.log(JSON.stringify({ written: out, files: files.size }));
