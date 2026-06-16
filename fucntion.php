<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require $_SERVER['DOCUMENT_ROOT'].'/vendor/autoload.php';
use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable($_SERVER['DOCUMENT_ROOT']);
$dotenv->load();
date_default_timezone_set('Asia/Bangkok');
$serversite = $_ENV['SERVER'];

$month = date('n');
$year = date('Y');
$firstDay = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth = date('t', $firstDay);
$monthName = date('F', $firstDay);
$dayOfWeek = date('w', $firstDay);
$today = date('j');    

class SoapApiService {
    private $endpoint;
    private $timeout;

    public function __construct($endpoint = null, $timeout = 30) {
        $this->endpoint = $endpoint ?? $_ENV['FBB_API_INFO'];
        $this->timeout = $timeout;
    }

    public function queryByCustomerId($customerId) {
        $customerId = htmlspecialchars(strip_tags($customerId));
        
        $xmlPayload = '<?xml version="1.0" encoding="UTF-8"?>
                        <soap:Envelope
                        xmlns:soap="http://www.w3.org/2003/05/soap-envelope"
                        xmlns:ws="http://ws.fbb.ais.co.th/">
                            <soap:Header/>
                                <soap:Body>
                                    <ws:queryByCustomerId>
                                        <customerId>' . $customerId . '</customerId>
                                        <channel>?</channel>
                                        <option>?</option>
                                        <refId>?</refId>
                                    </ws:queryByCustomerId>
                                </soap:Body>
                        </soap:Envelope>';

        $headers = [
            'Content-Type: text/xml; charset=utf-8',
            'Content-Length: ' . strlen($xmlPayload)
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        
        // [Security] Fortify Requirement: ต้อง Verify SSL เสมอใน Production
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        // ทำการยิง cURL ไปหา API จริง
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            error_log('cURL Error: ' . curl_error($ch));
            curl_close($ch);
            return ['error' => 'Connection failed'];
        }
        
        curl_close($ch);

        return $this->parseXmlResponse($response);
    }
    public function diagnosticByCustomerId($customerId) {
        $customerId = htmlspecialchars(strip_tags($customerId));
        
        $xmlPayload = '<?xml version="1.0" encoding="UTF-8"?>
                        <soap:Envelope
                        xmlns:soap="http://www.w3.org/2003/05/soap-envelope"
                        xmlns:ws="http://ws.fbb.ais.co.th/">
                            <soap:Header/>
                                <soap:Body>
                                    <ws:diagnosticByCustomerId>
                                        <customerId>' . $customerId . '</customerId>
                                        <channel>?</channel>
                                        <option>?</option>
                                        <refId>?</refId>
                                    </ws:diagnosticByCustomerId>
                                </soap:Body>
                        </soap:Envelope>';

        $headers = [
            'Content-Type: text/xml; charset=utf-8',
            'Content-Length: ' . strlen($xmlPayload)
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        
        // [Security] Fortify Requirement: ต้อง Verify SSL เสมอใน Production
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        // ทำการยิง cURL ไปหา API จริง
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            error_log('cURL Error: ' . curl_error($ch));
            curl_close($ch);
            return ['error' => 'Connection failed'];
        }
        
        curl_close($ch);

        return $this->parseXmlResponse($response, 'diagnosticByCustomerId');
    }

    private function parseXmlResponse($xmlString, $method = 'queryByCustomerId') {
    if (empty($xmlString)) return null;

    if (\PHP_VERSION_ID < 80000) {
        libxml_disable_entity_loader(true);
    }
    $use_errors = libxml_use_internal_errors(true);

    $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($xml === false) {
        error_log('XML Parse Error: ' . print_r(libxml_get_errors(), true));
        libxml_clear_errors();
        return null;
    }

    $xml->registerXPathNamespace('soap', 'http://www.w3.org/2003/05/soap-envelope');
    $xml->registerXPathNamespace('ns2', 'http://ws.fbb.ais.co.th/');

    $responseNodeName = 'ns2:' . $method . 'Response';
    $resultNode = $xml->xpath("//{$responseNodeName}/return");

    libxml_use_internal_errors($use_errors);

    if (!empty($resultNode)) {
        $json = json_encode($resultNode[0]);
        return json_decode($json, true);
    }

    return null;
}
}

function maskData($type, $value) {
    if (empty($value)) return '-';
    switch ($type) {
        case 'idCard': return strlen($value) > 4 ? substr($value, 0, 3) . 'XXXXXX' . substr($value, -4) : 'XXX';
        case 'mobile': return strlen($value) >= 10 ? substr($value, 0, 3) . 'XXXX' . substr($value, -3) : 'XXX';
        case 'password': return '********';
        case 'macAddress': return substr($value, 0, 8) . ':XX:XX:XX';
        default: return $value;
    }
}

