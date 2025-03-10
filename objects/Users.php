<?php

namespace objects;

use Firebase\JWT\JWT;
use objects\Base;
use utils\Prepare;

class Users extends Base
{
	private $table_name = "users";
	private $temp_table = "users_temp";
	private $recovery_table = "password_recovery";

	public function __construct($db)
	{
		parent::__construct($db);
	}

	// Obtener todos los usuarios
	public function getUsers()
	{
		$query = "SELECT id, email, firstname, lastname, role, active, created_at 
                 FROM $this->table_name 
                 ORDER BY lastname, firstname";
		parent::getAll($query);
		return $this;
	}

	// Obtener usuarios por rol
	public function getUsersByRole($role)
	{
		$query = "SELECT id, email, firstname, lastname, role, active, created_at 
                 FROM $this->table_name 
                 WHERE role = :role AND active = true 
                 ORDER BY lastname, firstname";
		parent::getAllWithParams($query, ["role" => $role]);
		return $this;
	}

	// Obtener un usuario específico
	public function getUser($id)
	{
		$query = "SELECT id, email, firstname, lastname, role, active, created_at 
                 FROM $this->table_name 
                 WHERE id = :id";
		parent::getOne($query, ["id" => $id]);
		return $this;
	}

	// Crear nuevo usuario
	public function setUser($fields)
	{
		try {
			$this->beginTransaction();

			$query = "INSERT INTO $this->table_name 
                     SET email = :email, 
                         firstname = :firstname, 
                         lastname = :lastname, 
                         password = :password, 
                         role = :role";

			$values = [
				"email" => $fields["email"],
				"firstname" => Prepare::UCfirst($fields["firstname"]),
				"lastname" => Prepare::UCfirst($fields["lastname"]),
				"password" => password_hash($fields["password"], PASSWORD_BCRYPT),
				"role" => $fields["role"] ?? 'transportista'
			];

			parent::add($query, $values);

			if (!parent::getResult()->ok) {
				throw new \Exception(parent::getResult()->msg);
			}

			$this->commit();
			return $this;
		} catch (\Exception $e) {
			$this->rollBack();
			$result = parent::getResult();
			$result->ok = false;
			$result->msg = $e->getMessage();
			return $this;
		}
	}

	// Actualizar usuario
	public function updateUser($fields)
	{
		try {
			$this->beginTransaction();

			$setFields = [];
			$values = ["id" => $fields["id"]];

			if (isset($fields["email"])) {
				$setFields[] = "email = :email";
				$values["email"] = $fields["email"];
			}
			if (isset($fields["firstname"])) {
				$setFields[] = "firstname = :firstname";
				$values["firstname"] = Prepare::UCfirst($fields["firstname"]);
			}
			if (isset($fields["lastname"])) {
				$setFields[] = "lastname = :lastname";
				$values["lastname"] = Prepare::UCfirst($fields["lastname"]);
			}
			if (isset($fields["role"])) {
				$setFields[] = "role = :role";
				$values["role"] = $fields["role"];
			}
			if (isset($fields["active"])) {
				$setFields[] = "active = :active";
				$values["active"] = $fields["active"];
			}

			if (empty($setFields)) {
				throw new \Exception("No hay campos para actualizar");
			}

			$query = "UPDATE $this->table_name 
                     SET " . implode(", ", $setFields) . " 
                     WHERE id = :id";

			parent::update($query, $values);

			if (!parent::getResult()->ok) {
				throw new \Exception(parent::getResult()->msg);
			}

			$this->commit();
			return $this;
		} catch (\Exception $e) {
			$this->rollBack();
			$result = parent::getResult();
			$result->ok = false;
			$result->msg = $e->getMessage();
			return $this;
		}
	}

	// Autenticar usuario
	public function authenticate($email, $password)
	{
		$query = "SELECT * FROM $this->table_name 
                 WHERE email = :email AND active = true";

		parent::getOne($query, ["email" => $email]);
		$result = parent::getResult();

		if (!$result->ok || !$result->data) {
			$result->ok = false;
			$result->msg = "Usuario no encontrado o inactivo";
			return $this;
		}

		$user = $result->data;

		if (!password_verify($password, $user->password)) {
			$result->ok = false;
			$result->msg = "Contraseña incorrecta";
			return $this;
		}

		// Calcular la expiración del token
		$expiration = 0;
		if ($_ENV["JWT_EXPIRATION"] === "1h") {
			$expiration = time() + (60 * 60); // 1 hora
		} else if ($_ENV["JWT_EXPIRATION"] === "24h") {
			$expiration = time() + (24 * 60 * 60); // 24 horas
		} else if ($_ENV["JWT_EXPIRATION"] === "7d") {
			$expiration = time() + (7 * 24 * 60 * 60); // 7 días
		} else {
			// Por defecto 24 horas si no se reconoce el formato
			$expiration = time() + (24 * 60 * 60);
		}

		// Generar JWT
		$payload = [
			"iss" => $_ENV["APP_URL"],
			"aud" => $_ENV["APP_URL"],
			"iat" => time(),
			"exp" => $expiration,
			"data" => [
				"id" => $user->id,
				"email" => $user->email,
				"firstname" => $user->firstname,
				"lastname" => $user->lastname,
				"role" => $user->role
			]
		];

		$jwt = JWT::encode(
			$payload,
			$_ENV["JWT_SECRET_KEY"],
			$_ENV["JWT_ALGORITHM"]
		);

		// Preparar respuesta
		unset($user->password);
		$user->token = "Bearer " . $jwt;

		$result->ok = true;
		$result->msg = "Autenticación exitosa";
		$result->data = $user;

		return $this;
	}

