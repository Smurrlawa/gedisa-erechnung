<?php

declare(strict_types=1);

return [
    'seller' => [
        'name' => env('ERECHNUNG_SELLER_NAME', 'GEDISA - Gesellschaft für digitale Services der Apotheken mbH'),
        'street' => env('ERECHNUNG_SELLER_STREET', 'Yorckstraße 22 - 24'),
        'postcode' => env('ERECHNUNG_SELLER_POSTCODE', '14467'),
        'city' => env('ERECHNUNG_SELLER_CITY', 'Potsdam'),
        'country' => env('ERECHNUNG_SELLER_COUNTRY', 'DE'),
        'vat_id' => env('ERECHNUNG_SELLER_VAT_ID', 'DE350031105'),
        'email' => env('ERECHNUNG_SELLER_EMAIL', 'rechnung@gedisa.de'),
        'phone' => env('ERECHNUNG_SELLER_PHONE', '+493062937755'),
        'creditor_id' => env('ERECHNUNG_SELLER_CREDITOR_ID', 'TODO'),
    ],
];
