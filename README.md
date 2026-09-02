# SpicyCrust API V2

API rápida en **PHP 8.2 + PDO + MariaDB/MySQL**, diseñada para reemplazar el almacenamiento JSON de la versión anterior y cubrir las funciones actuales del panel admin/evento.

## Estructura

```text
spicycrust-api-v2/
├── config/
│   ├── config.php
│   └── database.php
└── public/
    ├── .htaccess
    ├── index.php
    └── router.php
```

## Base de datos

Está preparada para la base existente:

```text
spicycrust_game_api
```

con las tablas:

```text
games
players
seasons
scores
```

El usuario local por defecto es:

```text
host: 127.0.0.1
database: spicycrust_game_api
user: root
password: vacío
```

Se puede cambiar con variables de entorno:

```text
DB_HOST
DB_PORT
DB_NAME
DB_USER
DB_PASS
SPICYCRUST_ADMIN_KEY
CORS_ORIGIN
```

> IMPORTANTE: cambia `SPICYCRUST_ADMIN_KEY` antes de publicar.

## Endpoints

### Públicos / lectura

```text
GET /api/v1/health
GET /api/v1/games
GET /api/v1/games?status=active
GET /api/v1/seasons
GET /api/v1/seasons?status=active

GET /api/v1/leaderboard
GET /api/v1/leaderboard?game=rhythm-slice&season=season-01&limit=20
GET /api/v1/leaderboard?game=rhythm-slice&season=season-01&search=pizza&limit=20

GET /api/v1/players
GET /api/v1/players?search=correo
GET /api/v1/players/15
GET /api/v1/players/15?game=rhythm-slice&season=season-01

GET /api/v1/stats
```

### Juegos enviando puntajes

```text
POST /api/v1/scores
Header: X-Game-Key: API_KEY_REAL_DEL_JUEGO
```

Ejemplo:

```json
{
  "game_slug": "rhythm-slice",
  "season_slug": "season-01",
  "player_external_id": "player-123",
  "email": "player@example.com",
  "nickname": "PizzaMaster",
  "score": 12500,
  "metadata": {
    "level": 2
  }
}
```

La API busca el juego y valida `X-Game-Key` usando `password_verify()` contra `games.api_key_hash`.

### Administración

Todos estos endpoints requieren:

```text
X-Admin-Key: TU_ADMIN_KEY
```

```text
POST   /api/v1/games
PATCH  /api/v1/games/{id}
DELETE /api/v1/games/{id}

POST   /api/v1/seasons
PATCH  /api/v1/seasons/{id}
DELETE /api/v1/seasons/{id}

DELETE /api/v1/scores/{id}
```

Al crear un juego se genera una API key nueva. La respuesta devuelve la key en texto plano **una sola vez** y en la base se guarda solamente el hash.

## Crear juego

```http
POST /api/v1/games
Content-Type: application/json
X-Admin-Key: change-me-before-production
```

```json
{
  "name": "Rhythm Slice",
  "slug": "rhythm-slice",
  "description": "Juego de ritmo",
  "status": "active"
}
```

## Crear temporada

```http
POST /api/v1/seasons
Content-Type: application/json
X-Admin-Key: change-me-before-production
```

```json
{
  "name": "Season 02",
  "slug": "season-02",
  "starts_at": "2026-11-01 00:00:00",
  "ends_at": "2027-01-31 23:59:59",
  "status": "active"
}
```

## Prueba rápida con XAMPP

Una opción simple es copiar:

```text
spicycrust-api-v2
```

a:

```text
C:\xampp\htdocs\
```

y hacer que Apache sirva la carpeta `public`.

Para una prueba todavía más rápida desde consola:

```bash
cd C:\xampp\htdocs\spicycrust-api-v2
php -S localhost:8080 -t public public/router.php
```

Después abre:

```text
http://localhost:8080/api/v1/health
```

Deberías recibir algo parecido a:

```json
{
  "success": true,
  "data": {
    "status": "ok",
    "version": "2.0.0",
    "engine": "PHP 8.2+ / PDO / MariaDB",
    "database": "ok"
  }
}
```

## Diferencias importantes frente a la API vieja

La versión anterior guardaba datos en:

```text
storage/db.json
```

Esta versión trabaja directamente con MariaDB/MySQL mediante PDO.

Además agrega las operaciones que necesita el sistema actual:

```text
players
player details
stats
game management
season management
score deletion
search
Top 1-100 leaderboard
```

## Antes de producción

Esto es una base funcional/prototipo rápido. Antes de publicarla conviene:

1. Configurar variables de entorno y eliminar las claves de desarrollo.
2. Restringir CORS al dominio real.
3. Usar HTTPS.
4. Añadir rate limiting a `POST /scores`.
5. Decidir el sistema definitivo de autenticación del administrador.
6. Registrar errores en logs sin devolver detalles internos al cliente.
7. Revisar cómo se valida un puntaje para evitar scores falsificados.
8. Cambiar las API keys placeholder existentes por keys reales generadas por esta API.