	// Cambiar contraseña
	public function changePassword($userId, $currentPassword, $newPassword)
	{
		try {
			$this->beginTransaction();

			// Verificar contraseña actual
			$query = "SELECT password FROM $this->table_name WHERE id = :id";
			parent::getOne($query, ["id" => $userId]);
			$result = parent::getResult();

			if (!$result->ok || !$result->data) {
				throw new \Exception("Usuario no encontrado");
			}

			if (!password_verify($currentPassword, $result->data->password)) {
				throw new \Exception("Contraseña actual incorrecta");
			}

			// Actualizar contraseña
			$query = "UPDATE $this->table_name 
                     SET password = :password 
                     WHERE id = :id";

			parent::update($query, [
				"id" => $userId,
				"password" => password_hash($newPassword, PASSWORD_BCRYPT)
			]);

			if (!parent::getResult()->ok) {
				throw new \Exception(parent::getResult()->msg);
			}

			$this->commit();
			return $this;
		} catch (\Exception $e) {
			$this->rollBack();
			$result = parent::getResult();
			$result->ok = false;
			$result->msg = $e->getMessage();
			return $this;
		}
	}

	// Recuperación de contraseña
	public function initiatePasswordRecovery($email)
	{
		try {
			$this->beginTransaction();

			// Verificar que el usuario existe
			$query = "SELECT id FROM $this->table_name WHERE email = :email AND active = true";
			parent::getOne($query, ["email" => $email]);
			$result = parent::getResult();

			if (!$result->ok || !$result->data) {
				throw new \Exception("Usuario no encontrado");
			}

			$userId = $result->data->id;
			$token = Prepare::randomString(32);

			// Crear token de recuperación
			$query = "INSERT INTO $this->recovery_table 
                     SET user_id = :user_id, 
                         token = :token";

			parent::add($query, [
				"user_id" => $userId,
				"token" => $token
			]);

			if (!parent::getResult()->ok) {
				throw new \Exception(parent::getResult()->msg);
			}

			$this->commit();

			$result->data = [
				"token" => $token,
				"email" => $email
			];

			return $this;
		} catch (\Exception $e) {
			$this->rollBack();
			$result = parent::getResult();
			$result->ok = false;
			$result->msg = $e->getMessage();
			return $this;
		}
	}

	// Validar token de recuperación
	public function validateRecoveryToken($token)
	{
		$query = "SELECT pr.*, u.email 
                 FROM $this->recovery_table pr 
                 INNER JOIN $this->table_name u ON u.id = pr.user_id 
                 WHERE pr.token = :token 
                 AND pr.used = false 
                 AND pr.expires_at > NOW()";

		parent::getOne($query, ["token" => $token]);
		return $this;
	}

	// Restablecer contraseña
	public function resetPassword($token, $newPassword)
	{
		try {
			$this->beginTransaction();

			// Validar token
			$validation = $this->validateRecoveryToken($token);
			if (!$validation->getResult()->ok || !$validation->getResult()->data) {
				throw new \Exception("Token inválido o expirado");
			}

			$recoveryData = $validation->getResult()->data;

			// Actualizar contraseña
			$query = "UPDATE $this->table_name 
                     SET password = :password 
                     WHERE id = :id";

			parent::update($query, [
				"id" => $recoveryData->user_id,
				"password" => password_hash($newPassword, PASSWORD_BCRYPT)
			]);

			if (!parent::getResult()->ok) {
				throw new \Exception(parent::getResult()->msg);
			}

			// Marcar token como usado
			$query = "UPDATE $this->recovery_table 
                     SET used = true 
                     WHERE token = :token";

			parent::update($query, ["token" => $token]);

			$this->commit();
			return $this;
		} catch (\Exception $e) {
			$this->rollBack();
			$result = parent::getResult();
			$result->ok = false;
			$result->msg = $e->getMessage();
			return $this;
		}
	}

	// Eliminar usuario (soft delete)
	public function deleteUser($id)
	{
		$query = "UPDATE $this->table_name 
                 SET active = false 
                 WHERE id = :id";
		parent::update($query, ["id" => $id]);
		return $this;
	}
}
