<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'github' => [
        'app_id' => env('GITHUB_APP_ID'),
        'app_slug' => env('GITHUB_APP_SLUG', 'dev-portal-app'),
        'private_key' => env('GITHUB_APP_PRIVATE_KEY'),
        'webhook_secret' => env('GITHUB_APP_WEBHOOK_SECRET'),
        'api_version' => env('GITHUB_API_VERSION', '2026-03-10'),
    ],

    'vault' => [
        'url' => env('VAULT_ADDR'),
        'token' => env('VAULT_TOKEN'),
        'namespace' => env('VAULT_NAMESPACE'),
        'mount' => env('VAULT_KV_MOUNT', 'secret'),
        'verify_tls' => env('VAULT_VERIFY_TLS', true),
        'connect_timeout' => env('VAULT_CONNECT_TIMEOUT', 3),
        'timeout' => env('VAULT_TIMEOUT', 10),
    ],

    'kubernetes' => [
        'url' => env('KUBERNETES_API_URL'),
        'token' => env('KUBERNETES_API_TOKEN'),
        'ca_cert' => env('KUBERNETES_CA_CERT'),
        'verify_tls' => env('KUBERNETES_VERIFY_TLS', true),
        'connect_timeout' => env('KUBERNETES_CONNECT_TIMEOUT', 3),
        'timeout' => env('KUBERNETES_TIMEOUT', 10),
        'external_secrets_api_version' => env('EXTERNAL_SECRETS_API_VERSION', 'v1'),
    ],

    'argocd' => [
        'url' => env('ARGOCD_URL'),
        'token' => env('ARGOCD_TOKEN'),
        'namespace' => env('ARGOCD_NAMESPACE', 'argocd'),
        'project' => env('ARGOCD_PROJECT', 'default'),
        'destination_server' => env('ARGOCD_DESTINATION_SERVER', 'https://kubernetes.default.svc'),
        'auto_prune' => env('ARGOCD_AUTO_PRUNE', true),
        'self_heal' => env('ARGOCD_SELF_HEAL', true),
        'verify_tls' => env('ARGOCD_VERIFY_TLS', true),
        'connect_timeout' => env('ARGOCD_CONNECT_TIMEOUT', 3),
        'timeout' => env('ARGOCD_TIMEOUT', 10),
    ],
];
