// Phase 0 pure-function CMS proof of concept: NOT an authenticated or persistent page editor.
// Production publication must use database revisions, backend authorization, transactions,
// optimistic version checks, media policy and append-only audit as specified in docs.
export const PAGE_BLOCKS=Object.freeze({
  hero:['eyebrow','title','subtitle','buttonLabel','buttonHref','imageId'],
  text:['title','body'],
  image:['imageId','alt','caption'],
  gallery:['title','mediaIds'],
  halls:['title','description'],
  packages:['title','description'],
  services:['title','description'],
  cta:['title','body','buttonLabel','buttonHref'],
  enquiry:['title','description'],
  columns:[],
  faq:['title','description']
});
const isObject=x=>!!x&&typeof x==='object'&&!Array.isArray(x);
const copy=x=>JSON.parse(JSON.stringify(x));
const MAX_SECTIONS=60;
const badText=x=>/\<\s*script\b|javascript\s*:|\bon\w+\s*=/i.test(x);
const assert=(truth,reason)=>{if(!truth)throw new Error(reason)};
function validateData(type,data){
  assert(isObject(data),`Invalid ${type} data`);
  for(const [key,value] of Object.entries(data)){
    assert(PAGE_BLOCKS[type].includes(key),`Unexpected editable field ${key} on ${type}`);
    if(key==='mediaIds'){
      assert(Array.isArray(value)&&value.length<=50&&value.every(v=>typeof v==='string'&&v.length<=128), 'Invalid gallery media list');
    }else{
      assert(typeof value==='string'&&value.length<=5000&&!badText(value),`Unsafe or invalid content field ${key}`);
      if(key==='buttonHref')assert(value.startsWith('/')&&!value.startsWith('//')||value.startsWith('#')||/^https:\/\/[^\s]+$/.test(value), 'Unsafe link target');
    }
  }
}
export function validatePage(page){
 assert(isObject(page)&&typeof page.id==='string'&&page.id.length>0&&page.id.length<=128,'Invalid page ID');
 assert(typeof page.slug==='string'&&/^\/(?:[a-z0-9-]+\/?)*$/.test(page.slug),'Invalid page path');
 assert(Array.isArray(page.sections)&&page.sections.length<=MAX_SECTIONS,'Invalid page sections');
 const ids=new Set();
 function block(item,depth){
  assert(isObject(item)&&typeof item.id==='string'&&/^[a-zA-Z0-9_-]{1,80}$/.test(item.id),'Invalid block id');
  assert(!ids.has(item.id),'Duplicate block id');ids.add(item.id);
  assert(Object.hasOwn(PAGE_BLOCKS,item.type),'Unsupported block type');
  validateData(item.type,item.data??{});
  assert(depth<=2,'Too many nested blocks');
  const allowed=item.type==='columns'?['id','type','data','columns']:['id','type','data'];
  assert(Object.keys(item).every(k=>allowed.includes(k)), 'Unknown block property');
  if(item.type==='columns'){
   assert(Array.isArray(item.columns)&&item.columns.length>=1&&item.columns.length<=3,'Column count must be 1 to 3');
   let sum=0;const positions=new Set();
   for(const col of item.columns){
    assert(isObject(col)&&Object.keys(col).every(k=>['id','widthDesktop','mobileOrder','blocks'].includes(k)), 'Invalid column property');
    assert(typeof col.id==='string'&&/^[a-zA-Z0-9_-]{1,80}$/.test(col.id)&&!ids.has(col.id),'Invalid column ID');
    ids.add(col.id);
    assert(Number.isInteger(col.widthDesktop)&&col.widthDesktop>=1&&col.widthDesktop<=12,'Invalid desktop width');
    sum+=col.widthDesktop;
    assert(Number.isInteger(col.mobileOrder)&&col.mobileOrder>=1&&col.mobileOrder<=item.columns.length&&!positions.has(col.mobileOrder),'Invalid mobile order');
    positions.add(col.mobileOrder);
    assert(Array.isArray(col.blocks)&&col.blocks.length<=20,'Invalid column content');
    col.blocks.forEach(b=>block(b,depth+1));
   }
   assert(sum===12,'Desktop columns must total 12 units');
  }
 }
 page.sections.forEach(s=>block(s,0));
 return true;
}
function at(page,from,to,length){
 validatePage(page);
 assert(Number.isInteger(from)&&Number.isInteger(to)&&from>=0&&to>=0&&from<length&&to<length,'Invalid reorder positions');
 return copy(page);
}
export function moveSection(page,from,to){
 const next=at(page,from,to,page.sections.length);
 next.sections.splice(to,0,next.sections.splice(from,1)[0]);
 validatePage(next);return next;
}
export function moveColumn(page,sectionId,from,to){
 validatePage(page);const n=copy(page),section=n.sections.find(s=>s.id===sectionId);
 assert(section?.type==='columns','Unknown column section');
 assert(Number.isInteger(from)&&Number.isInteger(to)&&from>=0&&to>=0&&from<section.columns.length&&to<section.columns.length,'Invalid column move');
 section.columns.splice(to,0,section.columns.splice(from,1)[0]);
 validatePage(n);return n;
}
export function moveMobileColumn(page,sectionId,from,to){
 validatePage(page);const n=copy(page),section=n.sections.find(s=>s.id===sectionId);
 assert(section?.type==='columns','Unknown column section');
 const ordered=[...section.columns].sort((a,b)=>a.mobileOrder-b.mobileOrder);
 assert(Number.isInteger(from)&&Number.isInteger(to)&&from>=0&&to>=0&&from<ordered.length&&to<ordered.length,'Invalid mobile column move');
 ordered.splice(to,0,ordered.splice(from,1)[0]);
 ordered.forEach((col,i)=>{section.columns.find(c=>c.id===col.id).mobileOrder=i+1});
 validatePage(n);return n;
}
export function updateText(page,blockId,field,value){
 validatePage(page);assert(typeof value==='string','Text must be string');const n=copy(page);let target;
 function find(blocks){
  for(const b of blocks){
   if(b.id===blockId){target=b;return}
   if(b.type==='columns')for(const c of b.columns){find(c.blocks);if(target)return}
  }
 }
 find(n.sections);assert(!!target,'Block not found');
 assert(PAGE_BLOCKS[target.type].includes(field)&&field!=='mediaIds','Field is not editable text');
 target.data[field]=value;
 validatePage(n);return n;
}
export function publishPreview(page,expectedRevision,actorId){
 validatePage(page);assert(page.draftRevision===expectedRevision,'Draft was updated by another editor');
 assert(typeof actorId==='string'&&actorId.trim(),'Publisher identity required');
 const next=copy(page),number=(next.published?.version||0)+1;
 const snapshot={version:number,sections:copy(next.sections),actorId};
 next.history=[...(next.history||[]),snapshot];
 next.published=copy(snapshot);
 return next;
}
export const SAMPLE_PAGE=Object.freeze({
 id:'home',slug:'/',draftRevision:1,
 sections:[
  {id:'hero_1',type:'hero',data:{eyebrow:'Zargul Silver Marquee',title:'Celebrate your story',subtitle:'Beautiful venue celebrations',buttonLabel:'Plan your event',buttonHref:'#enquiry',imageId:'approved-media-001'}},
  {id:'split_1',type:'columns',data:{},columns:[
   {id:'col_left',widthDesktop:5,mobileOrder:2,blocks:[{id:'photo_1',type:'image',data:{imageId:'approved-media-002',alt:'Venue interior'}}]},
   {id:'col_right',widthDesktop:7,mobileOrder:1,blocks:[{id:'copy_1',type:'text',data:{title:'Our halls',body:'Explore the venue.'}}]}
  ]},
  {id:'enquiry_1',type:'enquiry',data:{title:'Check an event date',description:'Ask our staff to confirm availability'}}
 ]
});
