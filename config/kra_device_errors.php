<?php

/**
 * User-facing messages for Comstore / LightStores KRA device errors.
 *
 * Sourced from Comstore API Documentation 2.1 (pages 31–36) plus common middleware codes.
 * Codes are parsed from responses such as "(Code 314)", "E337", or "E034".
 */
return [
    'codes' => [
        // Duplicate invoice (Comstore workflow)
        '12' => 'This receipt reference was already used on the KRA device. Try again — a new reference will be assigned automatically.',
        '994' => 'This invoice number was already submitted to KRA. Use Retry on the failed receipt if the sale did not complete.',
        '921' => 'The KRA device rejected the invoice sequence. Try submitting again.',

        // PIN errors (Comstore PDF)
        '325' => 'The shop KRA PIN is wrong or does not match this device. Check Finance settings and use the PIN assigned to the fiscal device.',
        '358' => 'The customer KRA PIN is invalid or in the wrong format. Correct the buyer PIN and retry.',

        // Credit note validation (Comstore PDF)
        '312' => 'The invoice type sent to KRA is incorrect. Contact support if this persists.',
        '313' => 'The original invoice reference for this credit note is missing or invalid. Use the CU invoice number from the original sale.',
        '314' => 'The receipt or invoice reference was rejected (duplicate, wrong format, or not valid for this credit note). Try again or verify the original invoice number.',
        '315' => 'The invoice type is invalid for this KRA submission. Contact support if this persists.',
        '331' => 'The VAT amount on this credit note exceeds the VAT on the original invoice. Reduce the return amount or check line VAT.',
        '332' => 'The price on this credit note exceeds the price on the original invoice line. Match the original sale prices.',
        '333' => 'The quantity on this credit note exceeds the quantity on the original invoice. Reduce the return quantity.',
        '334' => 'This invoice has already been fully credited on the KRA device. Check existing credit notes before retrying.',
        '335' => 'The credit note total exceeds the original invoice total. Reduce the return amount.',

        // Amount / tax / PLU on sale (Comstore PDF)
        '321' => 'The tax rate on a line item does not match how that product is registered on the KRA device. Re-upload the product with the correct tax type.',
        '322' => 'A line sale amount is calculated incorrectly. Check quantities, prices, and discounts.',
        '337' => 'One or more products were not found on the KRA device. Upload products to the device first, then retry.',
        '341' => 'Line amounts do not match the invoice totals, or VAT fields were filled for the wrong tax bracket. Check product tax types and amounts.',
        '351' => 'Insufficient stock on the KRA device for one or more items. Increase change_qty when uploading the product PLU.',

        // PLU upload (Comstore PDF)
        '034' => 'Product upload data is invalid. Check product names (English characters only), prices, and classification codes.',
        '353' => 'A product with this name is already registered on the KRA device. Use a unique product name or update the existing PLU.',

        // Legacy / middleware aliases still seen in the field
        '13' => 'A product on this sale is not registered on the KRA device. Register the product first.',
        '894' => 'A product has an invalid tax classification for KRA. Check the product setup and re-upload it to the device.',

        // Device registration & connectivity
        '901' => 'This KRA device serial number is not approved or registered with KRA. Check Finance settings and device registration.',
        '902' => 'The KRA device is already initialized. Use the existing device configuration.',
        '903' => 'The KRA device could not be initialized. Check hardware IP, serial number, and that the device is online.',
        '96' => 'Could not reach the KRA device. Check that it is powered on and on the same network.',
        '90' => 'The KRA device has no internet connection. Connect the device to the internet and try again.',

        // PIN / buyer (legacy codes)
        '880' => 'The customer KRA PIN is invalid. Correct the buyer PIN and retry.',
        '10' => 'The trader KRA PIN configured on the device is invalid. Check Finance / KRA settings.',
        '32' => 'The KRA PIN in the request is wrong. Verify the organization PIN on the device.',

        // KRA TIS / OSCU-VSCU spec (when returned directly)
        '34' => 'The receipt number sent to KRA is invalid. The system will assign a new number on retry.',
        '31' => 'The data sent to the KRA device was in the wrong format. Contact support if this persists.',
        '40' => 'The KRA control unit is not activated. Complete device activation with your KRA supplier.',
        '41' => 'The KRA control unit is already activated.',
        '99' => 'The KRA device needs hardware service. Contact your TIMS / Comstore supplier.',

        // TIMS web service
        '002' => 'This invoice was already received by KRA. If the sale did not complete, use Retry or issue a credit note for the duplicate.',
    ],

    'patterns' => [
        '/duplicate\s+invoice/i' => 'This receipt reference was already used on the KRA device. Try again — a new reference will be assigned automatically.',
        '/receiptNo\s+is\s+error/i' => 'The receipt or invoice reference was rejected. For credit notes, verify the original CU invoice number. For sales, try again.',
        '/relevantInvoiceNumber\s+is\s+error/i' => 'The original invoice reference for this credit note is missing or invalid. Use the CU invoice number from the original sale.',
        '/NO\s+FIND\s+PLU\s+DATA/i' => 'One or more products were not found on the KRA device. Upload or register the products on the device first, then retry.',
        '/Pinofshop\s+is\s+error/i' => 'The shop KRA PIN is wrong or does not match this device. Check Finance settings.',
        '/Pinofbuyer\s+is\s+error/i' => 'The customer KRA PIN is invalid or in the wrong format. Correct the buyer PIN and retry.',
        '/PLU\s+SALES\s+SUM\s+ERROR/i' => 'Line amounts do not match invoice totals, or VAT was applied to the wrong tax bracket. Check amounts and product tax types.',
        '/Insufficient\s+Stock/i' => 'Insufficient stock on the KRA device for one or more items. Re-upload the product with a higher change_qty.',
        '/THE\s+SAME\s+NAME/i' => 'A product with this name is already on the KRA device. Use a unique name or update the existing PLU.',
        '/SaleAmount\s+is\s+error/i' => 'A line sale amount is calculated incorrectly. Check quantities, prices, and discounts.',
        '/taxrate\s+is\s+error/i' => 'The tax rate on a line does not match the product registration on the KRA device. Re-upload the product with the correct tax type.',
        '/already\s+credited/i' => 'This invoice has already been fully credited on the KRA device.',
        '/PluItems.*required/i' => 'Product registration failed: the KRA device did not receive the product list. Try uploading products again.',
        '/SetPluDataInfoEX failed|Error processing PLU data/i' => 'Product upload data is invalid. Check names (English only), prices, and classification codes.',
        '/Signature generation failed/i' => 'KRA could not sign this receipt. Check the receipt reference, product registration, and amounts.',
        '/Device not initialized/i' => 'The fiscal device is not initialized. Use Initialize device in Finance settings or configure it in Comstore desktop.',
        '/deviceConnection.*Disconnected/i' => 'Comstore API is reachable but the fiscal hardware is disconnected. Check power and network to the Smart VSCU device.',
        '/Could not reach KRA device/i' => 'Could not reach the KRA device. Check that it is powered on, on the network, and the URL in settings is correct.',
        '/cURL error|Connection refused|timed out/i' => 'Could not connect to the KRA device. Check network connectivity and the device URL in Finance settings.',
    ],

    'fallback' => 'KRA device rejected the request. Check the device connection and product registration, then try again.',
];
