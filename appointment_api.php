<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!can_access_appointments($user)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $input = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($input)) {
        $input = $_POST;
    }
} else {
    $input = $_GET;
}

$csrf = (string) ($input['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!csrf_validate($csrf)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid session. Please refresh.']);
    exit;
}

$action = trim((string) ($input['action'] ?? ''));
$roleId = (int) ($user['role_id'] ?? 0);
$canSelectBranch = in_array($roleId, [ROLE_SUPERADMIN, ROLE_ADMIN], true);
$branchId = (int) ($user['branch_id'] ?? 0);
if ($canSelectBranch) {
    $requestedBranchId = (int) ($input['branch_id'] ?? 0);
    if ($requestedBranchId > 0) {
        try {
            $branchCheck = db()->prepare(
                'SELECT id
                 FROM allureone_branch
                 WHERE id = :id AND isActive = 1
                 LIMIT 1'
            );
            $branchCheck->execute(['id' => $requestedBranchId]);
            $branchId = (int) ($branchCheck->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            error_log('Appointment branch validation failed: ' . $e->getMessage());
            $branchId = 0;
        }
    } else {
        $branchId = 0;
    }
}
if ($branchId <= 0) {
    echo json_encode(['ok' => false, 'error' => $canSelectBranch ? 'Please select a valid branch.' : 'No branch linked to your account.']);
    exit;
}

/**
 * @return string
 */
function appointment_branch_session_key(int $branchId): string
{
    if ($branchId <= 0) {
        return '';
    }
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
        error_log('Appointment session key lookup failed: ' . $e->getMessage());
    }

    return '';
}

/**
 * @return array{ok:bool,error?:string,http?:int,json?:mixed,body?:string}
 */
function appointment_dingg_get(string $url, string $token): array
{
    $resp = dingg_http_request_authenticated('GET', $url, $token, null);
    $http = (int) ($resp['http'] ?? 0);
    $body = (string) ($resp['body'] ?? '');
    $json = json_decode($body, true);
    if ($http < 200 || $http >= 300) {
        $msg = is_array($json) ? trim((string) ($json['message'] ?? '')) : '';

        return ['ok' => false, 'error' => $msg !== '' ? $msg : ('Request failed (HTTP ' . $http . ').'), 'http' => $http, 'json' => $json, 'body' => $body];
    }

    return ['ok' => true, 'http' => $http, 'json' => $json, 'body' => $body];
}

/**
 * @param array<string, mixed> $payload
 * @return array{ok:bool,error?:string,http?:int,json?:mixed,body?:string}
 */
function appointment_dingg_json(string $method, string $url, string $token, array $payload): array
{
    $resp = dingg_http_request_authenticated($method, $url, $token, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $http = (int) ($resp['http'] ?? 0);
    $body = (string) ($resp['body'] ?? '');
    $json = json_decode($body, true);
    $msg = is_array($json) ? trim((string) ($json['message'] ?? '')) : '';
    $status = is_array($json) ? ($json['status'] ?? null) : null;
    $code = is_array($json) ? (int) ($json['code'] ?? 0) : 0;
    $success = is_array($json) && (($json['success'] ?? null) === true || $status === 'success' || $code === 200 || $code === 201);
    if ($http >= 200 && $http < 300 && ($success || $msg === 'Booking saved' || $code === 201)) {
        return ['ok' => true, 'http' => $http, 'json' => $json, 'body' => $body];
    }
    if ($http >= 200 && $http < 300 && is_array($json) && $status !== 'failure' && ($json['success'] ?? null) !== false) {
        // Some Dingg responses omit success flags
        if ($msg === '' || stripos($msg, 'success') !== false || stripos($msg, 'saved') !== false) {
            return ['ok' => true, 'http' => $http, 'json' => $json, 'body' => $body];
        }
    }

    return ['ok' => false, 'error' => $msg !== '' ? $msg : ('Request failed (HTTP ' . $http . ').'), 'http' => $http, 'json' => $json, 'body' => $body];
}

/**
 * @return array{ok:bool,sources:list<array{id:int,name:string}>,error?:string}
 */
function appointment_load_lead_sources(string $token): array
{
    $r = appointment_dingg_get(
        'https://api.dingg.app/api/v1/vendor/setting/lead_source/',
        $token
    );
    if (!$r['ok']) {
        return ['ok' => false, 'sources' => [], 'error' => $r['error'] ?? 'Could not load sources.'];
    }
    $sources = [];
    $data = is_array($r['json']) ? ($r['json']['data'] ?? []) : [];
    if (is_array($data)) {
        foreach ($data as $row) {
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
function appointment_load_countries(string $token): array
{
    $r = appointment_dingg_get(
        'https://api.dingg.app/api/v1/country?is_length=true',
        $token
    );
    if (!$r['ok']) {
        return ['ok' => false, 'countries' => [], 'error' => $r['error'] ?? 'Could not load countries.'];
    }

    $countries = [];
    $data = is_array($r['json']) ? ($r['json']['data'] ?? []) : [];
    if (is_array($data)) {
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['country_name'] ?? ''));
            $code = strtoupper(trim((string) ($row['country_code'] ?? '')));
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
                    'code' => $code,
                    'dial_code' => $dialCode,
                    'lengths' => array_values(array_unique($lengths)),
                ];
            }
        }
    }

    return ['ok' => true, 'countries' => $countries];
}

