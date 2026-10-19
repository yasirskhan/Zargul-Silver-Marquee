<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/** Escaped private staff customer-name picker, no protected account fields. */
final class CentralStaffCustomerDirectoryRenderer
{
    /** @param array<int,array{id:string,legal_name:string}> $rows */
    public static function html(array $rows,string $csrf,string $prefix=''):string
    {
        if(count($rows)>20 || strlen($prefix)>64 ||
            preg_match('/^[a-f0-9]{64}$/D',$csrf)!==1) {
            throw new RegistrationError('invalid_customer_directory_response');
        }
        $e=static fn(string $v):string=>
            htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $proof='<input type="hidden" name="staff_csrf" value="'.$e($csrf).'">';
        $listing='';
        foreach($rows as $row) {
            if(!is_string($row['id']??null) ||
               preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
                   $row['id'])!==1 ||
               !is_string($row['legal_name']??null) ||
               strlen($row['legal_name'])>500) {
                throw new RegistrationError('invalid_customer_directory_response');
            }
            $listing.='<li>'.$e($row['legal_name']).
                '<form method="post" action="/staff/dashboard">'.$proof.
                '<input type="hidden" name="customer_id" value="'.$e($row['id']).'">'.
                '<button type="submit">Open workspace</button></form></li>';
        }
        if($listing==='')$listing='<li>No accessible customers match the search.</li>';
        return '<section aria-label="Private HAFZATECH customer directory">'.
            '<h1>Find authorized customers</h1>'.
            '<p>Only customers within your current staff permissions appear. '.
            'Opening an account requires another authorization check.</p>'.
            '<form method="post" action="/staff/customers/directory">'.$proof.
            '<label>Business name prefix <input name="search" maxlength="64" '.
            'value="'.$e($prefix).'"></label>'.
            '<button type="submit">Find customers</button></form>'.
            '<ul>'.$listing.'</ul>'.
            '<form method="post" action="/staff/home">'.$proof.
            '<button type="submit">Back to staff home</button></form>'.
            '</section>';
    }
}
