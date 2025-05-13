<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use objects\ItemDescriptions;
use utils\Validate;

// GET - Obtener todas las descripciones predefinidas
$app->get("/item-descriptions", function (Request $request, Response $response, array $args) {
    $descriptions = new ItemDescriptions($this->get("db"));
    $resp = $descriptions->getAllDescriptions()->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// POST - Agregar una nueva descripción
$app->post("/item-description", function (Request $request, Response $response, array $args) {
    $fields = $request->getParsedBody();

    $verificar = [
        "description" => [
            "type" => "string",
            "min" => 2,
            "max" => 100
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

    $descriptions = new ItemDescriptions($this->get("db"));
    $resp = $descriptions->addDescription($fields["description"])->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// DELETE - Eliminar una descripción (solo las no predefinidas)
$app->delete("/item-description/{id:[0-9]+}", function (Request $request, Response $response, array $args) {
    $descriptions = new ItemDescriptions($this->get("db"));
    $resp = $descriptions->deleteDescription($args["id"])->getResult();

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});