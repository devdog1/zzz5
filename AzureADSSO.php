<?php
// AzureADSSO.php - Handle Azure Active Directory SSO login flow.

class AzureADSSO
{
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $tenantId;
    private $authUrl;
    private $tokenUrl;
    private $scopes = "openid profile email offline_access Group.Read.All";
    private $logoutUrl = "https://login.microsoftonline.com/{tenant}/oauth2/v2.0/logout";

    public function __construct($clientId, $clientSecret, $redirectUri, $tenantId = 'common')
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;
        $this->tenantId = $tenantId;

        $this->authUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/authorize";
        $this->tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";
        $this->logoutUrl = str_replace("{tenant}", $tenantId, $this->logoutUrl);
    }

    public function getAuthUrl($state)
    {
        $queryParams = http_build_query([
            'client_id'     => $this->clientId,
            'response_type' => 'code',
            'redirect_uri'  => $this->redirectUri,
            'response_mode' => 'query',
            'scope'         => $this->scopes,
            'state'         => $state,
        ]);

        return $this->authUrl . '?' . $queryParams;
    }

    public function getAccessToken($authorizationCode)
    {
        $postFields = [
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code'          => $authorizationCode,
            'redirect_uri'  => $this->redirectUri,
            'scope'         => $this->scopes,
        ];

        return $this->makePostRequest($this->tokenUrl, $postFields);
    }

    public function getUserInfo($idToken)
    {
        list($header, $payload, $signature) = explode(".", $idToken);
        $decodedPayload = base64_decode(str_replace(['-', '_'], ['+', '/'], $payload));
        return json_decode($decodedPayload, true);
    }

    public function getLogoutUrl($postLogoutRedirectUri)
    {
        return $this->logoutUrl . '?post_logout_redirect_uri=' . urlencode($postLogoutRedirectUri);
    }

    public function getUserGroups($accessToken)
    {
        $graphUrl = "https://graph.microsoft.com/v1.0/me/memberOf";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $graphUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            $groupsData = json_decode($response, true);
            $groupNames = [];

            if (isset($groupsData['value'])) {
                foreach ($groupsData['value'] as $group) {
                    if (isset($group['displayName'])) {
                        $groupNames[] = $group['displayName'];
                    }
                }
            }

            return $groupNames;
        }

        return [];
    }

    public function getAllGroups()
    {
        // Fallback for mock/local setups without live Azure Tenant credentials
        if ($this->clientId === 'YOUR_AZURE_CLIENT_ID' || empty($this->clientSecret) || strpos($this->clientId, 'mock') !== false) {
            return [
                ['id' => '9f8e7d6c-5b4a-3f2e-1d0c-b9a8f7e6d5c4', 'displayName' => 'Portal-Administrators', 'description' => 'Global Portal Administrators Group'],
                ['id' => '1a2b3c4d-5e6f-7a8b-9c0d-1e2f3a4b5c6d', 'displayName' => 'Portal-Managers', 'description' => 'Department Managers Group'],
                ['id' => 'a1b2c3d4-e5f6-7a8b-9c0d-e1f2a3b4c5d6', 'displayName' => 'OnCall-Operators', 'description' => 'On-Call Roster Operators Group'],
                ['id' => 'f9e8d7c6-b5a4-3f2e-1d0c-b9a8f7e6d5c4', 'displayName' => 'All-Employees', 'description' => 'General Employee Directory Group']
            ];
        }

        // Fetch app-only access token using Client Credentials Grant
        $tokenUrl = "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token";
        $postFields = [
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope'         => 'https://graph.microsoft.com/.default'
        ];

        $tokenRes = $this->makePostRequest($tokenUrl, $postFields);
        if (!$tokenRes || empty($tokenRes['access_token'])) {
            return [];
        }

        $accessToken = $tokenRes['access_token'];
        $graphUrl = "https://graph.microsoft.com/v1.0/groups?\$select=id,displayName,description";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $graphUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            $data = json_decode($response, true);
            if (isset($data['value'])) {
                return $data['value'];
            }
        }

        return [];
    }

    private function makePostRequest($url, $postFields)
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            return json_decode($response, true);
        }

        return null;
    }
}
