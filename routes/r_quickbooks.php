<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use objects\QuickBooks;

// GET - Obtener estado de conexión QuickBooks
$app->get("/quickbooks/status", function (Request $request, Response $response, array $args) {
    $qb = new QuickBooks($this->get("db"));
    $resp = $qb->getConfig()->getResult();

    if ($resp->ok && $resp->data) {
        $resp->data->is_connected = true;
        $resp->data->is_expired = $qb->areTokensExpired();
        // Removemos información sensible
        unset($resp->data->access_token);
        unset($resp->data->refresh_token);
    } else {
        $resp->data = [
            'is_connected' => false,
            'is_expired' => true
        ];
    }

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// POST - Procesar callback de OAuth QuickBooks
$app->post("/quickbooks/callback", function (Request $request, Response $response, array $args) {
    $fields = $request->getParsedBody();

    // Verificar que tenemos los campos necesarios
    if (!isset($fields['code']) || !isset($fields['realmId'])) {
        $resp = new \stdClass();
        $resp->ok = false;
        $resp->msg = "Parámetros incompletos";
        $resp->data = null;

        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(400);
    }

    try {
        // Intercambiar código por tokens
        $tokenEndpoint = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
        $client = new \GuzzleHttp\Client();

        $tokenResponse = $client->post($tokenEndpoint, [
            'form_params' => [
                'grant_type' => 'authorization_code',
                'code' => $fields['code'],
                'redirect_uri' => $_ENV['QB_REDIRECT_URI']
            ],
            'auth' => [
                $_ENV['QB_CLIENT_ID'],
                $_ENV['QB_CLIENT_SECRET']
            ]
        ]);

        $tokens = json_decode($tokenResponse->getBody());
        $tokens->realm_id = $fields['realmId'];

        // Guardar tokens
        $qb = new QuickBooks($this->get("db"));
        $resp = $qb->saveTokens($tokens)->getResult();

        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus($resp->ok ? 200 : 409);
    } catch (\Exception $e) {
        $resp = new \stdClass();
        $resp->ok = false;
        $resp->msg = $e->getMessage();
        $resp->data = null;

        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(500);
    }
});

// POST - Desconectar QuickBooks
$app->post("/quickbooks/disconnect", function (Request $request, Response $response, array $args) {
    $qb = new QuickBooks($this->get("db"));
    $resp = $qb->deleteConfig()->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// POST - Refrescar token
$app->post("/quickbooks/refresh", function (Request $request, Response $response, array $args) {
    try {
        $qb = new QuickBooks($this->get("db"));
        $config = $qb->getConfig()->getResult();

        if (!$config->ok || !$config->data) {
            throw new \Exception("No hay configuración de QuickBooks");
        }

        // Refrescar token
        $tokenEndpoint = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
        $client = new \GuzzleHttp\Client();

        $tokenResponse = $client->post($tokenEndpoint, [
            'form_params' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $config->data->refresh_token
            ],
            'auth' => [
                $_ENV['QB_CLIENT_ID'],
                $_ENV['QB_CLIENT_SECRET']
            ]
        ]);

        $tokens = json_decode($tokenResponse->getBody());
        $tokens->realm_id = $config->data->realm_id;

        // Guardar nuevos tokens
        $resp = $qb->saveTokens($tokens)->getResult();

        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus($resp->ok ? 200 : 409);
    } catch (\Exception $e) {
        $resp = new \stdClass();
        $resp->ok = false;
        $resp->msg = $e->getMessage();
        $resp->data = null;

        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(500);
    }
});
