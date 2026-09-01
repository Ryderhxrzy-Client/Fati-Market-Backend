<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials' => env('FCM_CREDENTIALS'),
    ],

    /*
     * Manual GCash settlement. There is no API integration: the buyer scans
     * this static QR, pays, and Admin verifies the proof by hand.
     */
    'gcash' => [
        'account_name' => env('GCASH_ACCOUNT_NAME', 'Ofelia Store'),
        'account_number' => env('GCASH_ACCOUNT_NUMBER'),
        'qr_image_url' => env('GCASH_QR_IMAGE_URL'),
    ],

    /*
     * The OAuth client the Android app signs in with. The ID token it sends is
     * accepted only if Google minted it for this exact client, so this value
     * must be the WEB client ID from the same Google Cloud project as the app's
     * Android client - not the Android client's own ID.
     */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'key' => env('CLOUDINARY_KEY'),
        'secret' => env('CLOUDINARY_SECRET'),
    ],

];
