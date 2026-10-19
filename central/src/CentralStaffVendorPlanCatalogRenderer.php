<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/**
 * Private operator-only plan form and allowlisted publication receipt.
 * No customer login, bank account, signed license or payment processing.
 */
final class CentralStaffVendorPlanCatalogRenderer
{
    public static function form(string $csrf,array $rows=[]):string
    {
        if(preg_match('/^[a-f0-9]{64}$/D',$csrf)!==1) {
            throw new RegistrationError('invalid_vendor_plan_response');
        }
        $b=random_bytes(16);
        $b[6]=chr((ord($b[6])&15)|64);
        $b[8]=chr((ord($b[8])&63)|128);
        $hex=bin2hex($b);
        $id=substr($hex,0,8).'-'.substr($hex,8,4).'-'.
            substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
        $safe=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8');
        if(count($rows)>25)throw new RegistrationError('invalid_vendor_plan_response');
        $escape=static fn(mixed $value):string=>htmlspecialchars(
            is_scalar($value)?(string)$value:'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $history='';
        foreach($rows as $row){
            $history.='<tr><td>'.$escape($row['plan_code']??'').'</td>'.
                '<td>'.$escape($row['version']??'').'</td>'.
                '<td>'.$escape($row['term_months']??'').' months</td>'.
                '<td>'.$escape($row['currency']??'').' '.
                $escape($row['price_minor']??'').' minor units</td>'.
                '<td>'.$escape($row['created_at']??'').'</td></tr>';
        }
        if($history===''){
            $history='<tr><td colspan="5">No published versions found.</td></tr>';
        }
        $history='<h3>Recent immutable plan versions</h3><table><thead>'.
            '<tr><th>Plan</th><th>Version</th><th>Term</th>'.
            '<th>Price</th><th>Published</th></tr></thead>'.
            '<tbody>'.$history.'</tbody></table>';
        return '<section aria-label="Private HAFZATECH plan catalog">'.
            '<h2>Publish a versioned VenueDesk subscription plan</h2>'.
            '<p>Plan changes create NEW immutable versions. They never verify funds, '.
            'activate customers or issue signed licenses.</p>'.
            '<form method="post" action="/staff/plans/publish">'.
            '<input type="hidden" name="staff_csrf" value="'.$safe.'">'.
            '<input type="hidden" name="request_id" value="'.$id.'">'.
            '<label>Plan code <input name="plan_code" pattern="[a-z][a-z0-9_]{2,63}" required></label>'.
            '<label>Term <select name="term_months"><option value="6">6 months</option>'.
            '<option value="12">12 months</option></select></label>'.
            '<label>Currency ISO 4217 <input name="currency" value="USD" '.
            'maxlength="3" required></label>'.
            '<label>Price in minor units <input name="price_minor" '.
            'type="text" pattern="[1-9][0-9]*" required></label>'.
            '<label>Maximum halls <input name="max_halls" type="number" min="1" '.
            'max="100000" value="1" required></label>'.
            '<label>Maximum staff <input name="max_staff" type="number" min="1" '.
            'max="100000" value="10" required></label>'.
            '<label>Feature flags JSON <textarea name="features_json" maxlength="2048" '.
            'required>{"booking":true}</textarea></label>'.
            '<button type="submit">Publish next plan version</button>'.
            '</form>'.$history.'</section>';
    }

    /** @param array<string,mixed> $row */
    public static function receipt(array $row):string
    {
        $expected=['currency','plan_version_id','price_minor','term_months','version'];
        $actual=array_keys($row);sort($actual,SORT_STRING);
        if($actual!==$expected ||
            !is_string($row['plan_version_id']) ||
            preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
                $row['plan_version_id'])!==1 ||
            !is_int($row['version']) || $row['version']<1 ||
            !in_array($row['term_months'],[6,12],true) ||
            !is_string($row['currency']) ||
            preg_match('/^[A-Z]{3}$/D',$row['currency'])!==1 ||
            !is_string($row['price_minor']) ||
            preg_match('/^[1-9][0-9]{0,15}$/D',$row['price_minor'])!==1) {
            throw new RegistrationError('invalid_vendor_plan_response');
        }
        $e=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES,'UTF-8');
        return '<section aria-label="Private HAFZATECH plan publication receipt">'.
            '<h2>Plan version published</h2>'.
            '<p>Version '.$row['version'].' / '.$row['term_months'].' months</p>'.
            '<p>Catalog ID '.$e($row['plan_version_id']).'</p>'.
            '<p>Price '.$e($row['currency']).' '.$e($row['price_minor']).
            ' minor units</p>'.
            '<p>This does not invoice customers, verify funds, change existing '.
            'paid entitlements or sign license grants.</p></section>';
    }
}
