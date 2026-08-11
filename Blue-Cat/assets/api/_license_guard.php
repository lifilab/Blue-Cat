<?php
/**
 * Blue-Cat ERP — Módulo de Guardián de Licencia de 1 Uso & Validación Online
 * Conecta Blue-Cat con el servidor de licencias (http://localhost:3050)
 */

defined('LICENSE_SERVER_URL') or define('LICENSE_SERVER_URL', getenv('LICENSE_SERVER_URL') ?: 'http://localhost:3050');
define('LICENSE_CONFIG_FILE', dirname(__DIR__, 2) . '/config/license_config.json');

function getLicenseConfig(): array {
    $candidatePaths = [
        LICENSE_CONFIG_FILE,
        (getenv('ProgramData') ?: 'C:/ProgramData') . '/Blue-Cat/config/license_config.json'
    ];

    foreach ($candidatePaths as $path) {
        if (file_exists($path)) {
            $content = file_get_contents($path);
            $data = json_decode($content, true);
            if (is_array($data) && !empty($data['license_key'])) {
                return $data;
            }
        }
    }
    return [
        'server_url' => LICENSE_SERVER_URL,
        'email' => '',
        'license_key' => '',
        'session_token' => '',
        'client_name' => '',
        'status' => 'unactivated',
        'last_verified' => 0
    ];
}

function saveLicenseConfig(array $config): bool {
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $paths = [
        LICENSE_CONFIG_FILE,
        (getenv('ProgramData') ?: 'C:/ProgramData') . '/Blue-Cat/config/license_config.json'
    ];
    $saved = false;
    foreach ($paths as $p) {
        $dir = dirname($p);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (@file_put_contents($p, $json) !== false) {
            $saved = true;
        }
    }
    return $saved;
}

function getSystemHwid(): string {
    $hostname = gethostname() ?: 'UNKNOWN_HOST';
    $os = PHP_OS;
    return md5("BLUECAT_HWID:{$hostname}:{$os}");
}

/**
 * Realiza el Handshake Inicial para activar la licencia de 1 uso
 */
function activateLicenseOnline(string $email, string $licenseKey, string $serverUrl = LICENSE_SERVER_URL): array {
    $hwid = getSystemHwid();
    $payload = json_encode([
        'email' => trim($email),
        'license_key' => trim(strtoupper($licenseKey)),
        'hwid' => $hwid
    ]);

    $ch = curl_init(rtrim($serverUrl, '/') . '/api/license/verify');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error || !$response) {
        return [
            'ok' => false,
            'message' => 'No se pudo conectar al servidor de licencias (' . ($error ?: 'Servidor Offline') . '). Asegúrate de tener conexión a internet.'
        ];
    }

    $resData = json_decode($response, true);
    if ($httpCode === 200 && isset($resData['status']) && $resData['status'] === 'success') {
        $config = [
            'server_url' => $serverUrl,
            'email' => trim($email),
            'license_key' => trim(strtoupper($licenseKey)),
            'session_token' => $resData['session_token'],
            'client_name' => $resData['client_name'] ?? 'Cliente',
            'status' => 'active',
            'last_verified' => time()
        ];
        saveLicenseConfig($config);

        return [
            'ok' => true,
            'message' => 'Licencia de 1 uso verificada y activada exitosamente.',
            'client_name' => $resData['client_name'] ?? 'Cliente',
            'session_token' => $resData['session_token']
        ];
    }

    return [
        'ok' => false,
        'message' => $resData['message'] ?? 'Licencia o correo inválido.'
    ];
}

/**
 * Realiza un Heartbeat con el Servidor y Rota la Clave Dinámica de Sesión
 */
function checkLicenseStatusOnline(bool $forceRefresh = false): array {
    $config = getLicenseConfig();

    if (empty($config['license_key']) || empty($config['email'])) {
        return [
            'active' => false,
            'reason' => 'NOT_ACTIVATED',
            'message' => 'El producto no ha sido activado con una licencia de 1 uso.'
        ];
    }

    // Cache por 15 segundos si no se fuerza la actualización
    if (!$forceRefresh && (time() - ($config['last_verified'] ?? 0)) < 15 && ($config['status'] ?? '') === 'active') {
        return [
            'active' => true,
            'client_name' => $config['client_name'] ?? '',
            'license_key' => $config['license_key']
        ];
    }

    $serverUrl = $config['server_url'] ?: LICENSE_SERVER_URL;
    $hwid = getSystemHwid();

    // Si aún no tenemos session_token, intentamos realizar handshake
    if (empty($config['session_token'])) {
        $activate = activateLicenseOnline($config['email'], $config['license_key'], $serverUrl);
        if ($activate['ok']) {
            return [
                'active' => true,
                'client_name' => $activate['client_name'],
                'license_key' => $config['license_key']
            ];
        }
        return [
            'active' => false,
            'reason' => 'ACTIVATION_FAILED',
            'message' => $activate['message']
        ];
    }

    // Ping Heartbeat
    $payload = json_encode([
        'session_token' => $config['session_token'],
        'license_key' => $config['license_key'],
        'hwid' => $hwid
    ]);

    $ch = curl_init(rtrim($serverUrl, '/') . '/api/license/heartbeat');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $resData = json_decode($response, true);

    if ($httpCode === 200 && isset($resData['status']) && $resData['status'] === 'ok') {
        // Rotación de token exitosa
        $config['session_token'] = $resData['next_session_token'];
        $config['status'] = 'active';
        $config['last_verified'] = time();
        saveLicenseConfig($config);

        return [
            'active' => true,
            'client_name' => $config['client_name'] ?? '',
            'license_key' => $config['license_key']
        ];
    }

    // Si fue suspendido por el admin en el panel o el token caducó
    $config['status'] = 'suspended';
    $config['session_token'] = '';
    saveLicenseConfig($config);

    return [
        'active' => false,
        'reason' => 'SUSPENDED_OR_REVOKED',
        'message' => $resData['message'] ?? 'La licencia se encuentra suspendida o no válida. Contacte al administrador.'
    ];
}