function appointment_minutes_to_label(int $mins): string
{
    $mins = max(0, $mins);
    $h = intdiv($mins, 60) % 24;
    $m = $mins % 60;
    $suffix = $h >= 12 ? 'PM' : 'AM';
    $h12 = $h % 12;
    if ($h12 === 0) {
        $h12 = 12;
    }

    return sprintf('%d:%02d %s', $h12, $m, $suffix);
}

function appointment_mask_mobile(string $mobile): string
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
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function appointment_normalize_booking_row(array $row): array
{
    $ext = is_array($row['extendedProps'] ?? null) ? $row['extendedProps'] : [];
    $user = is_array($ext['user'] ?? null) ? $ext['user'] : [];
    $book = is_array($ext['book'] ?? null) ? $ext['book'] : [];
    $room = is_array($ext['vendor_room'] ?? null) ? $ext['vendor_room'] : [];
    $svcArr = is_array($book['services_arr'] ?? null) ? $book['services_arr'] : [];
    $firstSvc = (isset($svcArr[0]) && is_array($svcArr[0])) ? $svcArr[0] : [];
    $fname = trim((string) ($user['fname'] ?? ''));
    $lname = trim((string) ($user['lname'] ?? ''));
    $customerName = trim($fname . ' ' . $lname);
    if ($customerName === '') {
        $customerName = 'Customer';
    }
    $startMins = (int) ($book['start_time'] ?? $firstSvc['start_time'] ?? 0);
    $endMins = (int) ($book['end_time'] ?? $firstSvc['end_time'] ?? 0);
    if ($startMins <= 0 && !empty($row['start'])) {
        try {
            $dt = new DateTime((string) $row['start']);
            $startMins = ((int) $dt->format('H')) * 60 + (int) $dt->format('i');
        } catch (Exception $e) {
            $startMins = 0;
        }
    }
    if ($endMins <= 0 && !empty($row['end'])) {
        try {
            $dt = new DateTime((string) $row['end']);
            $endMins = ((int) $dt->format('H')) * 60 + (int) $dt->format('i');
        } catch (Exception $e) {
            $endMins = 0;
        }
    }
    $spanMinutes = ($endMins > $startMins) ? ($endMins - $startMins) : 0;
    $serviceMinutes = (int) ($firstSvc['service_time'] ?? $firstSvc['time'] ?? 0);
    if ($serviceMinutes <= 0) {
        $serviceMinutes = $spanMinutes;
    }
    if ($serviceMinutes <= 0) {
        $serviceMinutes = 60;
    }
    $allowedDurations = [30, 60, 90, 120];
    if (!in_array($serviceMinutes, $allowedDurations, true)) {
        $nearest = 60;
        $bestDiff = PHP_INT_MAX;
        foreach ($allowedDurations as $allowed) {
            $diff = abs($allowed - $serviceMinutes);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $nearest = $allowed;
            }
        }
        $serviceMinutes = $nearest;
    }

    return [
        'id' => (int) ($row['id'] ?? $book['id'] ?? 0),
        'customer_id' => (int) ($user['id'] ?? 0),
        'customer_name' => $customerName,
        'customer_mobile_masked' => appointment_mask_mobile((string) ($user['mobile'] ?? '')),
        'staff_id' => (int) ($book['employee_id'] ?? $row['resourceId'] ?? 0),
        'staff_name' => trim((string) ($book['employee_name'] ?? '')),
        'service_id' => (int) ($firstSvc['id'] ?? 0),
        'service_name' => trim((string) ($firstSvc['service'] ?? $book['services'] ?? '')),
        'price' => (float) ($firstSvc['price'] ?? 0),
        'minutes' => $serviceMinutes,
        'room_id' => (int) ($room['id'] ?? 0),
        'room_name' => trim((string) ($room['name'] ?? '')),
        'booking_date' => trim((string) ($book['selected_date'] ?? substr((string) ($row['start'] ?? ''), 0, 10))),
        'start_time' => $startMins,
        'end_time' => $endMins,
        'start_label' => appointment_minutes_to_label($startMins),
        'end_label' => appointment_minutes_to_label($endMins),
        'status' => trim((string) ($row['booking_status'] ?? $book['status'] ?? '')),
        'is_editable' => (bool) ($book['is_editable'] ?? true),
        'comment' => trim((string) ($row['desc'] ?? '')),
    ];
}

