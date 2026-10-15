import {SAMPLE_PAGE,validatePage,moveSection,moveColumn,moveMobileColumn,updateText,publishPreview} from './cms-schema.mjs';
// Interactive in-tab demonstration. No persistence, real login, uploads or production publication.
const $=s=>document.querySelector(s), el=(tag,cls,text)=>{const x=document.createElement(tag);if(cls)x.className=cls;if(text!==undefined)x.textContent=text;return x};
let page=JSON.parse(JSON.stringify(SAMPLE_PAGE)),selected='hero_1',device='desktop',brandName='Zargul Silver Marquee',color='#caa56b',logoUrl='',imageUrl='',status='Unsaved changes in this browser only.',serial=0;
const labels={hero:'Hero / Banner',text:'Heading & Text',image:'Image',gallery:'Gallery',halls:'Halls',packages:'Packages',services:'Services',cta:'Call to Action',enquiry:'Enquiry Form',columns:'Column Layout',faq:'FAQs'};
function say(message){status=message;$('#editor-status').textContent=message}
function change(next){validatePage(next);page=next;page.draftRevision=(page.draftRevision||1)+1;say('Draft modified in this tab. Save/Publish are demonstrations only.');render()}
function add(kind){
 const id='block_'+(++serial)+'_'+Date.now().toString(36);
 let newBlock={id,type:kind,data:{title:labels[kind]}};
 if(kind==='columns')newBlock={id,type:kind,data:{},columns:[
  {id:id+'_left',widthDesktop:6,mobileOrder:1,blocks:[{id:id+'_text',type:'text',data:{title:'Your headline',body:'Click this column to edit.'}}]},
  {id:id+'_right',widthDesktop:6,mobileOrder:2,blocks:[{id:id+'_photo',type:'image',data:{imageId:'demo-placeholder',alt:'Venue photo'}}]}
 ]};
 if(kind==='cta')newBlock.data={title:'Celebrate with us',body:'Contact our team',buttonLabel:'Enquire',buttonHref:'#enquiry'};
 if(kind==='image')newBlock.data={imageId:'demo-placeholder',alt:'Venue photograph',caption:'Your venue'};
 if(kind==='enquiry')newBlock.data={title:'Check Availability',description:'Contact us about your date'};
 const n=JSON.parse(JSON.stringify(page));n.sections.push(newBlock);selected=id;change(n);
}
function doMove(i,to){if(to<0||to>=page.sections.length)return;change(moveSection(page,i,to))}
function renderOutline(){
 const host=$('#outline');host.replaceChildren();
 page.sections.forEach((s,i)=>{
  const outer=el('div','outline-item'+(selected===s.id?' selected':''));outer.draggable=true;
  outer.addEventListener('dragstart',e=>{e.dataTransfer?.setData('text/plain',String(i))});
  outer.addEventListener('dragover',e=>e.preventDefault());
  outer.addEventListener('drop',e=>{e.preventDefault();const from=Number(e.dataTransfer?.getData('text/plain'));if(Number.isInteger(from)&&from>=0&&from<page.sections.length)doMove(from,i)});
  const head=el('button','outline-head',`${i+1}. ${labels[s.type]||s.type}`);head.type='button';head.addEventListener('click',()=>{selected=s.id;render()});outer.append(head);
  const tools=el('div','outline-controls');
  for(const [symbol,target,caption] of [['↑',i-1,'Move up'],['↓',i+1,'Move down']]){
   const b=el('button','',symbol);b.type='button';b.title=caption;b.disabled=target<0||target>=page.sections.length;b.addEventListener('click',()=>doMove(i,target));tools.append(b);
  }
  const remove=el('button','','Delete');remove.title='Delete section';remove.type='button';remove.addEventListener('click',()=>{const n=JSON.parse(JSON.stringify(page));n.sections.splice(i,1);selected=n.sections[0]?.id||'';change(n)});tools.append(remove);outer.append(tools);host.append(outer);
 });
}
function textField(host,block,field,label){
 const value=block.data[field];if(typeof value!=='string')return;
 const wrapper=el('label','editor-field'),title=el('span','',label);wrapper.append(title);
 const input=field==='body'||field==='subtitle'||field==='description'?el('textarea'):el('input');if(input.tagName==='INPUT')input.type='text';input.value=value;input.addEventListener('change',()=>{
  try{change(updateText(page,block.id,field,input.value))}catch(e){say('Invalid edit: '+e.message);renderInspector()}
 });wrapper.append(input);host.append(wrapper);
}
function renderInspector(){
 const host=$('#inspector');host.replaceChildren();const section=page.sections.find(s=>s.id===selected);
 if(!section){host.append(el('p','','Select a section.'));return}
 host.append(el('p','',`${labels[section.type]} • section ID: ${section.id}`));
 for(const field of Object.keys(section.data||{}))if(field!=='mediaIds')textField(host,section,field,field.replace(/([A-Z])/g,' $1'));
 if(section.type==='columns'){
  section.columns.forEach((col,i)=>{
   const wrap=el('div','editor-columns');wrap.append(el('b','',`Column ${i+1} • width ${col.widthDesktop}/12`));
   const b=el('button','','Move left');b.type='button';b.disabled=i===0;b.addEventListener('click',()=>change(moveColumn(page,section.id,i,i-1)));wrap.append(b);
   const c=el('button','','Move right');c.type='button';c.disabled=i===section.columns.length-1;c.addEventListener('click',()=>change(moveColumn(page,section.id,i,i+1)));wrap.append(c);
   const ordered=[...section.columns].sort((a,b)=>a.mobileOrder-b.mobileOrder);const pos=ordered.findIndex(x=>x.id===col.id);
   for(const [text,to] of [['Mobile ↑',pos-1],['Mobile ↓',pos+1]]){
    const bt=el('button','',text);bt.type='button';bt.disabled=to<0||to>=ordered.length;bt.addEventListener('click',()=>change(moveMobileColumn(page,section.id,pos,to)));wrap.append(bt);
   }
   for(const child of col.blocks){wrap.append(el('p','',`Edit: ${labels[child.type]}`));for(const f of Object.keys(child.data||{}))if(f!=='mediaIds')textField(wrap,child,f,f)}
   host.append(wrap);
  });
  if(section.columns.length===2){
   const selector=el('select');for(const opt of ['6,6','4,8','8,4','3,9','9,3']){const o=el('option','',opt.replace(',',' / ')+' width');o.value=opt;if(section.columns.map(c=>c.widthDesktop).join(',')===opt)o.selected=true;selector.append(o)}
   selector.addEventListener('change',()=>{const widths=selector.value.split(',').map(Number),n=JSON.parse(JSON.stringify(page)),s=n.sections.find(a=>a.id===section.id);s.columns.forEach((c,i)=>c.widthDesktop=widths[i]);change(n)});
   const label=el('label','','Desktop column widths');label.append(selector);host.append(label);
  }
 }
}
function leaf(block){
 const box=el('div');const title=block.data?.title||labels[block.type]||'';
 if(block.type==='image'){const img=el('div','mock-image');if(imageUrl){const image=el('img');image.src=imageUrl;image.alt=block.data.alt||'Preview image';img.append(image)}else img.textContent='IMAGE PLACEHOLDER';box.append(img);if(block.data.caption)box.append(el('p','',block.data.caption));return box}
 if(block.type==='text'||block.type==='cta'||block.type==='hero'||block.type==='enquiry'||block.type==='halls'||block.type==='packages'||block.type==='services'){box.append(el(block.type==='hero'?'h2':'h3','',title));if(block.data?.subtitle||block.data?.body||block.data?.description)box.append(el('p','',block.data.subtitle||block.data.body||block.data.description))}
 if(block.type==='enquiry'){box.append(el('div','mock-enquiry','Customer enquiry form • integrated with booking engine in production'))}
 if(block.type==='halls'||block.type==='services'||block.type==='packages'){box.append(el('div','mock-enquiry','Dynamic venue catalog placeholder — managed securely from VenueDesk'))}
 if(block.data?.buttonLabel){const a=el('span','mock-button',block.data.buttonLabel);box.append(a)}
 return box;
}
function renderPreview(){
 const host=$('#page-preview');host.replaceChildren();host.className='page-preview '+device;host.style.setProperty('--editor-accent',color);
 const brand=el('header','mock-brand');if(logoUrl){const img=el('img');img.src=logoUrl;img.alt='Local logo preview';brand.append(img)}brand.append(el('strong','',brandName));host.append(brand);
 for(const s of page.sections){
  const section=el('section','mock-section'+(s.type==='hero'?' hero':'')+(selected===s.id?' mock-selected':''));
  section.addEventListener('click',()=>{selected=s.id;render()});
  if(s.type==='hero'&&s.data.eyebrow)section.append(el('div','mock-label',s.data.eyebrow));
  if(s.type==='columns'){
   const cols=el('div','mock-columns');
   for(const col of (device==='mobile'?[...s.columns].sort((a,b)=>a.mobileOrder-b.mobileOrder):s.columns)){
    const c=el('div','mock-column');if(device!=='mobile')c.style.gridColumn=`span ${col.widthDesktop}`;for(const b of col.blocks)c.append(leaf(b));cols.append(c)
   }section.append(cols)
  }else section.append(leaf(s));
  host.append(section);
 }
}
function renderHistory(){
 const host=$('#history');host.replaceChildren();
 if(!page.published){host.append(el('p','','No published demo version.'));$('#restore-previous').disabled=true;return}
 host.append(el('p','',`Demo publication v${page.published.version} • ${page.published.actorId}`));
 host.append(el('p','','Older versions remain in in-memory history during this browser tab only.'));
 $('#restore-previous').disabled=false;
}
function render(){renderOutline();renderInspector();renderPreview();renderHistory();$('#editor-status').textContent=status}
$('#add-section').addEventListener('click',()=>{try{add($('#new-section').value)}catch(e){say('Cannot add section: '+e.message)}});
$('#brand-name').addEventListener('input',e=>{brandName=e.target.value;renderPreview();say('Brand name changed in preview only.')});
$('#brand-color').addEventListener('input',e=>{color=e.target.value;renderPreview();say('Theme color changed in preview only.')});
$('#brand-logo').addEventListener('change',e=>{
 const f=e.target.files?.[0];if(!f)return;
 if(!['image/png','image/jpeg','image/webp'].includes(f.type)||f.size>4_000_000){say('Choose a PNG/JPEG/WebP image under 4 MB.');return}
 if(logoUrl)URL.revokeObjectURL(logoUrl);logoUrl=URL.createObjectURL(f);renderPreview();say('Logo changed for this tab only; no upload to server.');
});
for(const b of document.querySelectorAll('[data-device]'))b.addEventListener('click',()=>{device=b.dataset.device;document.querySelectorAll('[data-device]').forEach(x=>x.classList.toggle('active',x===b));renderPreview()});
$('#save-draft').addEventListener('click',()=>say('Draft preview checked in this tab. No server save or account is connected.'));
$('#publish').addEventListener('click',()=>{try{page=publishPreview(page,page.draftRevision,'demo-owner');say(`Published DEMO version v${page.published.version} locally. No public website was changed.`);render()}catch(e){say('Unable to preview publication: '+e.message)}});
$('#restore-previous').addEventListener('click',()=>{if(!page.published)return;const n=JSON.parse(JSON.stringify(page));n.sections=JSON.parse(JSON.stringify(n.published.sections));change(n);say('Last published demo layout copied to draft. Public site unchanged.')});
validatePage(page);render();
