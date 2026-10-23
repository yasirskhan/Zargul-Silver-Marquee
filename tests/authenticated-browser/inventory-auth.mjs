import {expect} from '@playwright/test';
import {createHmac} from 'node:crypto';
function otp(secret){
 const abc='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';let bits=0,num=0,b=[];
 for(const c of secret){num=(num<<5)|abc.indexOf(c);bits+=5;
 if(bits>=8){bits-=8;b.push((num>>>bits)&255);}}
 const counter=Buffer.alloc(8);counter.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));
 const hash=createHmac('sha1',Buffer.from(b)).update(counter).digest();
 return String((hash.readUInt32BE(hash[19]&15)&0x7fffffff)%1000000).padStart(6,'0');
}
function isAuthenticated(url){
 const p=new URL(url).pathname;
 return p.startsWith('/admin')&& !['/admin/login','/admin/multi-factor-authentication','/admin/password-reset']
 .some(x=>p.startsWith(x));
}
export async function inventoryLogin(browser,email,password,secret,viewport){
 const ctx=await browser.newContext({viewport});
 const page=await ctx.newPage();
 await page.goto('/admin/login');
 await page.waitForFunction(()=>Boolean(window.Livewire?.all?.().some(c=>c.el?.querySelector?.('input[type="email"]'))),null,{timeout:30000});
 await page.locator('input[type="email"]').fill(email);
 await page.getByLabel(/Password/).fill(password);
 await page.getByRole('button',{name:/sign in|log in/i}).first().click();
 const field=page.locator('input[autocomplete="one-time-code"]:visible,input[id*="code"]:visible,input[name*="code"]:visible,input[inputmode="numeric"]:visible').first();
 await expect(field).toBeVisible({timeout:30000});
 // Do not start a real TOTP submission at the end of its 30-second validity window.
 const remaining=30000-(Date.now()%30000);
 if(remaining<10000)await page.waitForTimeout(remaining+350);
 await field.fill(otp(secret));
 if(!isAuthenticated(page.url())){
   // Filament sometimes auto-submits. Check briefly, then press the real MFA
   // button while the code is still valid instead of waiting 30 seconds.
   // Wait for the real Livewire auto-submission to finish before attempting
   // a second MFA POST. The old 1.2 s wait could race an in-flight request,
   // reuse its one-time code, and leave the session at an MFA challenge.
   try {
     await page.waitForURL(isAuthenticated,{timeout:30000});
   } catch {
     if(!isAuthenticated(page.url())){
       const verify=page.getByRole('button',{name:/confirm sign in|verify|continue|sign in/i}).last();
       // Only submit manually when the server still presents a genuine MFA
       // verification action. Never replace MFA with a session shortcut.
       try {
         if(await verify.isVisible()){
           await expect(verify).toBeEnabled({timeout:10000});
           if(!isAuthenticated(page.url()))await verify.click();
         } else if(!isAuthenticated(page.url())){
           throw new Error('Real MFA did not redirect to an authenticated panel. Current page: '+new URL(page.url()).pathname);
         }
       } catch(error) {
         // Navigating away removes the challenge button. That is successful
         // only if the real authenticated panel is already available.
         if(!isAuthenticated(page.url()))throw error;
       }
       await page.waitForURL(isAuthenticated,{timeout:30000});
     }
   }
 }
 await page.goto('/admin/hall-inventory');
 await expect(page.locator('[aria-label="Inventory workflow navigator"]')).toBeVisible({timeout:30000});
 await page.waitForFunction(()=>Boolean(window.Livewire?.all?.().length),null,{timeout:30000});
 const dialog=page.getByRole('dialog',{name:'Pending owner approval requests'});
 if(await dialog.isVisible())await dialog.getByRole('button',{name:'Dismiss for this session'}).click();
 return {ctx,page};
}
export async function inventoryFocus(page,name){
 await page.locator('[aria-label="Inventory tasks"]').getByRole('button',{name,exact:true}).click();
}
export const syntheticEvidence={
 name:'witness.pdf',mimeType:'application/pdf',
 buffer:Buffer.from('%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF')
};
