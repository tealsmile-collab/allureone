<?php
/**
 * Gallabox WhatsApp credentials (deployable — do not rely on gitignored config.php alone).
 * Source of truth aligned with api/refer.txt
 */
declare(strict_types=1);

return [
    'api_url'        => 'https://server.gallabox.com/devapi/messages/whatsapp',
    'api_key'        => '6943d160bdb748e645cb887e',
    'api_secret'     => '002bdbfa12fb47ddb5d927bf6dfcc2d5',
    'channel_id'     => '68ad971bb42a9aef088df331',
    'template'       => 'meta_lead',
    'buyer_template' => 'allure_deal_confirmation',
    'default_phone'  => '918369676845',
    'default_name'   => 'Shailesh',
];
