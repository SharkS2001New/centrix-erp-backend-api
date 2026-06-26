<?php

/**
 * User-facing messages for Comstore / LightStores KRA device errors.
 *
 * Codes are parsed from device responses such as "(Code 314)" or "E337".
 * Comstore middleware codes (3xx) differ from raw OSCU/VSCU codes in the KRA TIS spec (e.g. 34).
 */
return [
    'codes' => [
        // Comstore / middleware — receipt & invoice numbering
        '314' => 'The KRA receipt reference number was rejected (duplicate, invalid, or already used on this device). Try the sale again — a new reference will be assigned automatically.',
        '12' => 'This receipt reference was already used on the KRA device. Try again with a fresh submission.',
        '994' => 'This invoice number was already submitted to KRA. Use Retry on the failed receipt or contact support if the sale was not completed.',
        '921' => 'The KRA device rejected the invoice sequence. Try submitting again.',

        // Product / PLU
        '337' => 'One or more products were not found on the KRA device. Upload or register the products on the device first, then retry.',
        '13' => 'A product on this sale is not registered on the KRA device. Register the product first.',
        '894' => 'A product has an invalid tax classification for KRA. Check the product setup and re-upload it to the device.',

        // Device registration & connectivity
        '901' => 'This KRA device serial number is not approved or registered with KRA. Check Finance settings and device registration.',
        '902' => 'The KRA device is already initialized. Use the existing device configuration.',
        '903' => 'The KRA device could not be initialized. Check that the device is online and correctly configured.',
        '96' => 'Could not reach the KRA device. Check that the device is powered on and on the same network.',
        '90' => 'The KRA device has no internet connection. Connect the device to the internet and try again.',

        // PIN / buyer
        '880' => 'The customer KRA PIN is invalid. Correct the buyer PIN and retry.',
        '10' => 'The trader KRA PIN configured on the device is invalid. Check Finance / KRA settings.',
        '32' => 'The KRA PIN in the request is wrong. Verify the organization PIN on the device.',

        // KRA TIS / OSCU-VSCU spec (when returned directly)
        '34' => 'The receipt number sent to KRA is invalid. The system will assign a new number on retry.',
        '31' => 'The data sent to the KRA device was in the wrong format. Contact support if this persists.',
        '40' => 'The KRA control unit is not activated. Complete device activation with your KRA supplier.',
        '41' => 'The KRA control unit is already activated.',
        '99' => 'The KRA device needs hardware service. Contact your TIMS / Comstore supplier.',

        // TIMS web service (middleware invoice already received)
        '002' => 'This invoice was already received by KRA. If the sale did not complete, use Retry or issue a credit note for the duplicate.',
    ],

    'patterns' => [
        '/receiptNo\s+is\s+error/i' => 'The KRA receipt reference number was rejected (duplicate, invalid, or already used on this device). Try the sale again — a new reference will be assigned automatically.',
        '/NO\s+FIND\s+PLU\s+DATA/i' => 'One or more products were not found on the KRA device. Upload or register the products on the device first, then retry.',
        '/PluItems.*required/i' => 'Product registration failed: the KRA device did not receive the product list. Try uploading products again.',
        '/Signature generation failed/i' => 'KRA could not sign this receipt. Check the receipt reference number and that all products are registered on the device.',
        '/Could not reach KRA device/i' => 'Could not reach the KRA device. Check that it is powered on, on the network, and the IP address in settings is correct.',
        '/cURL error|Connection refused|timed out/i' => 'Could not connect to the KRA device. Check network connectivity and the device IP in Finance settings.',
    ],

    'fallback' => 'KRA device rejected the request. Check the device connection and product registration, then try again.',
];
