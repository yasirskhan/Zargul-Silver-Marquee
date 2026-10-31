<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/** Escape-only private operator form for signed designer-credit settings. */
final class CentralStaffDesignerCreditRenderer
{
    public static function form(string $licenseId,string $csrf,
        string $requestId,array $history=[],string $notice=''): string
    {
        $e=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $latest=$history[0]['credit']??['enabled'=>false,'prefix'=>'','label'=>'',
            'url'=>'','alignment'=>'center'];
        $selected=static fn(string $v,string $current):string=>$v===$current?' selected':'';
        $checked=($latest['enabled']??false)?' checked':'';
        $rows='';
        foreach ($history as $row) {
            $rows.='<tr><td>'.$e((string)$row['version']).'</td><td>'
                .$e((string)$row['created_at']).'</td><td>'
                .($row['credit']['enabled']?'enabled':'disabled').'</td><td>'
                .$e((string)$row['credit']['label']).'</td></tr>';
        }
        return '<!doctype html><html><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Designer credit</title></head><body><main>'
            .'<h1>Designer credit</h1>'
            .($notice!==''?'<p role="status">'.$e($notice).'</p>':'')
            .'<form method="post" action="/staff/licenses/designer-credit/configure">'
            .'<input type="hidden" name="staff_csrf" value="'.$e($csrf).'">'
            .'<input type="hidden" name="license_id" value="'.$e($licenseId).'">'
            .'<input type="hidden" name="request_id" value="'.$e($requestId).'">'
            .'<p><label><input type="checkbox" name="enabled" value="1"'.$checked.'> Enabled</label></p>'
            .'<p><label>Prefix <input name="prefix" maxlength="40" value="'.$e((string)$latest['prefix']).'"></label></p>'
            .'<p><label>Label <input name="label" maxlength="120" value="'.$e((string)$latest['label']).'"></label></p>'
            .'<p><label>HTTPS URL <input type="url" name="url" maxlength="500" value="'.$e((string)$latest['url']).'"></label></p>'
            .'<p><label>Alignment <select name="alignment">'
            .'<option value="left"'.$selected('left',(string)$latest['alignment']).'>Left</option>'
            .'<option value="center"'.$selected('center',(string)$latest['alignment']).'>Center</option>'
            .'<option value="right"'.$selected('right',(string)$latest['alignment']).'>Right</option>'
            .'</select></label></p>'
            .'<button type="submit">Save signed credit settings</button></form>'
            .'<h2>Immutable version history</h2><table><thead><tr><th>Version</th>'
            .'<th>Created</th><th>State</th><th>Label</th></tr></thead><tbody>'
            .($rows!==''?$rows:'<tr><td colspan="4">No credit configured.</td></tr>')
            .'</tbody></table></main></body></html>';
    }
}