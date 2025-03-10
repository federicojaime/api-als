<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use objects\Users;
use utils\Validate;

// POST - Login
$app->post("/login", function (Request $request, Response $response, array $args) {
    $fields = $request->getParsedBody();
    
    $verificar = [
        "email" => [
            "type" => "string",
            "isValidMail" => true
        ],
        "password" => [
            "type" => "string",
            "min" => 6
        ]
    ];

    $validacion = new Validate();
    $validacion->validar($fields, $verificar);

    $resp = null;

    if($validacion->hasErrors()) {
        $resp = $validacion->getErrors();
    } else {
        $users = new Users($this->get("db"));
        $resp = $users->authenticate($fields["email"], $fields["password"])->getResult();
    }

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 401);
});

// GET - Obtener todos los usuarios
$app->get("/users", function (Request $request, Response $response, array $args) {
    $params = $request->getQueryParams();
    $users = new Users($this->get("db"));
    
    if (isset($params['role'])) {
        $resp = $users->getUsersByRole($params['role'])->getResult();
    } else {
        $resp = $users->getUsers()->getResult();
    }
    
    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// GET - Obtener un usuario específico
$app->get("/user/{id:[0-9]+}", function (Request $request, Response $response, array $args) {
    $users = new Users($this->get("db"));
    $resp = $users->getUser($args["id"])->getResult();
    
    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// POST - Crear nuevo usuario
$app->post("/user", function (Request $request, Response $response, array $args) {
    $fields = $request->getParsedBody();
    
    $verificar = [
        "email" => [
            "type" => "string",
            "isValidMail" => true,
            "unique" => "users"
        ],
        "firstname" => [
            "type" => "string",
            "min" => 3,
            "max" => 50
        ],
        "lastname" => [
            "type" => "string",
            "min" => 3,
            "max" => 50
        ],
        "password" => [
            "type" => "string",
            "min" => 6,
            "max" => 20
        ],
        "role" => [
            "type" => "string",
            "values" => ["admin", "transportista"]
        ]
    ];

    $validacion = new Validate($this->get("db"));
    $validacion->validar($fields, $verificar);

    $resp = null;

    if($validacion->hasErrors()) {
        $resp = $validacion->getErrors();
    } else {
        $users = new Users($this->get("db"));
        $resp = $users->setUser($fields)->getResult();
    }

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// PATCH - Actualizar usuario
$app->patch("/user/{id:[0-9]+}", function (Request $request, Response $response, array $args) {
    $fields = $request->getParsedBody();
    $fields['id'] = $args['id'];
    
    $verificar = [
        "id" => [
            "type" => "number",
            "min" => 1,
            "exist" => "users"
        ]
    ];

    if (isset($fields['email'])) {
        $verificar['email'] = [
            "type" => "string",
            "isValidMail" => true,
            "unique" => "users"
        ];
    }

    if (isset($fields['firstname'])) {
        $verificar['firstname'] = [
            "type" => "string",
            "min" => 3,
            "max" => 50
        ];
    }

    if (isset($fields['lastname'])) {
        $verificar['lastname'] = [
            "type" => "string",
            "min" => 3,
            "max" => 50
        ];
    }

    if (isset($fields['role'])) {
        $verificar['role'] = [
            "type" => "string",
            "values" => ["admin", "transportista"]
        ];
    }

    $validacion = new Validate($this->get("db"));
    $validacion->validar($fields, $verificar);

    $resp = null;

    if($validacion->hasErrors()) {
        $resp = $validacion->getErrors();
    } else {
        $users = new Users($this->get("db"));
        $resp = $users->updateUser($fields)->getResult();
    }

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// PATCH - Cambiar contraseña
$app->patch("/user/{id:[0-9]+}/password", function (Request $request, Response $response, array $args) {
    $fields = $request->getParsedBody();
    
    $verificar = [
        "current_password" => [
            "type" => "string",
            "min" => 6
        ],
        "new_password" => [
            "type" => "string",
            "min" => 6,
            "max" => 20
        ]
    ];

    $validacion = new Validate($this->get("db"));
    $validacion->validar($fields, $verificar);

    $resp = null;

    if($validacion->hasErrors()) {
        $resp = $validacion->getErrors();
    } else {
        $users = new Users($this->get("db"));
        $resp = $users->changePassword(
            $args["id"], 
            $fields["current_password"], 
            $fields["new_password"]
        )->getResult();
    }

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// POST - Iniciar recuperación de contraseña
$app->post("/user/password/recover", function (Request $request, Response $response, array $args) {
    $fields = $request->getParsedBody();
    
    $verificar = [
        "email" => [
            "type" => "string",
            "isValidMail" => true
        ]
    ];

    $validacion = new Validate($this->get("db"));
    $validacion->validar($fields, $verificar);

    $resp = null;

    if($validacion->hasErrors()) {
        $resp = $validacion->getErrors();
    } else {
        $users = new Users($this->get("db"));
        $resp = $users->initiatePasswordRecovery($fields["email"])->getResult();
        
        if($resp->ok) {
            // Aquí se enviaría el email con el token
            // Por ahora solo devolvemos el token en la respuesta
            $resp->msg = "Se ha enviado un correo con las instrucciones";
        }
    }

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// POST - Restablecer contraseña con token
$app->post("/user/password/reset", function (Request $request, Response $response, array $args) {
    $fields = $request->getParsedBody();
    
    $verificar = [
        "token" => [
            "type" => "string",
            "min" => 32,
            "max" => 32
        ],
        "new_password" => [
            "type" => "string",
            "min" => 6,
            "max" => 20
        ]
    ];

    $validacion = new Validate($this->get("db"));
    $validacion->validar($fields, $verificar);

    $resp = null;

    if($validacion->hasErrors()) {
        $resp = $validacion->getErrors();
    } else {
        $users = new Users($this->get("db"));
        $resp = $users->resetPassword(
            $fields["token"], 
            $fields["new_password"]
        )->getResult();
    }

    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});

// DELETE - Eliminar usuario (soft delete)
$app->delete("/user/{id:[0-9]+}", function (Request $request, Response $response, array $args) {
    $users = new Users($this->get("db"));
    $resp = $users->deleteUser($args["id"])->getResult();
    
    $response->getBody()->write(json_encode($resp));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($resp->ok ? 200 : 409);
});