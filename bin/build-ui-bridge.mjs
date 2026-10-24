import {readFileSync, writeFileSync} from 'node:fs';
import {stripTypeScriptTypes} from 'node:module';

const source = readFileSync(new URL('../ui/bridge.ts', import.meta.url), 'utf8');
const compiled = '// Generated from bridge.ts using node:module stripTypeScriptTypes.\n' + stripTypeScriptTypes(source, {mode: 'strip'}).replace(/[ \t]+$/gm, '');
writeFileSync(new URL('../ui/bridge.js', import.meta.url), compiled);
