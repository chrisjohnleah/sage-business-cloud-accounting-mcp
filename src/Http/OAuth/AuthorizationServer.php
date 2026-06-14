<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp\Http\OAuth;

use ChrisJohnLeah\SageAccounting\Mcp\Http\HttpResult;
use Throwable;

/**
 * The MCP server's built-in OAuth 2.1 authorization server, bridging to Sage.
 *
 * - register():     RFC 7591 dynamic client registration (public native client).
 * - authorize():    validate client + PKCE, stash the request, redirect to Sage.
 * - sageCallback(): exchange the Sage code (persists the Sage token), mint our
 *                   single-use code, redirect back to the MCP client.
 * - token():        authorization_code + refresh_token grants (PKCE enforced).
 *
 * The tokens it issues only gate the MCP endpoint; the Sage connection lives in
 * the Sage token store, populated during sageCallback().
 */
final class AuthorizationServer
{
    private const int AUTH_CODE_TTL = 60;

    private const int PENDING_TTL = 600;

    private const int ACCESS_TTL = 3600;

    public function __construct(
        private readonly OAuthStore $store,
        private readonly SageBridgeContract $sage,
        private readonly string $origin,
    ) {
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function register(array $body, int $now): HttpResult
    {
        $redirectUris = [];

        $rawUris = $body['redirect_uris'] ?? null;
        if (is_array($rawUris)) {
            foreach ($rawUris as $uri) {
                if (is_string($uri) && $uri !== '') {
                    $redirectUris[] = $uri;
                }
            }
        }

        if ($redirectUris === []) {
            return HttpResult::json([
                'error' => 'invalid_client_metadata',
                'error_description' => 'At least one redirect_uri is required.',
            ], 400);
        }

        $name = self::param($body, 'client_name');
        $client = $this->store->registerClient($redirectUris, $name, $now);

        return HttpResult::json([
            'client_id' => $client['client_id'],
            'redirect_uris' => $client['redirect_uris'],
            'client_name' => $client['client_name'],
            'client_id_issued_at' => $client['client_id_issued_at'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function authorize(array $query, int $now): HttpResult
    {
        $clientId = self::param($query, 'client_id');
        $redirectUri = self::param($query, 'redirect_uri');
        $responseType = self::param($query, 'response_type');
        $codeChallenge = self::param($query, 'code_challenge');
        $method = self::param($query, 'code_challenge_method') ?? 'S256';
        $state = self::param($query, 'state') ?? '';
        $scope = self::param($query, 'scope') ?? Metadata::SCOPE;

        if ($clientId === null) {
            return self::errorPage('Missing client_id.');
        }

        $client = $this->store->getClient($clientId);

        if ($client === null) {
            return self::errorPage('Unknown client_id. Re-add the MCP server so it can register.');
        }

        // Until the redirect_uri is verified against the registered set, errors
        // must be shown here rather than redirected (open-redirect protection).
        if ($redirectUri === null || ! in_array($redirectUri, $client['redirect_uris'], true)) {
            return self::errorPage('The redirect_uri is not registered for this client.');
        }

        if ($responseType !== 'code') {
            return self::redirectError($redirectUri, $state, 'unsupported_response_type');
        }

        if ($codeChallenge === null) {
            return self::redirectError($redirectUri, $state, 'invalid_request', 'code_challenge is required.');
        }

        if (strtoupper($method) !== 'S256') {
            return self::redirectError($redirectUri, $state, 'invalid_request', 'Only the S256 PKCE method is supported.');
        }

        $bridgeState = $this->store->createPendingAuthorization([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'scope' => $scope,
            'state' => $state,
        ], self::PENDING_TTL, $now);

        $sageUrl = $this->sage->authorizationUrl($this->sageRedirectUri(), $bridgeState);

        return HttpResult::redirect($sageUrl);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function sageCallback(array $query, int $now): HttpResult
    {
        if (($error = self::param($query, 'error')) !== null) {
            return self::errorPage('Sage authorisation failed: '.$error);
        }

        $bridgeState = self::param($query, 'state');
        $code = self::param($query, 'code');

        if ($bridgeState === null) {
            return self::errorPage('Missing state on the Sage callback.');
        }

        $pending = $this->store->consumePendingAuthorization($bridgeState, $now);

        if ($pending === null) {
            return self::errorPage('Authorization request expired or already used. Start the connection again.');
        }

        $clientRedirect = self::param($pending, 'redirect_uri');
        $clientId = self::param($pending, 'client_id');
        $claudeState = self::param($pending, 'state') ?? '';

        if ($clientRedirect === null || $clientId === null) {
            return self::errorPage('Corrupt authorization state.');
        }

        if ($code === null) {
            return self::redirectError($clientRedirect, $claudeState, 'invalid_request', 'No authorization code from Sage.');
        }

        try {
            $this->sage->exchange($this->sageRedirectUri(), $code);
        } catch (Throwable $exception) {
            return self::redirectError($clientRedirect, $claudeState, 'access_denied', 'Sage token exchange failed: '.$exception->getMessage());
        }

        $ourCode = $this->store->createAuthCode([
            'client_id' => $clientId,
            'redirect_uri' => $clientRedirect,
            'code_challenge' => self::param($pending, 'code_challenge') ?? '',
            'code_challenge_method' => self::param($pending, 'code_challenge_method') ?? 'S256',
            'scope' => self::param($pending, 'scope') ?? Metadata::SCOPE,
        ], self::AUTH_CODE_TTL, $now);

        $location = $clientRedirect
            .(str_contains($clientRedirect, '?') ? '&' : '?')
            .http_build_query(['code' => $ourCode, 'state' => $claudeState]);

        return HttpResult::redirect($location);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function token(array $body, int $now): HttpResult
    {
        return match (self::param($body, 'grant_type')) {
            'authorization_code' => $this->tokenFromAuthCode($body, $now),
            'refresh_token' => $this->tokenFromRefresh($body, $now),
            default => HttpResult::json(['error' => 'unsupported_grant_type'], 400),
        };
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function tokenFromAuthCode(array $body, int $now): HttpResult
    {
        $code = self::param($body, 'code');
        $verifier = self::param($body, 'code_verifier');
        $clientId = self::param($body, 'client_id');
        $redirectUri = self::param($body, 'redirect_uri');

        if ($code === null || $verifier === null) {
            return HttpResult::json(['error' => 'invalid_request', 'error_description' => 'code and code_verifier are required.'], 400);
        }

        $record = $this->store->consumeAuthCode($code, $now);

        if ($record === null) {
            return HttpResult::json(['error' => 'invalid_grant', 'error_description' => 'Unknown or expired authorization code.'], 400);
        }

        $recordClientId = self::param($record, 'client_id');
        $recordRedirect = self::param($record, 'redirect_uri');

        if ($clientId !== null && $recordClientId !== $clientId) {
            return HttpResult::json(['error' => 'invalid_grant', 'error_description' => 'client_id mismatch.'], 400);
        }

        if ($redirectUri !== null && $recordRedirect !== $redirectUri) {
            return HttpResult::json(['error' => 'invalid_grant', 'error_description' => 'redirect_uri mismatch.'], 400);
        }

        $challenge = self::param($record, 'code_challenge') ?? '';
        $challengeMethod = self::param($record, 'code_challenge_method') ?? 'S256';

        if (! Pkce::verify($verifier, $challenge, $challengeMethod)) {
            return HttpResult::json(['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed.'], 400);
        }

        return $this->issue($recordClientId ?? '', self::param($record, 'scope') ?? Metadata::SCOPE, $now);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function tokenFromRefresh(array $body, int $now): HttpResult
    {
        $refreshToken = self::param($body, 'refresh_token');

        if ($refreshToken === null) {
            return HttpResult::json(['error' => 'invalid_request', 'error_description' => 'refresh_token is required.'], 400);
        }

        $clientId = $this->store->consumeRefreshToken($refreshToken);

        if ($clientId === null) {
            return HttpResult::json(['error' => 'invalid_grant', 'error_description' => 'Unknown refresh token.'], 400);
        }

        return $this->issue($clientId, Metadata::SCOPE, $now);
    }

    private function issue(string $clientId, string $scope, int $now): HttpResult
    {
        $tokens = $this->store->issueTokens($clientId, self::ACCESS_TTL, $now);

        return HttpResult::json([
            'access_token' => $tokens['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => $tokens['expires_in'],
            'refresh_token' => $tokens['refresh_token'],
            'scope' => $scope,
        ], 200, ['Cache-Control' => 'no-store']);
    }

    private function sageRedirectUri(): string
    {
        return $this->origin.'/sage/callback';
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function param(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function redirectError(string $redirectUri, string $state, string $error, ?string $description = null): HttpResult
    {
        $params = ['error' => $error, 'state' => $state];

        if ($description !== null) {
            $params['error_description'] = $description;
        }

        $location = $redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').http_build_query($params);

        return HttpResult::redirect($location);
    }

    private static function errorPage(string $message): HttpResult
    {
        $html = '<!doctype html><meta charset="utf-8"><title>Sage MCP</title>'
            .'<body style="font-family:system-ui,sans-serif;margin:3rem;color:#1a1a1a">'
            .'<h1>Sage MCP — authorization error</h1><p>'.htmlspecialchars($message, ENT_QUOTES).'</p></body>';

        return HttpResult::html($html, 400);
    }
}
