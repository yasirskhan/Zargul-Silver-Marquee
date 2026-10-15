import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const content=path=>readFileSync(new URL('../'+path,import.meta.url),'utf8');
test('website editor includes identity, section outline, draft and publish previews',()=>{
 const html=content('website-editor.html');
 for(const key of ['Website Editor','id="brand-name"','id="brand-color"','id="brand-logo"','id="outline"','id="inspector"','id="page-preview"','id="add-section"','id="save-draft"','id="publish"','id="history"']) assert.ok(html.includes(key),key);
 assert.ok(html.includes('No accounts, media, pages or payments are saved'));
});
test('responsive editing controls include desktop tablet mobile, column movement and local logo preview',()=>{
 const html=content('website-editor.html'),js=content('assets/website-editor.mjs'),css=content('assets/editor.css');
 for(const device of ['desktop','tablet','mobile']) assert.ok(html.includes('data-device="'+device+'"'));
 for(const fn of ['moveSection(','moveColumn(','moveMobileColumn(','updateText(','publishPreview('])assert.ok(js.includes(fn),fn);
 assert.ok(js.includes("role")===false || js.includes("demo-owner"));
 assert.ok(js.includes('revokeObjectURL')&&js.includes('4_000_000'));
 assert.ok(css.includes('.page-preview.mobile')&&css.includes('@media(max-width:760px)'));
});
test('admin dashboard links editor and owner-only demo role hides shortcut',()=>{
 assert.ok(content('admin.html').includes('id="site-editor-link"'));
 assert.ok(content('assets/admin.mjs').includes("toggle('hidden',role!=='owner')"));
});
