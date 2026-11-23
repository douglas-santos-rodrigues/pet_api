<?php

return [
    'client_url' => env('PET_CLIENT_URL', env('APP_URL', 'http://localhost:8000')),
    'google_client_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('GOOGLE_CLIENT_IDS', ''))))),
    'storage_disk' => env('PET_STORAGE_DISK', 'local'),
    'upload_max_kib' => (int) env('PET_UPLOAD_MAX_KIB', 10240),
    'public_attachments' => (bool) env('PET_PUBLIC_ATTACHMENTS', false),
    'scanner' => env('PET_SCANNER_BIN'),
    'push_enabled' => (bool) env('PET_PUSH_ENABLED', false),
    'push_access_token' => env('PET_EXPO_PUSH_ACCESS_TOKEN'),
    'integration_company_limit' => (int) env('PET_INTEGRATION_COMPANY_LIMIT', 60),
    'integration_ip_limit' => (int) env('PET_INTEGRATION_IP_LIMIT', 180),
];
