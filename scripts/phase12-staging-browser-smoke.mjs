#!/usr/bin/env node
/**
 * Read-only Phase 12 desktop/mobile smoke capture against an AUTHORIZED
 * staging HTTPS deployment. Never stores credentials, bypasses MFA, writes
 * inventory or assumes a green result if the staging site is unavailable.
 *
 * Operator creates short-lived Playwright storageState JSON OUTSIDE repository
 * using genuine manual login + MFA. Cookie-bearing files are secrets.
 */
import { chromium } from '@playwright/test';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';

const url = process.env.PHASE12_STAGING_URL;
const states = [
  ['owner', process.env.PHASE12_OWNER_STORAGE_STATE],
  ['manager-a', process.env.PHASE12_MANAGER_A_STORAGE_STATE],
  ['manager-b', process.env.PHASE12_MANAGER_B_STORAGE_STATE]
];
const out = process.env.PHASE12_CAPTURE_DIR;
const hostname = url ? new URL(url).hostname : '';
if (!url || !url.startsWith('https://') || !/(stage|staging|uat|test)/i.test(hostname)
    || !out || states.some(([,state])=>!state)) {
  process.stderr.write('Refused: controlled HTTPS staging/test URL, 3 private MFA-authenticated storageState paths and private capture directory required.\n');
  process.exit(2);
}

const captured = [];
await mkdir(out,{recursive:true,mode:0o700});
const browser = await chromium.launch({headless:true});
try {
  for(const [role,storageState] of states) {
    for(const [mode,viewport,isMobile] of [
      ['desktop',{width:1366,height:768},false],
      ['mobile',{width:390,height:844},true]
    ]) {
      const context = await browser.newContext({
        viewport, isMobile, deviceScaleFactor:1,
        storageState, ignoreHTTPSErrors:false
      });
      const page = await context.newPage();
      const record = {
        role, mode, expected:'Authenticated, scoped Hall inventory page, catalog and loan workflow controls',
        outcome:'NOT RUN', screenshot:null, timestamp:new Date().toISOString()
      };
      try {
        const response=await page.goto(new URL('/admin/hall-inventory',url).toString(),
          {waitUntil:'domcontentloaded',timeout:30000});
        if(!response || response.status()>=400)
          throw new Error('HTTP error '+(response?.status()??'no response'));
        await page.getByRole('heading',{name:'Item catalog for selected hall'}).waitFor({timeout:20000});
        await page.getByRole('heading',{name:'Borrow / lend multiple inventory items'}).waitFor({timeout:20000});
        const name='phase12-'+role+'-'+mode+'.png';
        await page.screenshot({path:path.join(out,name),fullPage:true,animations:'disabled'});
        record.screenshot=name;
        record.outcome='PASSED READ-ONLY UI SMOKE';
      } catch(e) {
        record.outcome='FAILED READ-ONLY UI SMOKE';
        record.error=String(e?.message||e).slice(0,400);
      } finally {
        captured.push(record);
        await context.close();
      }
    }
  }
} finally {
  await browser.close();
}
await writeFile(path.join(out,'phase12-smoke-results.json'),
  JSON.stringify({
    kind:'READ_ONLY_AUTHENTICATED_UI_SMOKE_NOT_FULL_UAT',
    deployedSha:process.env.PHASE12_DEPLOYED_SHA||'UNKNOWN',
    targetOrigin:new URL(url).origin,
    captures:captured
  },null,2)+'\n',{mode:0o600});
process.stdout.write('Read-only staging capture manifest written to private output directory.\n');
if(captured.some(item=>item.outcome!=='PASSED READ-ONLY UI SMOKE'))process.exitCode=1;
