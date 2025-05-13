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


// GET - Obtener subclientes de un cliente específico
$app->get("/clients/{id:[0-9]+}/subclients", function (Request $request, Response $response, array $args) {
    // Esta ruta debería estar conectada a una función que obtiene los subclientes, 
    // pero como no veo la estructura de subclientes en la API actual, sugiero crearla.
    // Por ahora, devolvemos un formato básico que el frontend ya espera:

    $pdo = $this->get("db");
    $query = "SELECT id, name, business_name, tax_id FROM subclients WHERE client_id = :client_id";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':client_id', $args['id'], PDO::PARAM_INT);
    $stmt->execute();
    $subclients = $stmt->fetchAll(PDO::FETCH_OBJ);

    $resp = new \stdClass();
    $resp->ok = true;
    $resp->msg = "";
    $resp->data = $subclients;

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus(200);
});
