<?php
return [
    'decrypt_totp' => static fn(string $sealed): string => $sealed,
    'encrypt_support_reply' => static fn(string $clear): string => $clear,
];