$token = '';
if ($canSelectBranch) {
    $token = appointment_branch_session_key($branchId);
} else {
    $token = (string) (dingg_resolve_pos_token_for_api() ?? '');
}
if ($token === '') {
    $token = appointment_branch_session_key($branchId);
    if ($token !== '') {
        dingg_encrypt_session_token($token);
    }
}
if ($token === '') {
    echo json_encode([
        'ok' => false,
        'error' => $canSelectBranch
            ? 'Dingg session is not available for the selected branch.'
            : 'Dingg session not available. Please log out and log in again.',
    ]);
    exit;
}

try {
    if ($action === 'list') {
        $date = trim((string) ($input['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        }
        $r = appointment_dingg_get('https://api.dingg.app/api/v1/calender/booking?date=' . rawurlencode($date), $token);
        if (!$r['ok']) {
            echo json_encode(['ok' => false, 'error' => $r['error'] ?? 'Could not load appointments.']);
            exit;
        }
        $rows = [];
        $data = is_array($r['json']) ? ($r['json']['data'] ?? []) : [];
        if (is_array($data)) {
            foreach ($data as $row) {
                if (is_array($row)) {
                    $rows[] = appointment_normalize_booking_row($row);
                }
            }
        }
        usort($rows, static function (array $a, array $b): int {
            return ((int) $a['start_time']) <=> ((int) $b['start_time']);
        });
        echo json_encode(['ok' => true, 'date' => $date, 'appointments' => $rows]);
        exit;
    }

    if ($action === 'staff') {
        $r = appointment_dingg_get(
            'https://api.dingg.app/api/v1/vendor/undefined/employee?is_appointment=true',
            $token
        );
        if (!$r['ok']) {
            echo json_encode(['ok' => false, 'error' => $r['error'] ?? 'Could not load staff.']);
            exit;
        }
        $staff = [];
        $data = is_array($r['json']) ? ($r['json']['data'] ?? []) : [];
        if (is_array($data)) {
            foreach ($data as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (array_key_exists('status', $row) && !(bool) $row['status']) {
                    continue;
                }
                $id = (int) ($row['employee_id'] ?? $row['id'] ?? 0);
                $name = trim((string) ($row['name'] ?? ''));
                if ($id <= 0 || $name === '') {
                    continue;
                }
                // Skip obvious system/app accounts
                if (strcasecmp($name, 'AllureOne') === 0) {
                    continue;
                }
                $services = [];
                $employeeServices = $row['employee_services'] ?? [];
                if (is_array($employeeServices)) {
                    foreach ($employeeServices as $employeeService) {
                        if (!is_array($employeeService)) {
                            continue;
                        }
                        $svc = is_array($employeeService['vendor_service'] ?? null)
                            ? $employeeService['vendor_service']
                            : $employeeService;
                        $serviceId = (int) ($svc['id'] ?? $svc['vendor_service_id'] ?? $employeeService['vendor_service_id'] ?? 0);
                        $serviceName = trim((string) ($svc['service'] ?? $svc['name'] ?? ''));
                        if ($serviceId <= 0 || $serviceName === '') {
                            continue;
                        }
                        $minutes = (int) ($svc['service_time'] ?? 0);
                        if ($minutes <= 0) {
                            $minutes = 60;
                        }
                        $subCategory = is_array($svc['sub_category'] ?? null) ? $svc['sub_category'] : [];
                        $services[] = [
                            'id' => $serviceId,
                            'name' => $serviceName,
                            'price' => (float) ($svc['price'] ?? 0),
                            'minutes' => $minutes,
                            'group' => trim((string) ($subCategory['subcategory'] ?? '')),
                        ];
                    }
                }
                usort($services, static function (array $a, array $b): int {
                    return strcasecmp((string) $a['name'], (string) $b['name']);
                });
                $staff[] = ['id' => $id, 'name' => $name, 'services' => $services];
            }
        }
        usort($staff, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']);
        });
        echo json_encode(['ok' => true, 'staff' => $staff]);
        exit;
    }

    if ($action === 'rooms') {
        $r = appointment_dingg_get('https://api.dingg.app/api/v1/calendar/rooms', $token);
        if (!$r['ok']) {
            echo json_encode(['ok' => false, 'error' => $r['error'] ?? 'Could not load rooms.']);
            exit;
        }
        $rooms = [];
        $data = is_array($r['json']) ? ($r['json']['data'] ?? []) : [];
        if (is_array($data)) {
            foreach ($data as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int) ($row['id'] ?? 0);
                $name = trim((string) ($row['title'] ?? $row['name'] ?? $row['desc'] ?? ''));
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

    if ($action === 'client_search') {
        $search = trim((string) ($input['search'] ?? ''));
        $searchLength = function_exists('mb_strlen') ? mb_strlen($search) : strlen($search);
        if ($searchLength < 3) {
            echo json_encode(['ok' => false, 'error' => 'Enter at least 3 characters.']);
            exit;
        }
        if ($searchLength > 100) {
            echo json_encode(['ok' => false, 'error' => 'Search is too long.']);
            exit;
        }
        $r = appointment_dingg_get(
            'https://api.dingg.app/api/v1/vendor/user_list?'
            . http_build_query(['search' => $search, 'is_web' => 'true'], '', '&', PHP_QUERY_RFC3986),
            $token
        );
        if (!$r['ok']) {
            echo json_encode(['ok' => false, 'error' => $r['error'] ?? 'Could not search clients.']);
            exit;
        }
        $clients = [];
        $data = is_array($r['json']) ? ($r['json']['data'] ?? []) : [];
        if (is_array($data)) {
            foreach ($data as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int) ($row['id'] ?? 0);
                $name = trim((string) ($row['name'] ?? ''));
                if ($id <= 0 || $name === '') {
                    continue;
                }
                $maskedMobile = appointment_mask_mobile((string) ($row['mobile'] ?? ''));
                $clients[] = [
                    'id' => $id,
                    'name' => $name,
                    'mobile_masked' => $maskedMobile,
                    'label' => $name . ($maskedMobile !== '' ? ' (' . $maskedMobile . ')' : ''),
                ];
            }
        }
        echo json_encode(['ok' => true, 'clients' => $clients]);
        exit;
    }

    if ($action === 'client_create') {
        $name = trim((string) ($input['name'] ?? ''));
        $mobile = preg_replace('/\D+/', '', (string) ($input['mobile'] ?? '')) ?? '';
        $gender = strtolower(trim((string) ($input['gender'] ?? 'male')));
        $sourceId = (int) ($input['source_id'] ?? 0);
        $countryId = (int) ($input['country_id'] ?? 1);
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($nameLength < 2 || $nameLength > 100) {
            echo json_encode(['ok' => false, 'error' => 'Enter a valid client name.']);
            exit;
        }
        $countryResult = appointment_load_countries($token);
        if (!$countryResult['ok']) {
            echo json_encode(['ok' => false, 'error' => $countryResult['error'] ?? 'Could not validate country.']);
            exit;
        }
        $selectedCountry = null;
        foreach ($countryResult['countries'] as $country) {
            if ((int) $country['id'] === $countryId) {
                $selectedCountry = $country;
                break;
            }
        }
        if ($selectedCountry === null) {
            echo json_encode(['ok' => false, 'error' => 'Please select a valid country.']);
            exit;
        }
        $mobileLength = strlen($mobile);
        $validLengths = $selectedCountry['lengths'];
        if (
            $mobileLength < 4
            || $mobileLength > 17
            || ($validLengths !== [] && !in_array($mobileLength, $validLengths, true))
        ) {
            $lengthHint = $validLengths !== [] ? ' (' . implode(', ', $validLengths) . ' digits)' : '';
            echo json_encode(['ok' => false, 'error' => 'Enter a valid mobile number for ' . $selectedCountry['name'] . $lengthHint . '.']);
            exit;
        }
        if (!in_array($gender, ['male', 'female', 'other'], true)) {
            $gender = 'male';
        }
        $sourceResult = appointment_load_lead_sources($token);
        if (!$sourceResult['ok']) {
            echo json_encode(['ok' => false, 'error' => $sourceResult['error'] ?? 'Could not validate source.']);
            exit;
        }
        $sourceName = '';
        foreach ($sourceResult['sources'] as $source) {
            if ((int) $source['id'] === $sourceId) {
                $sourceName = (string) $source['name'];
                break;
            }
        }
        if ($sourceId <= 0 || $sourceName === '') {
            echo json_encode(['ok' => false, 'error' => 'Please select a valid source.']);
            exit;
        }
        $payload = [
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
        ];
        $r = appointment_dingg_json(
            'POST',
            'https://api.dingg.app/api/v1/vendor/customer_create',
            $token,
            $payload
        );
        if (!$r['ok']) {
            echo json_encode(['ok' => false, 'error' => $r['error'] ?? 'Could not add client.']);
            exit;
        }
        $responseData = is_array($r['json']) ? ($r['json']['data'] ?? []) : [];
        $createdId = 0;
        if (is_array($responseData)) {
            $responseUser = is_array($responseData['user'] ?? null) ? $responseData['user'] : [];
            $responseFirst = is_array($responseData[0] ?? null) ? $responseData[0] : [];
            $createdId = (int) (
                $responseData['id']
                ?? $responseData['user_id']
                ?? $responseUser['id']
                ?? $responseFirst['id']
                ?? 0
            );
        }
        if ($createdId <= 0) {
            $lookup = appointment_dingg_get(
                'https://api.dingg.app/api/v1/vendor/user_list?'
                . http_build_query(['search' => $mobile, 'is_web' => 'true'], '', '&', PHP_QUERY_RFC3986),
                $token
            );
            $lookupRows = ($lookup['ok'] && is_array($lookup['json']))
                ? ($lookup['json']['data'] ?? [])
                : [];
            if (is_array($lookupRows)) {
                $mobileTail = substr($mobile, -10);
                foreach ($lookupRows as $lookupRow) {
                    if (!is_array($lookupRow)) {
                        continue;
                    }
                    $lookupMobile = preg_replace('/\D+/', '', (string) ($lookupRow['mobile'] ?? '')) ?? '';
                    if ($lookupMobile !== '' && substr($lookupMobile, -10) === $mobileTail) {
                        $createdId = (int) ($lookupRow['id'] ?? 0);
                        if ($createdId > 0) {
                            break;
                        }
                    }
                }
            }
        }
        if ($createdId <= 0) {
            echo json_encode([
                'ok' => false,
                'error' => 'Client was added, but could not be selected automatically. Search for the client again.',
            ]);
            exit;
        }
        echo json_encode([
            'ok' => true,
            'message' => 'Client added.',
            'client' => [
                'id' => $createdId,
                'name' => $name,
                'mobile_masked' => appointment_mask_mobile($mobile),
                'label' => $name . ' (' . appointment_mask_mobile($mobile) . ')',
            ],
        ]);
        exit;
    }

    if ($action === 'lead_sources') {
        $sourceResult = appointment_load_lead_sources($token);
        if (!$sourceResult['ok']) {
            echo json_encode(['ok' => false, 'error' => $sourceResult['error'] ?? 'Could not load sources.']);
            exit;
        }
        echo json_encode(['ok' => true, 'sources' => $sourceResult['sources']]);
        exit;
    }

    if ($action === 'countries') {
        $countryResult = appointment_load_countries($token);
        if (!$countryResult['ok']) {
            echo json_encode(['ok' => false, 'error' => $countryResult['error'] ?? 'Could not load countries.']);
            exit;
        }
        echo json_encode(['ok' => true, 'countries' => $countryResult['countries']]);
        exit;
    }

    if ($action === 'save') {
        $bookingId = (int) ($input['booking_id'] ?? 0);
        $userId = (int) ($input['user_id'] ?? 0);
        $bookingDate = trim((string) ($input['booking_date'] ?? ''));
        $staffId = (int) ($input['staff_id'] ?? 0);
        $serviceId = (int) ($input['service_id'] ?? 0);
        $serviceName = trim((string) ($input['service_name'] ?? ''));
        $roomId = (int) ($input['room_id'] ?? 0);
        $startTime = (int) ($input['start_time'] ?? 0);
        $endTime = (int) ($input['end_time'] ?? 0);
        $price = (float) ($input['price'] ?? 0);

        if ($userId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Please select a customer.']);
            exit;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bookingDate)) {
            echo json_encode(['ok' => false, 'error' => 'Please select a valid date.']);
            exit;
        }
        if ($staffId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Please select staff.']);
            exit;
        }
        if ($serviceId <= 0 || $serviceName === '') {
            echo json_encode(['ok' => false, 'error' => 'Please select a service.']);
            exit;
        }
        if ($roomId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Please select a room.']);
            exit;
        }
        if ($startTime < 0 || $endTime <= $startTime) {
            echo json_encode(['ok' => false, 'error' => 'Please select a valid start time.']);
            exit;
        }
        $appointmentStart = DateTime::createFromFormat(
            '!Y-m-d H:i',
            $bookingDate . ' ' . sprintf('%02d:%02d', intdiv($startTime, 60), $startTime % 60),
            new DateTimeZone('Asia/Kolkata')
        );
        $nowIst = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
        if (!$appointmentStart instanceof DateTime || $appointmentStart <= $nowIst) {
            echo json_encode(['ok' => false, 'error' => 'Past start time cannot be selected.']);
            exit;
        }
        $duration = $endTime - $startTime;
        if (!in_array($duration, [30, 60, 90, 120], true)) {
            echo json_encode(['ok' => false, 'error' => 'Please select a valid service time.']);
            exit;
        }

        $payload = [
            'user_id' => $userId,
            'booking_date' => $bookingDate,
            'booking_comment' => 'A1',
            'booking_status' => 'tentative',
            'merge_services_of_same_staff' => false,
            'services' => [[
                'service_id' => $serviceId,
                'service_name' => $serviceName,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'family_mem_id' => null,
                'p_start' => null,
                'p_end' => null,
                'assigned_emp_id' => $staffId,
                'room_id' => $roomId,
                'req_emp_id' => null,
                'secondary_staff' => null,
            ]],
            'is_locked' => false,
            'appointment_prices' => [[
                'service_id' => (string) $serviceId,
                'price' => $price,
            ]],
        ];
        $method = 'POST';
        if ($bookingId > 0) {
            $payload['id'] = $bookingId;
            $method = 'PUT';
        }
        $r = appointment_dingg_json($method, 'https://api.dingg.app/api/v1/vendor/booking', $token, $payload);
        if (!$r['ok']) {
            echo json_encode(['ok' => false, 'error' => $r['error'] ?? 'Could not save appointment.']);
            exit;
        }
        echo json_encode([
            'ok' => true,
            'message' => $bookingId > 0 ? 'Appointment updated.' : 'Appointment booked.',
            'data' => $r['json']['data'] ?? null,
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('Appointment API failed: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Something went wrong. Please try again.']);
}
