<?php
class CrowdSecAPI {
    private $baseUrl;
    private $username;
    private $password;
    private $token;
    private $apiKey;
    private $userAgent = 'crowdsec-admin';
    private $tokenFile = __DIR__ . '/../cache/token.json';
    
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
    
    private function loadEnv() {
        $env = [];
        $envFile = __DIR__ . '/../.env';
        
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                list($key, $value) = explode('=', $line, 2);
                $env[trim($key)] = trim($value);
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
                    return;
                }
            }
        }
        $this->login();
    }
    
    private function saveToken($token, $expiresIn = 3600) {
        $dir = dirname($this->tokenFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $data = [
            'token' => $token,
            'expires' => time() + $expiresIn - 60 // 60s buffer
        ];
        
        file_put_contents($this->tokenFile, json_encode($data));
    }
    
    public function login() {
        if ($this->apiKey) {
            return true;
        }

        if ($this->username === '' || $this->password === '') {
            throw new Exception('CrowdSec credentials are missing.');
        }

        $url = $this->baseUrl . '/v1/watchers/login';
        
        $data = [
            'machine_id' => $this->username,
            'password' => $this->password
        ];
        
        $response = $this->request('POST', $url, $data, false);
        
        if (isset($response['token'])) {
            $this->token = $response['token'];
            $this->saveToken($this->token);
            return true;
        }
        
        throw new Exception('Failed to authenticate with CrowdSec LAPI');
    }
    
    private function request($method, $url, $data = null, $useAuth = true) {
        $ch = curl_init();
        
        $headers = ['Content-Type: application/json'];
        if ($useAuth && $this->apiKey) {
            $headers[] = 'X-Api-Key: ' . $this->apiKey;
        } elseif ($useAuth && $this->token) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_TIMEOUT => 30
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 401 && $useAuth && !$this->apiKey) {
            $this->login();
            return $this->request($method, $url, $data, true);
        }
        
        if ($httpCode >= 400) {
            throw new Exception("API request failed with status $httpCode: $response");
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
}
