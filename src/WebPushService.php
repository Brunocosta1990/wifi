<?php
declare(strict_types=1);

final class WebPushService
{
    private string $vapidSubject;
    private string $vapidPublicKey;
    private string $vapidPrivatePem;

    public function __construct(?array $config = null)
    {
        $config ??= app_config()['vapid'];
        $this->vapidSubject = (string) $config['subject'];
        $this->vapidPublicKey = (string) $config['public_key'];
        $this->vapidPrivatePem = (string) $config['private_key_pem'];
    }

    public static function generateVapidKeys(): array
    {
        $resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($resource === false) {
            throw new RuntimeException('Não foi possível gerar as chaves VAPID com OpenSSL.');
        }

        if (!openssl_pkey_export($resource, $privatePem)) {
            throw new RuntimeException('Não foi possível exportar a chave privada VAPID.');
        }

        $details = openssl_pkey_get_details($resource);
        if (!is_array($details) || empty($details['ec']['x']) || empty($details['ec']['y'])) {
            throw new RuntimeException('O OpenSSL do servidor não expõe os detalhes da curva P-256.');
        }

        $publicRaw = "\x04" . $details['ec']['x'] . $details['ec']['y'];
        return [
            'public_key' => self::base64UrlEncode($publicRaw),
            'private_key_pem' => $privatePem,
        ];
    }

