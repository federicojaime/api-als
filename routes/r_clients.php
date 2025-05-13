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




// Añadir a routes/r_clients.php

// POST - Crear nuevo subcliente
// POST - Crear nuevo subcliente
$app->post("/clients/{id:[0-9]+}/subclient", function (Request $request, Response $response, array $args) {
    $parentId = $args['id'];
    $fields = $request->getParsedBody();

    $verificar = [
        "business_name" => [
            "type" => "string",
            "min" => 3,
            "max" => 100
        ]
    ];

    // Solo validar tax_id si se proporciona
    if (isset($fields['tax_id']) && !empty($fields['tax_id'])) {
        $verificar['tax_id'] = [
            "type" => "string",
            "min" => 8,
            "max" => 20
        ];
    }

    $validacion = new Validate($this->get("db"));
    $validacion->validar($fields, $verificar);

    if ($validacion->hasErrors()) {
        $resp = $validacion->getErrors();
        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(409);
    }

    // Asegurarse de que el cliente padre existe
    $pdo = $this->get("db");
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE id = :id");
    $stmt->execute(['id' => $parentId]);

    if (!$stmt->fetch()) {
        $resp = new \stdClass();
        $resp->ok = false;
        $resp->msg = "El cliente padre no existe";
        $resp->data = null;

        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(404);
    }

    // Crear el subclient
    $query = "INSERT INTO subclients (
        client_id, name, business_name, tax_id, created_at, updated_at
    ) VALUES (
        :client_id, :name, :business_name, :tax_id, NOW(), NOW()
    )";

    $stmt = $pdo->prepare($query);
    $stmt->execute([
        'client_id' => $parentId,
        'name' => $fields['name'] ?? $fields['business_name'],
        'business_name' => $fields['business_name'],
        'tax_id' => $fields['tax_id'] ?? null
    ]);

    $newId = $pdo->lastInsertId();
    // Obtener el subclient recién creado
    $query = "SELECT * FROM subclients WHERE id = :id";
    $stmt = $pdo->prepare($query);
    $stmt->execute(['id' => $newId]);
    $subclient = $stmt->fetch(\PDO::FETCH_OBJ);

    $resp = new \stdClass();
    $resp->ok = true;
    $resp->msg = "Subclient creado correctamente";
    $resp->data = $subclient;

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus(200);
});

// GET - Obtener subclientes de un cliente específico
$app->get("/clients/{id:[0-9]+}/subclients", function (Request $request, Response $response, array $args) {
    $clientId = $args['id'];

    // Verificar que el cliente existe
    $pdo = $this->get("db");
    $checkStmt = $pdo->prepare("SELECT id FROM clients WHERE id = :id");
    $checkStmt->execute(['id' => $clientId]);

    if (!$checkStmt->fetch()) {
        $resp = new \stdClass();
        $resp->ok = false;
        $resp->msg = "Cliente no encontrado";
        $resp->data = [];

        $response->getBody()->write(json_encode($resp));
        return $response
            ->withHeader("Content-Type", "application/json")
            ->withStatus(404);
    }

    // Obtener subclientes
    $query = "SELECT id, name, business_name, tax_id FROM subclients WHERE client_id = :client_id ORDER BY business_name, name";
    $stmt = $pdo->prepare($query);
    $stmt->execute(['client_id' => $clientId]);
    $subclients = $stmt->fetchAll(\PDO::FETCH_OBJ);

    $resp = new \stdClass();
    $resp->ok = true;
    $resp->msg = "";
    $resp->data = $subclients;

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus(200);
});
