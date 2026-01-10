<?php
class CrowdSecAPI {
    private $baseUrl;
    private $username;
    private $password;
    private $token;
    private $apiKey;
    private $userAgent = 'curl/8.14.1';
    private $tokenFile = __DIR__ . '/../cache/token.json';
    private $debug = true; // Zapni debug mode
    
    public function __construct() {
        $env = $this->loadEnv();
        $this->baseUrl = rtrim($env['CROWDSEC_URL'] ?? 'http://localhost:8080', '/');
        $this->username = $env['CROWDSEC_USER'] ?? '';
        $this->password = $env['CROWDSEC_PASSWORD'] ?? '';
        $this->apiKey = $env['CROWDSEC_API_KEY'] ?? ($env['CROWDSEC_BOUNCER_KEY'] ?? '');
        
        if ($this->username !== '' && $this->password !== '') {
            $this->apiKey = '';
        }
        
        if (!$this->apiKey) {
            $this->loadToken();
        }
    }
    
    private function debug($message) {
        if ($this->debug) {
            error_log('[CrowdSec] ' . $message);
        }
    }
    
    private function loadEnv() {
        $env = [];
        $envFile = __DIR__ . '/../.env';
        
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, '#') === 0) continue;
                
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $key = trim($parts[0]);
                    $value = trim($parts[1]);
                    // Remove quotes if present
                    $value = trim($value, '"\'');
                    $env[$key] = $value;
                }
            }
        }
        
        return $env;
    }
    
    private function loadToken() {
        if ($this->apiKey) {
            return;
        }

        if (file_exists($this->tokenFile)) {
            $data = json_decode(file_get_contents($this->tokenFile), true);
            if ($data && isset($data['token']) && isset($data['expires'])) {
                if (time() < $data['expires']) {
                    $this->token = $data['token'];
                    $this->debug("Loaded valid token from cache");
                    return;
                } else {
                    $this->debug("Token expired, will get new one");
                }
            }
        }
        
        $this->login();
    }
    
    private function decodeJwtPayload($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        
        $payload = base64_decode(strtr($parts[1], '-_', '+/'));
        return json_decode($payload, true);
    }
    
    private function saveToken($token) {
        $dir = dirname($this->tokenFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $payload = $this->decodeJwtPayload($token);
        $expiresAt = null;
        
        if ($payload && isset($payload['exp'])) {
            $expiresAt = (int)$payload['exp'];
        } else {
            $expiresAt = time() + 3600;
        }
        
        $data = [
            'token' => $token,
            'expires' => $expiresAt - 60,
            'created_at' => time()
        ];
        
        file_put_contents($this->tokenFile, json_encode($data, JSON_PRETTY_PRINT));
        $this->debug("Token saved, expires at: " . date('Y-m-d H:i:s', $expiresAt));
    }
    
    public function login() {
        if ($this->apiKey) {
            return true;
        }

        if ($this->username === '' || $this->password === '') {
            throw new Exception('CrowdSec credentials are missing.');
        }

        $this->debug("Attempting login for machine: {$this->username}");
        
        $url = $this->baseUrl . '/v1/watchers/login';
        
        $data = [
            'machine_id' => $this->username,
            'password' => $this->password
        ];
        
        // Debug credentials (mask password)
        $this->debug("Login URL: $url");
        $this->debug("Machine ID: {$this->username}");
        $this->debug("Password length: " . strlen($this->password));
        
        $response = $this->request('POST', $url, $data, false);
        
        if (isset($response['token'])) {
            $this->token = $response['token'];
            $this->saveToken($this->token);
            $this->debug("Login successful, token received");
            return true;
        }
        
        $errorMsg = $response['message'] ?? json_encode($response);
        $this->debug("Login failed: $errorMsg");
        throw new Exception('Failed to authenticate with CrowdSec LAPI: ' . $errorMsg);
    }
    
    private function request($method, $url, $data = null, $useAuth = true) {
        $ch = curl_init();
        
        $headers = ['Content-Type: application/json'];
        if ($useAuth && $this->apiKey) {
            $headers[] = 'X-Api-Key: ' . $this->apiKey;
            $this->debug("Using API Key authentication");
        } elseif ($useAuth && $this->token) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
            $this->debug("Using JWT Bearer token authentication");
        }
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);
        
        if ($method === 'POST') {
            $jsonData = json_encode($data, JSON_UNESCAPED_SLASHES);
            $this->debug("POST Request to: $url");
            $this->debug("JSON Data: $jsonData");
            
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            $this->debug("DELETE Request to: $url");
        } else {
            $this->debug("GET Request to: $url");
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        $this->debug("Response Code: $httpCode");
        if ($response) {
            $this->debug("Response Body: " . substr($response, 0, 500));
        }
        
        // Handle 401 only if not already in login process
        if ($httpCode === 401 && $useAuth && !$this->apiKey && strpos($url, '/watchers/login') === false) {
            $this->debug("Got 401, clearing token and re-authenticating");
            
            if (file_exists($this->tokenFile)) {
                unlink($this->tokenFile);
            }
            $this->token = null;
            
            $this->login();
            return $this->request($method, $url, $data, true);
        }
        
        if ($curlError) {
            $this->debug("CURL Error: $curlError");
            throw new Exception("CURL error: $curlError");
        }
        
        if ($httpCode >= 400) {
            $errorMsg = $response ?: "HTTP $httpCode";
            $this->debug("API Error: $errorMsg");
            throw new Exception("API request failed: $errorMsg");
        }
        
        return json_decode($response, true);
    }
    
    public function getAlerts($since = null, $until = null, $limit = 10000) {
        $url = $this->baseUrl . '/v1/alerts';
        $params = ['limit' => $limit];
        
        if ($since) $params['since'] = $since;
        if ($until) $params['until'] = $until;
        
        $url .= '?' . http_build_query($params);
        
        return $this->request('GET', $url);
    }
    
    public function getAlertById($id) {
        $url = $this->baseUrl . '/v1/alerts/' . $id;
        return $this->request('GET', $url);
    }
    
    public function deleteAlert($id) {
        $url = $this->baseUrl . '/v1/alerts/' . $id;
        return $this->request('DELETE', $url);
    }
    
    public function addDecision($ip, $type = 'ban', $duration = '4h', $reason = 'manual') {
        $url = $this->baseUrl . '/v1/alerts';
        
        $data = [[
            'created_at' => date('c'),
            'machine_id' => $this->username,
            'scenario' => $reason,
            'message' => "Manual ban from Web UI",
            'source' => [
                'scope' => 'Ip',
                'value' => $ip
            ],
            'decisions' => [[
                'duration' => $duration,
                'type' => $type,
                'scope' => 'Ip',
                'value' => $ip
            ]]
        ]];
        
        return $this->request('POST', $url, $data);
    }
    
    public function deleteDecision($id) {
        $url = $this->baseUrl . '/v1/decisions/' . $id;
        return $this->request('DELETE', $url);
    }
    
    public function getTokenInfo() {
        if (!$this->token) {
            return null;
        }
        
        $payload = $this->decodeJwtPayload($this->token);
        if (!$payload) {
            return null;
        }
        
        return [
            'machine_id' => $payload['id'] ?? null,
            'expires_at' => $payload['exp'] ?? null,
            'issued_at' => $payload['orig_iat'] ?? null,
            'expires_in' => isset($payload['exp']) ? ($payload['exp'] - time()) : null
        ];
    }
}
