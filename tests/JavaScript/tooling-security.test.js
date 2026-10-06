import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { tmpdir } from 'node:os';

// Resolve the actual transitive dependency, without adding shell-quote to the app.
const require = createRequire(import.meta.url);
const runnerPackage = require.resolve('concurrently/package.json');
const runnerRequire = createRequire(runnerPackage);
const { quote, parse } = runnerRequire('shell-quote');
const runner = join(dirname(runnerPackage), 'dist/bin/index.js');
const environment = { PATH: '/usr/bin:/bin', NO_COLOR: '1' };

test('GHSA-pqg4-j6r4-53mv rejects all four line terminators after comment tokens without executing a shell', () => {
    for (const separator of ['\n', '\r', '\u2028', '\u2029']) {
        assert.throws(() => quote(['echo', 'synthetic', { comment: 'fixture' }, `a${separator}ignored`]), TypeError);
    }
});

test('parsed comments followed by an untrusted multiline argument are rejected', () => {
    const tokens = parse('echo synthetic #fixture');
    assert.throws(() => quote(tokens.concat('first\nignored')), TypeError);
});

test('ordinary string arguments round-trip through the native POSIX shell', () => {
    const args = ['plain', 'with spaces', "apostrophe's", 'double"quote', '#literal', ';literal', '$literal', 'line\nbreak', '', 'ümlaut'];
    const result = spawnSync('/bin/sh', ['-c', `printf '%s\\0' ${quote(args)}`], { env: environment, encoding: 'utf8', timeout: 5000 });
    assert.ifError(result.error);
    assert.equal(result.status, 0);
    assert.deepEqual(result.stdout.split('\0').slice(0, -1), args);
});

test('the installed concurrently CLI preserves additional arguments', () => {
    const args = ['with spaces', "apostrophe's", '#literal', '$literal', 'line\nbreak'];
    const command = `${quote([process.execPath, '-e', 'process.stdout.write(JSON.stringify(process.argv.slice(1)))'])} {@}`;
    const result = spawnSync(process.execPath, [runner, '--raw', '--passthrough-arguments', command, '--', ...args], { env: environment, encoding: 'utf8', timeout: 5000 });
    assert.ifError(result.error);
    assert.equal(result.status, 0);
    assert.deepEqual(JSON.parse(result.stdout), args);
});

test('the project runner flags preserve named output, failure propagation and sibling shutdown', () => {
    const directory = mkdtempSync(join(tmpdir(), 'ewaste-tooling-'));
    try {
        const ready = join(directory, 'ready');
        const failing = `const fs=require('node:fs');console.log('synthetic-server');const start=Date.now();const t=setInterval(()=>{if(fs.existsSync(${JSON.stringify(ready)})){clearInterval(t);process.exit(1)}else if(Date.now()-start>3000){process.exit(2)}},10)`;
        const sibling = `const fs=require('node:fs');fs.writeSync(1,'synthetic-vite\\n');fs.writeFileSync(${JSON.stringify(ready)},'ready');process.on('SIGTERM',()=>process.exit(0));setTimeout(()=>process.exit(2),5000)`;
        const commands = [failing, sibling].map((code) => quote([process.execPath, '-e', code]));
        const result = spawnSync(process.execPath, [runner, '--names=server,vite', '--kill-others', ...commands], { env: environment, encoding: 'utf8', timeout: 8000 });
        assert.ifError(result.error);
        assert.equal(result.status, 1);
        assert.match(result.stdout, /\[server\] synthetic-server/);
        assert.match(result.stdout, /\[vite\] synthetic-vite/);
        assert.match(result.stdout, /Sending SIGTERM/);
        assert.doesNotMatch(result.stdout, /exited with code 2/);
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }
});
