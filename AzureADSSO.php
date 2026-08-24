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
        $curlError = curl_error($ch);
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

            $this->logAzureAction('AZURE_GET_USER_GROUPS_SUCCESS', ['count' => count($groupNames), 'groups' => $groupNames]);
            return $groupNames;
        }

        $this->logAzureAction('AZURE_GET_USER_GROUPS_ERROR', ['http_code' => $httpCode, 'response' => $response, 'curl_error' => $curlError]);
        return [];
    }

    /**
     * Retrieve members of a specific Azure AD group using Microsoft Graph API.
     * Strictly queries live Graph API without mock fallbacks.
     *
     * @param string $groupId Group Object ID or Group Display Name
     * @param string|null $accessToken Optional access token (if null, checks session locations or gets app token)
     * @return array Array of user member objects
     */
    public function getGroupMembers($groupId, $accessToken = null)
    {
        if (empty($groupId)) {
            $this->logAzureAction('AZURE_GET_GROUP_MEMBERS_SKIPPED', ['reason' => 'Empty Group ID provided']);
            return [];
        }

        // Determine access token if not passed explicitly
        if (empty($accessToken)) {
            if (function_exists('get_azure_access_token')) {
                $accessToken = get_azure_access_token();
            } else {
                $accessToken = $_SESSION['user']['access_token'] ?? $_SESSION['access_token'] ?? $_SESSION['azure_access_token'] ?? $_SESSION['tokens']['access_token'] ?? null;
            }
        }

        // If no user access token found, acquire app-level token via Client Credentials Grant
        if (empty($accessToken)) {
            $tokenUrl = "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token";
            $postFields = [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope'         => 'https://graph.microsoft.com/.default'
            ];
            $tokenRes = $this->makePostRequest($tokenUrl, $postFields);
            if ($tokenRes && !empty($tokenRes['access_token'])) {
                $accessToken = $tokenRes['access_token'];
            }
        }

        if (empty($accessToken)) {
            $this->logAzureAction('AZURE_GET_GROUP_MEMBERS_FAILED', ['group_id' => $groupId, 'reason' => 'No OAuth access token available']);
            return [];
        }

        $graphUrl = "https://graph.microsoft.com/v1.0/groups/" . urlencode($groupId) . "/members?\$select=id,displayName,userPrincipalName,mail";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $graphUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode == 200) {
            $data = json_decode($response, true);
            if (isset($data['value'])) {
                $members = $data['value'];
                $this->logAzureAction('AZURE_GET_GROUP_MEMBERS_SUCCESS', ['group_id' => $groupId, 'member_count' => count($members)]);
                return $members;
            }
        }

        $this->logAzureAction('AZURE_GET_GROUP_MEMBERS_ERROR', ['group_id' => $groupId, 'http_code' => $httpCode, 'response' => $response, 'curl_error' => $curlError]);
        return [];
    }

    public function getAllGroups()
    {
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
            $this->logAzureAction('AZURE_GET_ALL_GROUPS_TOKEN_FAILED', ['tenant_id' => $this->tenantId, 'client_id' => $this->clientId]);
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
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode == 200) {
            $data = json_decode($response, true);
            if (isset($data['value'])) {
                $groups = $data['value'];
                $this->logAzureAction('AZURE_GET_ALL_GROUPS_SUCCESS', ['group_count' => count($groups)]);
                return $groups;
            }
        }

        $this->logAzureAction('AZURE_GET_ALL_GROUPS_ERROR', ['http_code' => $httpCode, 'response' => $response, 'curl_error' => $curlError]);
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
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode == 200) {
            return json_decode($response, true);
        }

        $this->logAzureAction('AZURE_POST_REQUEST_ERROR', ['url' => $url, 'http_code' => $httpCode, 'response' => $response, 'curl_error' => $curlError]);
        return null;
    }

    private function logAzureAction($action, $details)
    {
        if (function_exists('log_action')) {
            log_action($action, $details);
        }
        error_log("[AzureADSSO] {$action}: " . json_encode($details, JSON_UNESCAPED_SLASHES));
    }
}