function displaySafe($value) {
    return htmlspecialchars((is_array($value) ? json_encode($value) : (string)$value), ENT_QUOTES, 'UTF-8');
}

function formatLabel($camelCase) {
    $result = preg_replace('/(?<!^)[A-Z]/', ' $0', $camelCase);
    return ucwords($result);
}

function detectMaskType($key) {
    $k = strtolower($key);
    if (strpos($k, 'idcard') !== false) return 'idCard';
    if (strpos($k, 'mobile') !== false) return 'mobile';
    if (strpos($k, 'password') !== false) return 'password';
    if (strpos($k, 'macaddress') !== false || strpos($k, 'callingstationid') !== false) return 'macAddress';
    return null;
}

function renderField($label, $value, $maskType = null) {
    $safeValue = displaySafe($value);
    
    echo '<div class="py-2 d-flex justify-content-between align-items-center border-bottom" style="border-color: #f1f1f1 !important; min-height: 40px;">';
    echo '<span class="text-muted small fw-bold me-2 text-wrap" style="max-width: 40%;">' . displaySafe($label) . '</span>';
    
    if ($maskType) {
        $maskedValue = displaySafe(maskData($maskType, $value));
        echo '<div class="d-flex align-items-center gap-2 text-end" style="max-width: 60%;">';
        echo '<span class="small fw-bold text-dark sensitive-text text-wrap text-break" data-raw="' . $safeValue . '" data-masked="' . $maskedValue . '">' . $maskedValue . '</span>';
        echo '<button type="button" onclick="toggleVisibility(this)" class="btn btn-link btn-sm p-0 text-muted" title="Toggle visibility">';
        echo '<i class="fa-solid fa-eye-slash"></i>';
        echo '</button>';
        echo '</div>';
    } else {
        $colorClass = ($label === 'Status' || strpos(strtoupper($safeValue), 'ACTIVE') !== false || strpos(strtoupper($safeValue), 'ONLINE') !== false || strpos(strtoupper($safeValue), 'SUCCESS') !== false) ? 'text-success' : 'text-dark';
        echo '<span class="small fw-bold ' . $colorClass . ' text-end text-wrap text-break" style="max-width: 60%;">' . ($safeValue ?: '-') . '</span>';
    }
    echo '</div>';
}


function renderJson($data) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | 
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    );
}


