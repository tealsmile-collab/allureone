<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if ((int) ($user['role_id'] ?? 0) !== ROLE_SUPERADMIN) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied.']);
    exit;
}

$raw = file_get_contents('php://input');
$input = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($input)) {
    $input = $_POST;
}

$csrf = (string) ($input['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!csrf_validate($csrf)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid session. Please refresh.']);
    exit;
}

$action = trim((string) ($input['action'] ?? ''));
$branchId = (int) ($input['branch_id'] ?? 0);
if ($branchId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Please select a branch.']);
    exit;
}

try {
    $branchCheck = db()->prepare(
        'SELECT id FROM allureone_branch WHERE id = :id AND isActive = 1 LIMIT 1'
    );
    $branchCheck->execute(['id' => $branchId]);
    if ((int) ($branchCheck->fetchColumn() ?: 0) !== $branchId) {
        echo json_encode(['ok' => false, 'error' => 'Please select a valid branch.']);
        exit;
    }
} catch (Throwable $e) {
    error_log('Invoice branch validation failed: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Could not validate branch.']);
    exit;
}

function invoice_branch_token(int $branchId): string
{
    try {
        $st = db()->prepare(
            'SELECT session_key
             FROM allureone_session_data
             WHERE branch_id = :branch_id
             ORDER BY updated_date DESC
             LIMIT 1'
        );
        $st->execute(['branch_id' => $branchId]);
        return trim((string) ($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        error_log('Invoice Dingg token lookup failed: ' . $e->getMessage());
        return '';
    }
}

function invoice_mask_mobile(string $mobile): string
{
    $mobile = preg_replace('/\D+/', '', trim($mobile)) ?? '';
    if (strlen($mobile) > 10 && str_starts_with($mobile, '91')) {
        $mobile = substr($mobile, 2);
    }
    $length = strlen($mobile);
    if ($length <= 5) {
        return str_repeat('*', $length);
    }

    $maskLength = min(5, max(1, $length - 4));
    $startLength = intdiv($length - $maskLength, 2);

    return substr($mobile, 0, $startLength)
        . str_repeat('*', $maskLength)
        . substr($mobile, $startLength + $maskLength);
}

/**
 * @return array{ok:bool,error?:string,json?:mixed,http?:int}
 */
function invoice_get(string $url, string $token): array
{
    $response = dingg_http_request_authenticated('GET', $url, $token, null);
    $http = (int) ($response['http'] ?? 0);
    $body = (string) ($response['body'] ?? '');
    $json = json_decode($body, true);
    if ($http < 200 || $http >= 300) {
        $message = is_array($json) ? trim((string) ($json['message'] ?? '')) : '';
        return [
            'ok' => false,
            'error' => $message !== '' ? $message : 'Dingg request failed (HTTP ' . $http . ').',
            'http' => $http,
            'json' => $json,
        ];
    }
    return ['ok' => true, 'http' => $http, 'json' => $json];
}

/**
 * @param array<string,mixed> $payload
 * @return array{ok:bool,error?:string,json?:mixed,http?:int}
 */
function invoice_post(string $url, string $token, array $payload): array
{
    $response = dingg_http_request_authenticated(
        'POST',
        $url,
        $token,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    $http = (int) ($response['http'] ?? 0);
    $body = (string) ($response['body'] ?? '');
    $json = json_decode($body, true);
    $success = is_array($json)
        && (($json['success'] ?? null) === true
            || ($json['status'] ?? null) === 'success'
            || in_array((int) ($json['code'] ?? 0), [200, 201], true));
    if ($http >= 200 && $http < 300 && $success) {
        return ['ok' => true, 'http' => $http, 'json' => $json];
    }
    $message = is_array($json) ? trim((string) ($json['message'] ?? '')) : '';
    return [
        'ok' => false,
        'error' => $message !== '' ? $message : 'Dingg request failed (HTTP ' . $http . ').',
        'http' => $http,
        'json' => $json,
    ];
}

/**
 * @return array{ok:bool,sources:list<array{id:int,name:string}>,error?:string}
 */
function invoice_sources(string $token): array
{
    $response = invoice_get('https://api.dingg.app/api/v1/vendor/setting/lead_source/', $token);
    if (!$response['ok']) {
        return ['ok' => false, 'sources' => [], 'error' => $response['error'] ?? 'Could not load sources.'];
    }
    $sources = [];
    $rows = is_array($response['json']) ? ($response['json']['data'] ?? []) : [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            if ($id > 0 && $name !== '') {
                $sources[] = ['id' => $id, 'name' => $name];
            }
        }
    }
    return ['ok' => true, 'sources' => $sources];
}

/**
 * @return array{ok:bool,countries:list<array{id:int,name:string,code:string,dial_code:int,lengths:list<int>}>,error?:string}
 */
function invoice_countries(string $token): array
{
    $response = invoice_get('https://api.dingg.app/api/v1/country?is_length=true', $token);
    if (!$response['ok']) {
        return ['ok' => false, 'countries' => [], 'error' => $response['error'] ?? 'Could not load countries.'];
    }
    $countries = [];
    $rows = is_array($response['json']) ? ($response['json']['data'] ?? []) : [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['country_name'] ?? ''));
            $dialCode = (int) ($row['dial_code'] ?? 0);
            $lengths = [];
            if (is_array($row['possible_length'] ?? null)) {
                foreach ($row['possible_length'] as $length) {
                    $length = (int) $length;
                    if ($length > 0) {
                        $lengths[] = $length;
                    }
                }
            }
            if ($id > 0 && $name !== '' && $dialCode > 0) {
                $countries[] = [
                    'id' => $id,
                    'name' => $name,
                    'code' => strtoupper(trim((string) ($row['country_code'] ?? ''))),
                    'dial_code' => $dialCode,
                    'lengths' => array_values(array_unique($lengths)),
                ];
            }
        }
    }
    return ['ok' => true, 'countries' => $countries];
}