    public function send(array $subscription, array $payload, int $ttl = 300, array $meta = []): array
    {
        foreach (['endpoint', 'p256dh_key', 'auth_key'] as $required) {
            if (empty($subscription[$required])) {
                return ['success' => false, 'status' => 0, 'expired' => false, 'error' => "Assinatura sem {$required}."];
            }
        }

        $endpoint = (string) $subscription['endpoint'];
        $parts = parse_url($endpoint);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return ['success' => false, 'status' => 0, 'expired' => true, 'error' => 'Endpoint Push inválido.'];
        }

        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($json) > 2800) {
                throw new RuntimeException('A notificação ultrapassa o limite seguro de 2.800 bytes.');
            }

            $encrypted = $this->encryptPayload(
                $json,
                self::base64UrlDecode((string) $subscription['p256dh_key']),
                self::base64UrlDecode((string) $subscription['auth_key'])
            );

            return $this->pushRequest($endpoint, $parts, $encrypted, [
                'Content-Encoding: aes128gcm',
                'Content-Type: application/octet-stream',
            ], $ttl, $meta);
        } catch (Throwable $e) {
            return ['success' => false, 'status' => 0, 'expired' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Envia apenas o sinal Web Push. O conteúdo é buscado pelo Service Worker
     * no próprio servidor, eliminando incompatibilidades de criptografia de payload.
     */
    public function sendSignal(array $subscription, int $ttl = 300, array $meta = []): array
    {
        if (empty($subscription['endpoint'])) {
            return ['success' => false, 'status' => 0, 'expired' => false, 'error' => 'Assinatura sem endpoint.'];
        }

        $endpoint = (string) $subscription['endpoint'];
        $parts = parse_url($endpoint);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return ['success' => false, 'status' => 0, 'expired' => true, 'error' => 'Endpoint Push inválido.'];
        }

        try {
            return $this->pushRequest($endpoint, $parts, '', [], $ttl, $meta + ['subscription_id' => $subscription['id'] ?? null]);
        } catch (Throwable $e) {
            return ['success' => false, 'status' => 0, 'expired' => false, 'error' => $e->getMessage()];
        }
    }

    private function pushRequest(string $endpoint, array $parts, string $body, array $extraHeaders, int $ttl, array $meta = []): array
    {
        $audience = ($parts['scheme'] ?? 'https') . '://' . $parts['host'];
        if (!empty($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)) {
            $audience .= ':' . (int) $parts['port'];
        }
        $jwt = $this->createVapidJwt($audience);

        $headers = array_merge([
            'TTL: ' . max(0, min(86400, $ttl)),
            'Urgency: normal',
            'Authorization: vapid t=' . $jwt . ', k=' . $this->vapidPublicKey,
            'Content-Length: ' . strlen($body),
        ], $extraHeaders);

        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new RuntimeException('Não foi possível iniciar o cURL.');
        }
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'AlertaWiFiMVP/1.0.2',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_WHATEVER,
        ];
        curl_setopt_array($ch, $options);
        $started = microtime(true);
        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $durationMs = (int) ((microtime(true) - $started) * 1000);
        curl_close($ch);

        $success = in_array($status, [201, 202], true) || ($status >= 200 && $status < 300);
        $expired = in_array($status, [404, 410], true);
        $error = $success ? null : ($curlError ?: ('Serviço Push retornou HTTP ' . $status . ($responseBody ? ': ' . mb_substr((string) $responseBody, 0, 300) : '')));

        $provider = $parts['host'] ?? 'desconhecido';
        Logger::info('push_attempt', $meta + ['endpoint' => $endpoint, 'provider' => $provider, 'http_code' => $status, 'duration_ms' => $durationMs, 'curl_error' => $curlError ?: null, 'provider_response' => $responseBody ? mb_substr((string)$responseBody, 0, 500) : null, 'attempt' => $meta['attempt'] ?? 1], 'push');
        $this->writeDiagnosticLog($provider, $status, $error);

        return [
            'success' => $success,
            'status' => $status,
            'expired' => $expired,
            'error' => $error,
        ];
    }

    private function writeDiagnosticLog(string $host, int $status, ?string $error): void
    {
        $root = dirname(__DIR__);
        $line = sprintf(
            "[%s] host=%s http=%d result=%s%s\n",
            gmdate('c'),
            preg_replace('/[^a-z0-9.:-]/i', '', $host),
            $status,
            $error === null ? 'ok' : 'erro',
            $error === null ? '' : ' detail=' . str_replace(["\r", "\n"], ' ', mb_substr($error, 0, 500))
        );
        @file_put_contents($root . '/storage/logs/push.log', $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Implementação compacta dos RFCs 8188/8291 para uma mensagem aes128gcm de registro único.
     */
    public function encryptPayload(string $payload, string $clientPublicKey, string $authSecret): string
    {
        if (strlen($clientPublicKey) !== 65 || $clientPublicKey[0] !== "\x04") {
            throw new RuntimeException('Chave pública p256dh inválida.');
        }
        if (strlen($authSecret) < 16) {
            throw new RuntimeException('Segredo auth inválido.');
        }

        $serverKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($serverKey === false) {
            throw new RuntimeException('Não foi possível gerar a chave efêmera de criptografia.');
        }
        $serverDetails = openssl_pkey_get_details($serverKey);
        if (!is_array($serverDetails) || empty($serverDetails['ec']['x']) || empty($serverDetails['ec']['y'])) {
            throw new RuntimeException('OpenSSL sem suporte adequado à curva prime256v1.');
        }
        $serverPublicKey = "\x04" . $serverDetails['ec']['x'] . $serverDetails['ec']['y'];

        $clientPem = self::publicKeyRawToPem($clientPublicKey);
        $clientKey = openssl_pkey_get_public($clientPem);
        if ($clientKey === false) {
            throw new RuntimeException('Não foi possível importar a chave pública do navegador.');
        }

        $sharedSecret = openssl_pkey_derive($clientKey, $serverKey, 32);
        if ($sharedSecret === false || strlen($sharedSecret) !== 32) {
            throw new RuntimeException('Falha ao derivar o segredo ECDH.');
        }

        $keyInfo = "WebPush: info\x00" . $clientPublicKey . $serverPublicKey;
        $inputKeyMaterial = self::hkdf($sharedSecret, $authSecret, $keyInfo, 32);
        $salt = random_bytes(16);
        $contentEncryptionKey = self::hkdf($inputKeyMaterial, $salt, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = self::hkdf($inputKeyMaterial, $salt, "Content-Encoding: nonce\x00", 12);

        // 0x02 marca o último registro no content coding aes128gcm.
        $plaintext = $payload . "\x02";
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-128-gcm',
            $contentEncryptionKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16
        );
        if ($ciphertext === false) {
            throw new RuntimeException('Falha ao criptografar a notificação.');
        }

        $recordSize = 4096;
        return $salt . pack('N', $recordSize) . chr(strlen($serverPublicKey)) . $serverPublicKey . $ciphertext . $tag;
    }

    private function createVapidJwt(string $audience): string
    {
        $header = self::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_UNESCAPED_SLASHES));
        $claims = self::base64UrlEncode(json_encode([
            'aud' => $audience,
            'exp' => time() + 43200,
            'sub' => $this->vapidSubject,
        ], JSON_UNESCAPED_SLASHES));
        $unsigned = $header . '.' . $claims;

        if (!openssl_sign($unsigned, $derSignature, $this->vapidPrivatePem, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Falha ao assinar o token VAPID.');
        }
        $rawSignature = self::ecdsaDerToJose($derSignature, 64);
        return $unsigned . '.' . self::base64UrlEncode($rawSignature);
    }

    private static function hkdf(string $inputKeyMaterial, string $salt, string $info, int $length): string
    {
        $hashLength = 32;
        $pseudoRandomKey = hash_hmac('sha256', $inputKeyMaterial, $salt, true);
        $output = '';
        $previous = '';
        $blocks = (int) ceil($length / $hashLength);
        for ($i = 1; $i <= $blocks; $i++) {
            $previous = hash_hmac('sha256', $previous . $info . chr($i), $pseudoRandomKey, true);
            $output .= $previous;
        }
        return substr($output, 0, $length);
    }

    private static function publicKeyRawToPem(string $raw): string
    {
        // SubjectPublicKeyInfo para id-ecPublicKey + prime256v1.
        $prefix = hex2bin('3059301306072A8648CE3D020106082A8648CE3D030107034200');
        $der = $prefix . $raw;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function ecdsaDerToJose(string $der, int $outputLength): string
    {
        $offset = 0;
        if (ord($der[$offset++]) !== 0x30) {
            throw new RuntimeException('Assinatura ECDSA DER inválida.');
        }
        self::readDerLength($der, $offset);
        if (ord($der[$offset++]) !== 0x02) {
            throw new RuntimeException('Assinatura ECDSA sem componente R.');
        }
        $rLength = self::readDerLength($der, $offset);
        $r = substr($der, $offset, $rLength);
        $offset += $rLength;
        if (ord($der[$offset++]) !== 0x02) {
            throw new RuntimeException('Assinatura ECDSA sem componente S.');
        }
        $sLength = self::readDerLength($der, $offset);
        $s = substr($der, $offset, $sLength);

        $partLength = intdiv($outputLength, 2);
        $r = str_pad(ltrim($r, "\x00"), $partLength, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), $partLength, "\x00", STR_PAD_LEFT);
        if (strlen($r) !== $partLength || strlen($s) !== $partLength) {
            throw new RuntimeException('Tamanho inesperado da assinatura ECDSA.');
        }
        return $r . $s;
    }

    private static function readDerLength(string $der, int &$offset): int
    {
        $length = ord($der[$offset++]);
        if (($length & 0x80) === 0) {
            return $length;
        }
        $count = $length & 0x7f;
        $length = 0;
        for ($i = 0; $i < $count; $i++) {
            $length = ($length << 8) | ord($der[$offset++]);
        }
        return $length;
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Valor Base64URL inválido.');
        }
        return $decoded;
    }
}