function checkLogin() {
    if (!isset($_COOKIE["token"])) {
        return false;
    }
    if (isset($_SESSION['force_change_password']) && $_SESSION['force_change_password'] === true) {
        header("Location: /auth/reset-password.php");
        exit;
    }

        
    $token = $_COOKIE["token"];
    $url = $_ENV['BE_API_PYBE']."/verify_token";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token"
    ]);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        setcookie("token", "", time() - 3600, "/");

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        session_unset();
        session_destroy();

        return false;
    }

    return true;
}
function hasPermission(string $permission): bool
{
    if (!isset($_SESSION['user_data']['permission'])) {
        return false;
    }

    return in_array($permission, $_SESSION['user_data']['permission'], true);
}
function callApi($url, $method = 'GET', $data = []) {
    $apiurl = $_ENV['BE_API_PYBE'] . $url;
    $token = $_ENV['API_TOKEN'];
    $ch = curl_init($apiurl);
    
    $headers = [
        "Authorization: Bearer $token"
    ];

    // Handle non-GET methods (POST, PUT, DELETE)
    if (strtoupper($method) !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        
        if (!empty($data)) {
            $json_data = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
            // Add Content-Type header when sending JSON data
            $headers[] = "Content-Type: application/json";
            $headers[] = "Content-Length: " . strlen($json_data);
        }
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        return ['status' => 'error', 'message' => 'cURL Error: ' . $err, 'data' => []];
    }
    
    // Check for HTTP errors (e.g., 4xx or 5xx) that might not return a JSON body
    if ($http_code >= 400) {
        $data = json_decode($response, true);

        if (isset($data['detail']) && is_array($data['detail'])) {
            $errors = [];
            foreach ($data['detail'] as $err) {
                $field = isset($err['loc']) ? implode('.', $err['loc']) : 'unknown';
                $errors[] = $field . ': ' . ($err['msg'] ?? 'invalid');
            }
            $message = implode(' | ', $errors);
        } else {
            $message = $data['detail'] ?? ($data['message'] ?? "HTTP Error $http_code");
        }

        return [
            'status'  => 'error',
            'message' => "API failed ($http_code): " . $message,
            'data'    => []
        ];
    }

    $data = json_decode($response, true);

    if(json_last_error() !== JSON_ERROR_NONE) {
        // If API returned 200/300 but body is not JSON (e.g., empty response for success)
        if (trim($response) === '' && $http_code < 400) {
            return ['status' => 'success', 'message' => 'Operation successful, no content returned.', 'data' => []];
        }
        return ['status' => 'error', 'message' => 'Invalid JSON response: ' . $response, 'data' => []];
    }

    // Default return path for successful JSON response
    return $data;
}
function callApiBot($url, $method = 'GET', $data = []) {
    // Whitelist allowed methods
    $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
    $method = strtoupper($method);
    if (!in_array($method, $allowedMethods, true)) {
        return ['status' => 'error', 'message' => 'Invalid HTTP method', 'data' => []];
    }

    $baseUrl = rtrim($_ENV['BE_API_PYBE'] ?? '', '/');
    if (!preg_match('/^https?:\/\//', $baseUrl)) {
        $baseUrl = 'http://' . $baseUrl;
    }

    $url = '/' . ltrim($url, '/');
    $apiurl = $baseUrl . $url;

    $parsed = parse_url($baseUrl);
    if (empty($baseUrl) || !isset($parsed['scheme'], $parsed['host'])) {
        return ['status' => 'error', 'message' => 'Invalid API URL', 'data' => []];
    }

    $token = $_ENV['API_TOKEN'] ?? '';
    if (empty($token)) {
        return ['status' => 'error', 'message' => 'Missing API token', 'data' => []];
    }

    // Sanitize token to prevent header injection (strip newlines)
    $token = preg_replace('/[\r\n]/', '', $token);

    $ch = curl_init($apiurl);

    $headers = [
        "Authorization: Bearer $token"
    ];

    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if (!empty($data)) {
            $json_data = json_encode($data, JSON_THROW_ON_ERROR);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
            $headers[] = "Content-Type: application/json";
            $headers[] = "Content-Length: " . strlen($json_data);
        }
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);

    // SSL verification (required for Fortify)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        // ไม่ expose error details ออกไป
        return ['status' => 'error', 'message' => 'Request failed. Please try again.', 'data' => []];
    }

    if ($http_code >= 400) {
        $responseData = json_decode($response, true);

        if (isset($responseData['detail']) && is_array($responseData['detail'])) {
            $errors = [];
            foreach ($responseData['detail'] as $errItem) {
                $field = isset($errItem['loc']) ? implode('.', array_map('strval', $errItem['loc'])) : 'unknown';
                $errors[] = $field . ': ' . ($errItem['msg'] ?? 'invalid');
            }
            $message = implode(' | ', $errors);
        } else {
            $message = $responseData['detail'] ?? ($responseData['message'] ?? "HTTP Error $http_code");
        }

        return [
            'status'  => 'error',
            'message' => "API failed ($http_code): " . $message,
            'data'    => []
        ];
    }

    $responseData = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        if (trim($response) === '' && $http_code < 400) {
            return ['status' => 'success', 'message' => 'Operation successful, no content returned.', 'data' => []];
        }
        // ไม่ expose raw response
        return ['status' => 'error', 'message' => 'Invalid response format.', 'data' => []];
    }

    return $responseData;
}
function callApiV2($url, $expected_token, $method = 'GET', $params = null) {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        "Authorization: Bearer $expected_token"
    ]);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    if (!empty($params)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    }

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        throw new Exception('cURL Error: ' . $err);
    }

    if ($http_code >= 400) {
        $data = json_decode($response, true);
        $message = $data['detail'] ?? ($data['message'] ?? "HTTP Error $http_code");
        throw new Exception("API failed ($http_code): $message");
    }

    $data = json_decode($response, true);

    // if (json_last_error() !== JSON_ERROR_NONE) {
    //     if (trim($response) === '' && $http_code < 400) {
    //         return [
    //             'status' => 'success',
    //             'message' => 'Operation successful, no content returned.',
    //             'data' => []
    //         ];
    //     }
    //     return [
    //         'status' => 'error',
    //         'message' => 'Invalid JSON response: ' . $response,
    //         'data' => []
    //     ];
    // }

    return $data;
}

function secondsToTime($seconds): string {
    if (!is_numeric($seconds) || $seconds < 0) {
        return 'N/A';
    }
    // Calculate total hours and minutes from seconds
    // Note: We only care about HH and MM since the input time is likely HH:MM:00
    $total_seconds = (int)round($seconds);
    $hours = floor($total_seconds / 3600);
    $minutes = floor(($total_seconds % 3600) / 60);
    
    // Use modulo 24 to handle times that wrap around (though typically 0-23 hours)
    $hours = $hours % 24; 

    // Format to HH:MM (pad with leading zero)
    return sprintf('%02d:%02d', $hours, $minutes);
}
function requireRole(int $maxPriority, string $msg = 'Access denied') {
    if (!isset($_SESSION['user_data']['role_pr'])) {
        http_response_code(401);
        die('Not logged in');
    }

    if ($_SESSION['user_data']['role_pr'] > $maxPriority) {
        http_response_code(403);
        die($msg);
    }
}
function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
?>