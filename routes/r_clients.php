<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use objects\Clients;
use utils\Validate;

// GET - Obtener todos los clientes
$app->get("/clients", function (Request $request, Response $response, array $args) {
    $params = $request->getQueryParams();
    $clients = new Clients($this->get("db"));
    $resp = $clients->getClients($params)->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// GET - Obtener un cliente específico
$app->get("/client/{id:[0-9]+}", function (Request $request, Response $response, array $args) {
    $clients = new Clients($this->get("db"));
    $resp = $clients->getClient($args["id"])->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// POST - Crear nuevo cliente
$app->post("/client", function (Request $request, Response $response, array $args) {
    $fields = $request->getParsedBody();

    $verificar = [
        "business_name" => [
            "type" => "string",
            "min" => 3,
            "max" => 100
        ],
        "tax_id" => [
            "type" => "string",
            "min" => 8,
            "max" => 20,
            "unique" => "clients"
        ]
    ];

    $validacion = new Validate($this->get("db"));
    $validacion->validar($fields, $verificar);

    if ($validacion->hasErrors()) {
        $resp = $validacion->getErrors();
        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(409);
    }

    $clients = new Clients($this->get("db"));
    $resp = $clients->createClient((object)$fields)->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// PUT - Actualizar cliente
$app->put("/client/{id:[0-9]+}", function (Request $request, Response $response, array $args) {
    $fields = $request->getParsedBody();

    $verificar = [
        "business_name" => [
            "type" => "string",
            "min" => 3,
            "max" => 100
        ],
        "tax_id" => [
            "type" => "string",
            "min" => 8,
            "max" => 20,
            "unique" => "clients",
            "unique_ignore" => $args["id"]
        ]
    ];

    $validacion = new Validate($this->get("db"));
    $validacion->validar($fields, $verificar);

    if ($validacion->hasErrors()) {
        $resp = $validacion->getErrors();
        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(409);
    }

    $clients = new Clients($this->get("db"));
    $resp = $clients->updateClient($args["id"], (object)$fields)->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// DELETE - Eliminar cliente
$app->delete("/client/{id:[0-9]+}", function (Request $request, Response $response, array $args) {
    $clients = new Clients($this->get("db"));
    $resp = $clients->deleteClient($args["id"])->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});
