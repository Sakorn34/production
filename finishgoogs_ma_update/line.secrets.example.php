<?php

/**

 * ตัวอย่าง line.secrets.php — คัดลอกไป D:/AppServ/secrets/production/line.secrets.php

 */

return [

    'channel_access_token'  => '',

    'channel_secret'        => '',

    'default_recipient_id'  => '',

    'enabled'               => false,

    'public_production_url' => '',

    'public_parts_url'      => '',

    'events'                => [

        'production.problem_found'        => true,

        'stock.low_threshold'             => true,

        'ma.repair_required'              => true,

        'stock.manual_withdraw'           => true,

        'production.summary.daily'        => true,

        'production.summary.daily_update' => true,

        'production.summary.weekly'       => true,

        'production.summary.monthly'      => true,

    ],

    'schedules' => [

        'stock.low_threshold'             => ['time' => '08:00', 'weekday' => 0, 'type' => 'daily'],

        'production.summary.daily'        => ['time' => '17:30', 'weekday' => 0, 'type' => 'daily'],

        'production.summary.daily_update' => ['time' => '18:00', 'weekday' => 0, 'type' => 'daily'],

        'production.summary.weekly'     => ['time' => '17:30', 'weekday' => 0, 'type' => 'weekly'],

        'production.summary.monthly'      => ['time' => '17:35', 'weekday' => 0, 'type' => 'monthly_last_day'],

    ],

];


