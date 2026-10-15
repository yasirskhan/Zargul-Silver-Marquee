import test from 'node:test';
import assert from 'node:assert/strict';
import {SAMPLE_PAGE,validatePage,moveSection,moveColumn,moveMobileColumn,updateText,publishPreview} from '../assets/cms-schema.mjs';
const demo=()=>JSON.parse(JSON.stringify(SAMPLE_PAGE));
test('approved schema accepts a typed homepage with 2-column section',()=>assert.equal(validatePage(demo()),true));
test('reordering sections never mutates the original and preserves IDs',()=>{
 const page=demo(),moved=moveSection(page,1,0);
 assert.equal(page.sections[0].id,'hero_1');
 assert.equal(moved.sections[0].id,'split_1');
 assert.equal(moved.sections.length,page.sections.length);
});
test('owner can move desktop columns without replacing content',()=>{
 const page=moveColumn(demo(),'split_1',0,1);
 assert.equal(page.sections[1].columns[0].id,'col_right');
 assert.equal(page.sections[1].columns[0].blocks[0].data.title,'Our halls');
 assert.equal(page.sections[1].columns[1].widthDesktop,5);
});
test('mobile column order is independently adjustable',()=>{
 const before=demo(),after=moveMobileColumn(before,'split_1',0,1);
 assert.equal(after.sections[1].columns[0].id,'col_left');
 assert.equal(after.sections[1].columns[0].mobileOrder,1);
 assert.equal(after.sections[1].columns[1].mobileOrder,2);
 assert.equal(before.sections[1].columns[0].mobileOrder,2);
});
test('text edits update one block without mutating other blocks',()=>{
 const page=demo(),next=updateText(page,'copy_1','title','Beautiful celebrations');
 assert.equal(next.sections[1].columns[1].blocks[0].data.title,'Beautiful celebrations');
 assert.equal(page.sections[1].columns[1].blocks[0].data.title,'Our halls');
});
test('untrusted text content and unsafe URLs are rejected',()=>{
 assert.throws(()=>updateText(demo(),'copy_1','body','<script>alert(1)</script>'));
 assert.throws(()=>updateText(demo(),'hero_1','buttonHref','javascript:alert(1)'));
 assert.throws(()=>updateText(demo(),'hero_1','onClick','alert(1)'));
});
test('invalid layouts and fake blocks fail closed',()=>{
 const page=demo();page.sections[1].columns[0].widthDesktop=8;
 assert.throws(()=>validatePage(page));
 const x=demo();x.sections.push({id:'evil',type:'custom-html',data:{html:'<script>bad</script>'}});
 assert.throws(()=>validatePage(x));
 const y=demo();y.sections[1].columns[1].blocks[0].id='photo_1';
 assert.throws(()=>validatePage(y));
});
test('publishing a preview snapshots content but does not mutate draft',()=>{
 const page=demo(),published=publishPreview(page,1,'owner-01');
 assert.equal(published.published.version,1);
 assert.equal(published.published.sections[0].data.title,'Celebrate your story');
 const edited=updateText(published,'hero_1','title','Our new headline');
 assert.equal(edited.published.sections[0].data.title,'Celebrate your story');
 assert.equal(page.published,undefined);
});
test('draft revision mismatch rejects a stale publishing attempt',()=>{
 assert.throws(()=>publishPreview(demo(),3,'owner-01'));
 assert.throws(()=>publishPreview(demo(),1,''));
});
test('bad reordering cannot lose data',()=>{
 assert.throws(()=>moveSection(demo(),99,0));
 assert.throws(()=>moveColumn(demo(),'hero_1',0,1));
 assert.throws(()=>moveMobileColumn(demo(),'split_1',-1,0));
});
