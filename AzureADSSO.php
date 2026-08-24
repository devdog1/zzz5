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
    /**
     * Retrieve members of a specific Azure AD group using Microsoft Graph API.
     * Sanitizes group ID, resolves group Display Names to Object ID UUIDs,
     * and falls back to App Client Credentials token if delegated token has insufficient rights.
     *
     * @param string $groupId Group Object ID UUID or Group Display Name
     * @param string|null $accessToken Optional access token
     * @return array Array of user member objects
     */
    public function getGroupMembers($groupId, $accessToken = null)
    {
        $groupId = trim((string)$groupId);

        if (empty($groupId)) {
            $this->logAzureAction('AZURE_GET_GROUP_MEMBERS_SKIPPED', ['reason' => 'Empty Group ID provided']);
            return [];
        }

        // Detect if a JWT token was accidentally passed as $groupId
        if (str_starts_with($groupId, 'eyJ') || str_contains($groupId, '.') || strlen($groupId) > 150) {
            $this->logAzureAction('AZURE_GET_GROUP_MEMBERS_INVALID_INPUT', [
                'reason' => 'JWT access token string was passed as groupId parameter instead of Group Object ID/Name',
                'input_preview' => substr($groupId, 0, 30) . '...'
            ]);
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

        // Helper closure to acquire an App-Level Client Credentials Token
        $getAppToken = function() {
            $tokenUrl = "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token";
            $postFields = [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope'         => 'https://graph.microsoft.com/.default'
            ];
            $tokenRes = $this->makePostRequest($tokenUrl, $postFields);
            return $tokenRes['access_token'] ?? null;
        };

        if (empty($accessToken)) {
            $accessToken = $getAppToken();
        }

        if (empty($accessToken)) {
            $this->logAzureAction('AZURE_GET_GROUP_MEMBERS_FAILED', ['group_id' => $groupId, 'reason' => 'No OAuth access token available']);
            return [];
        }

        // If $groupId is not a UUID (format: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx), resolve Display Name to Object ID
        $isUuid = preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $groupId);
        $resolvedObjectId = $groupId;

        if (!$isUuid) {
            $filterUrl = "https://graph.microsoft.com/v1.0/groups?\$filter=" . urlencode("displayName eq '" . addslashes($groupId) . "'") . "&\$select=id,displayName";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $filterUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code == 200) {
                $filterData = json_decode($resp, true);
                if (!empty($filterData['value'][0]['id'])) {
                    $resolvedObjectId = $filterData['value'][0]['id'];
                }
            }
        }

        // Query Group Members using resolved Object ID
        $executeGroupQuery = function($token) use ($resolvedObjectId) {
            $graphUrl = "https://graph.microsoft.com/v1.0/groups/" . urlencode($resolvedObjectId) . "/members?\$select=id,displayName,userPrincipalName,mail";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $graphUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            return ['code' => $httpCode, 'response' => $response, 'error' => $curlError];
        };

        $res = $executeGroupQuery($accessToken);

        // If 401 (Invalid token) or 403 (Insufficient Privileges), retry once using App Client Credentials Token
        if (($res['code'] == 401 || $res['code'] == 403) && ($appToken = $getAppToken())) {
            $this->logAzureAction('AZURE_GET_GROUP_MEMBERS_RETRY_APP_TOKEN', [
                'group_id' => $groupId,
                'initial_code' => $res['code'],
                'reason' => 'User delegated token lacked privileges; retried with app-level token'
            ]);
            $res = $executeGroupQuery($appToken);
        }

        if ($res['code'] == 200) {
            $data = json_decode($res['response'], true);
            if (isset($data['value'])) {
                $members = $data['value'];
                $this->logAzureAction('AZURE_GET_GROUP_MEMBERS_SUCCESS', [
                    'group_identifier' => $groupId,
                    'resolved_object_id' => $resolvedObjectId,
                    'member_count' => count($members)
                ]);
                return $members;
            }
        }

        $this->logAzureAction('AZURE_GET_GROUP_MEMBERS_ERROR', [
            'group_identifier' => $groupId,
            'resolved_object_id' => $resolvedObjectId,
            'http_code' => $res['code'],
            'response' => $res['response'],
            'curl_error' => $res['error']
        ]);

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