$token = invoice_branch_token($branchId);
if ($token === '') {
    echo json_encode(['ok' => false, 'error' => 'Dingg session is not available for the selected branch.']);
    exit;
}

try {
    if ($action === 'client_search') {
        $search = trim((string) ($input['search'] ?? ''));
        if (strlen($search) < 3) {
            echo json_encode(['ok' => true, 'clients' => []]);
            exit;
        }
        $response = invoice_get(
            'https://api.dingg.app/api/v1/vendor/user_list?'
            . http_build_query(['search' => $search, 'is_web' => 'true'], '', '&', PHP_QUERY_RFC3986),
            $token
        );
        if (!$response['ok']) {
            echo json_encode(['ok' => false, 'error' => $response['error'] ?? 'Could not search clients.']);
            exit;
        }
        $clients = [];
        $rows = is_array($response['json']) ? ($response['json']['data'] ?? []) : [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int) ($row['id'] ?? 0);
                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    $name = trim((string) (($row['fname'] ?? '') . ' ' . ($row['lname'] ?? '')));
                }
                if ($name === '') {
                    $name = trim((string) ($row['display_name'] ?? ''));
                }
                $mobile = preg_replace('/\D+/', '', (string) ($row['mobile'] ?? '')) ?? '';
                if ($id > 0 && $name !== '') {
                    $masked = invoice_mask_mobile($mobile);
                    if (!isset($_SESSION['invoice_client_mobiles']) || !is_array($_SESSION['invoice_client_mobiles'])) {
                        $_SESSION['invoice_client_mobiles'] = [];
                    }
                    if (!isset($_SESSION['invoice_client_mobiles'][$branchId]) || !is_array($_SESSION['invoice_client_mobiles'][$branchId])) {
                        $_SESSION['invoice_client_mobiles'][$branchId] = [];
                    }
                    $_SESSION['invoice_client_mobiles'][$branchId][$id] = $mobile;
                    $clients[] = [
                        'id' => $id,
                        'name' => $name,
                        'mobile_masked' => $masked,
                        'label' => $name . ($masked !== '' ? ' (' . $masked . ')' : ''),
                    ];
                }
            }
        }
        echo json_encode(['ok' => true, 'clients' => $clients]);
        exit;
    }

    if ($action === 'sources') {
        $result = invoice_sources($token);
        echo json_encode($result);
        exit;
    }

    if ($action === 'countries') {
        $result = invoice_countries($token);
        echo json_encode($result);
        exit;
    }

    if ($action === 'client_create') {
        $name = trim((string) ($input['name'] ?? ''));
        $mobile = preg_replace('/\D+/', '', (string) ($input['mobile'] ?? '')) ?? '';
        $gender = strtolower(trim((string) ($input['gender'] ?? 'male')));
        $countryId = (int) ($input['country_id'] ?? 1);
        $sourceId = (int) ($input['source_id'] ?? 0);
        if (strlen($name) < 2 || strlen($name) > 100) {
            echo json_encode(['ok' => false, 'error' => 'Enter a valid client name.']);
            exit;
        }
        if (!in_array($gender, ['male', 'female', 'other'], true)) {
            $gender = 'male';
        }
        $countryResult = invoice_countries($token);
        $selectedCountry = null;
        foreach ($countryResult['countries'] as $country) {
            if ((int) $country['id'] === $countryId) {
                $selectedCountry = $country;
                break;
            }
        }
        if (!$countryResult['ok'] || $selectedCountry === null) {
            echo json_encode(['ok' => false, 'error' => 'Please select a valid country.']);
            exit;
        }
        $validLengths = $selectedCountry['lengths'];
        if (strlen($mobile) < 4 || ($validLengths !== [] && !in_array(strlen($mobile), $validLengths, true))) {
            echo json_encode(['ok' => false, 'error' => 'Enter a valid mobile number for ' . $selectedCountry['name'] . '.']);
            exit;
        }
        $sourceResult = invoice_sources($token);
        $sourceName = '';
        foreach ($sourceResult['sources'] as $source) {
            if ((int) $source['id'] === $sourceId) {
                $sourceName = (string) $source['name'];
                break;
            }
        }
        if (!$sourceResult['ok'] || $sourceName === '') {
            echo json_encode(['ok' => false, 'error' => 'Please select a valid source.']);
            exit;
        }
        $response = invoice_post(
            'https://api.dingg.app/api/v1/vendor/customer_create',
            $token,
            [
                'fname' => $name,
                'lname' => '',
                'mobile' => $mobile,
                'country_id' => $countryId,
                'gender' => $gender,
                'dob' => null,
                'anniversary' => null,
                'sms_promo' => true,
                'sms_trans' => true,
                'email_promo' => true,
                'email_trans' => true,
                'address' => '',
                'gstn' => '',
                'source_id' => $sourceId,
                'source_remark' => $sourceName,
                'is_whatsapp_num' => true,
                'whatsapp_promo' => true,
                'whatsapp_trans' => true,
                'is_dummy' => false,
                'state_id' => 22,
                'registration_no' => '',
                'identifier_no' => '',
                'postal_code' => '',
                'extra_details' => (object) [],
            ]
        );
        if (!$response['ok']) {
            echo json_encode(['ok' => false, 'error' => $response['error'] ?? 'Could not add client.']);
            exit;
        }
        $data = is_array($response['json']) ? ($response['json']['data'] ?? []) : [];
        $createdId = is_array($data)
            ? (int) ($data['id'] ?? $data['user_id'] ?? ($data['user']['id'] ?? 0))
            : 0;
        if ($createdId <= 0) {
            $lookup = invoice_get(
                'https://api.dingg.app/api/v1/vendor/user_list?'
                . http_build_query(['search' => $mobile, 'is_web' => 'true'], '', '&', PHP_QUERY_RFC3986),
                $token
            );
            $lookupRows = ($lookup['ok'] && is_array($lookup['json'])) ? ($lookup['json']['data'] ?? []) : [];
            if (is_array($lookupRows)) {
                foreach ($lookupRows as $lookupRow) {
                    $lookupMobile = is_array($lookupRow)
                        ? (preg_replace('/\D+/', '', (string) ($lookupRow['mobile'] ?? '')) ?? '')
                        : '';
                    if (
                        is_array($lookupRow)
                        && $lookupMobile !== ''
                        && (
                            $lookupMobile === $mobile
                            || substr($lookupMobile, -10) === substr($mobile, -10)
                        )
                    ) {
                        $createdId = (int) ($lookupRow['id'] ?? 0);
                        break;
                    }
                }
            }
        }
        if ($createdId > 0) {
            if (!isset($_SESSION['invoice_client_mobiles']) || !is_array($_SESSION['invoice_client_mobiles'])) {
                $_SESSION['invoice_client_mobiles'] = [];
            }
            if (!isset($_SESSION['invoice_client_mobiles'][$branchId]) || !is_array($_SESSION['invoice_client_mobiles'][$branchId])) {
                $_SESSION['invoice_client_mobiles'][$branchId] = [];
            }
            $_SESSION['invoice_client_mobiles'][$branchId][$createdId] = $mobile;
        }
        echo json_encode([
            'ok' => $createdId > 0,
            'error' => $createdId > 0 ? null : 'Client was added but could not be selected. Search again.',
            'client' => [
                'id' => $createdId,
                'name' => $name,
                'mobile_masked' => invoice_mask_mobile($mobile),
                'label' => $name . ' (' . invoice_mask_mobile($mobile) . ')',
            ],
        ]);
        exit;
    }

    if ($action === 'staff') {
        $response = invoice_get(
            'https://api.dingg.app/api/v1/vendor/undefined/employee?is_appointment=true',
            $token
        );
        if (!$response['ok']) {
            echo json_encode(['ok' => false, 'error' => $response['error'] ?? 'Could not load staff.']);
            exit;
        }
        $staff = [];
        $rows = is_array($response['json']) ? ($response['json']['data'] ?? []) : [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int) ($row['employee_id'] ?? $row['id'] ?? 0);
                $name = trim((string) ($row['name'] ?? ''));
                if ($id <= 0 || $name === '' || strcasecmp($name, 'AllureOne') === 0) {
                    continue;
                }
                $services = [];
                $employeeServices = is_array($row['employee_services'] ?? null) ? $row['employee_services'] : [];
                foreach ($employeeServices as $employeeService) {
                    if (!is_array($employeeService)) {
                        continue;
                    }
                    $service = is_array($employeeService['vendor_service'] ?? null)
                        ? $employeeService['vendor_service']
                        : $employeeService;
                    $serviceId = (int) ($service['id'] ?? $service['vendor_service_id'] ?? $employeeService['vendor_service_id'] ?? 0);
                    $serviceName = trim((string) ($service['service'] ?? $service['name'] ?? ''));
                    if ($serviceId <= 0 || $serviceName === '') {
                        continue;
                    }
                    $minutes = (int) ($service['service_time'] ?? 0);
                    $services[] = [
                        'id' => $serviceId,
                        'name' => $serviceName,
                        'minutes' => $minutes > 0 ? $minutes : 60,
                        'price' => (float) ($service['price'] ?? 0),
                    ];
                }
                $staff[] = ['id' => $id, 'name' => $name, 'services' => $services];
            }
        }
        echo json_encode(['ok' => true, 'staff' => $staff]);
        exit;
    }

    if ($action === 'rooms') {
        $response = invoice_get('https://api.dingg.app/api/v1/calendar/rooms', $token);
        if (!$response['ok']) {
            echo json_encode(['ok' => false, 'error' => $response['error'] ?? 'Could not load rooms.']);
            exit;
        }
        $rooms = [];
        $rows = is_array($response['json']) ? ($response['json']['data'] ?? []) : [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int) ($row['id'] ?? 0);
                $name = trim((string) ($row['title'] ?? $row['name'] ?? $row['desc'] ?? $row['room_name'] ?? ''));
                if ($id > 0 && $name !== '') {
                    $rooms[] = ['id' => $id, 'name' => $name];
                }
            }
        }
        usort($rooms, static function (array $a, array $b): int {
            return strnatcasecmp((string) $a['name'], (string) $b['name']);
        });
        echo json_encode(['ok' => true, 'rooms' => $rooms]);
        exit;
    }

    if ($action === 'setup') {
        $settingResponse = invoice_get('https://api.dingg.app/api/v1/vendor/setting', $token);
        $taxResponse = invoice_get('https://api.dingg.app/api/v1/vendor/tax', $token);
        $invoiceSettingResponse = invoice_get('https://api.dingg.app/api/v1/vendor/invoice-setting', $token);
        $paymentResponse = invoice_get('https://api.dingg.app/api/v1/payment_mode', $token);
        foreach ([$settingResponse, $taxResponse, $invoiceSettingResponse, $paymentResponse] as $response) {
            if (!$response['ok']) {
                echo json_encode(['ok' => false, 'error' => $response['error'] ?? 'Could not load invoice settings.']);
                exit;
            }
        }
        $settings = is_array($settingResponse['json']) ? ($settingResponse['json']['data'] ?? []) : [];
        $taxRows = is_array($taxResponse['json']) ? ($taxResponse['json']['data'] ?? []) : [];
        $serviceTax = null;
        if (is_array($taxRows)) {
            foreach ($taxRows as $taxRow) {
                if (is_array($taxRow) && ($taxRow['on_service'] ?? false) === true) {
                    $serviceTax = $taxRow;
                    break;
                }
            }
        }
        $paymentRows = is_array($paymentResponse['json']) ? ($paymentResponse['json']['data'] ?? []) : [];
        if (is_array($paymentRows) && isset($paymentRows['rows']) && is_array($paymentRows['rows'])) {
            $paymentRows = $paymentRows['rows'];
        }
        $paymentModes = [];
        if (is_array($paymentRows)) {
            foreach ($paymentRows as $paymentRow) {
                if (!is_array($paymentRow)) {
                    continue;
                }
                $value = (int) ($paymentRow['value'] ?? 0);
                $name = trim((string) (
                    $paymentRow['name']
                    ?? $paymentRow['label']
                    ?? $paymentRow['payment_mode']
                    ?? $paymentRow['mode']
                    ?? ''
                ));
                if ($value > 0 && $name !== '') {
                    $paymentModes[] = ['id' => $value, 'name' => $name];
                }
            }
        }
        echo json_encode([
            'ok' => true,
            'settings' => [
                'apply_gst' => (bool) ($settings['apply_gst'] ?? false),
                's_tax_inclusive' => (bool) ($settings['s_tax_inclusive'] ?? false),
                'p_tax_inclusive' => (bool) ($settings['p_tax_inclusive'] ?? false),
            ],
            'service_tax' => $serviceTax,
            'payment_modes' => $paymentModes,
            'invoice_setting' => is_array($invoiceSettingResponse['json'])
                ? ($invoiceSettingResponse['json']['data'] ?? null)
                : null,
        ]);
        exit;
    }

    if ($action === 'bill_number') {
        $date = trim((string) ($input['date'] ?? ''));
        $clientId = (int) ($input['user_id'] ?? 0);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            echo json_encode(['ok' => false, 'error' => 'Please select a valid invoice date.']);
            exit;
        }
        $clientMobile = '';
        if (
            $clientId > 0
            && isset($_SESSION['invoice_client_mobiles'][$branchId][$clientId])
        ) {
            $clientMobile = preg_replace(
                '/\D+/',
                '',
                (string) $_SESSION['invoice_client_mobiles'][$branchId][$clientId]
            ) ?? '';
        }
        if ($clientMobile === '') {
            echo json_encode(['ok' => false, 'error' => 'Client mobile is unavailable. Search and select the client again.']);
            exit;
        }
        $response = invoice_get(
            'https://api.dingg.app/api/v1/vendor/bill/bill-numbers?'
            . http_build_query(
                ['invoice_date' => $date, 'is_product_only' => 'false'],
                '',
                '&',
                PHP_QUERY_RFC3986
            ),
            $token
        );
        if (!$response['ok']) {
            echo json_encode(['ok' => false, 'error' => $response['error'] ?? 'Could not get invoice number.']);
            exit;
        }
        $rows = is_array($response['json']) ? ($response['json']['data'] ?? []) : [];
        $selected = null;
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row) && ($row['is_default'] ?? false) === true && ($row['is_active'] ?? true) === true) {
                    $selected = $row;
                    break;
                }
            }
        }
        if (!is_array($selected)) {
            echo json_encode(['ok' => false, 'error' => 'No default active invoice prefix found.']);
            exit;
        }
        $prefixKeys = [
            'id', 'initial', 'middle', 'separator', 'leading_zero',
            'reset_month', 'reset_day',
        ];
        $prefix = [];
        foreach ($prefixKeys as $key) {
            $prefix[$key] = $selected[$key] ?? null;
        }
        echo json_encode([
            'ok' => true,
            'vendor_location_id' => (int) ($selected['vendor_location_id'] ?? 0),
            'next_invoice_number' => (string) ($selected['next_invoice_number'] ?? ''),
            'inv_prefix' => $prefix,
            'mobile' => $clientMobile,
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('Invoice API failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Invoice request failed.']);
}
