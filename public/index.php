<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . $config['cors_origin']);
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Game-Key, X-Admin-Key, X-Request-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $pdo = require __DIR__ . '/../config/database.php';
} catch (Throwable $e) {
    respondError(500, 'DATABASE_CONNECTION_FAILED', 'No se pudo conectar con la base de datos.');
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Permite montar la API en una subcarpeta.
// Se toma desde /api/v1 si aparece en cualquier parte de la ruta.
$apiPos = strpos($path, '/api/v1');
if ($apiPos !== false) {
    $path = substr($path, $apiPos);
}

$path = '/' . trim($path, '/');
if ($path === '/') {
    $path = '/api/v1/health';
}

/* ============================================================
   FUNCIONES AUXILIARES
============================================================ */

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function respondData(mixed $data, int $status = 200): never
{
    respond([
        'success' => true,
        'data' => $data,
    ], $status);
}

function respondError(int $status, string $code, string $message, array $details = []): never
{
    $error = [
        'code' => $code,
        'message' => $message,
    ];

    if ($details) {
        $error['details'] = $details;
    }

    respond([
        'success' => false,
        'error' => $error,
    ], $status);
}

function bodyJson(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === '' || $raw === false) {
        return [];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        respondError(400, 'INVALID_JSON', 'El cuerpo de la solicitud debe ser JSON válido.');
    }

    return $data;
}

function headerValue(string $name): ?string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $value = $_SERVER[$key] ?? null;

    return is_string($value) && $value !== '' ? trim($value) : null;
}

function requireAdmin(array $config): void
{
    $provided = headerValue('X-Admin-Key');
    $expected = (string)($config['admin_key'] ?? '');

    if (
        $provided === null ||
        $expected === '' ||
        !hash_equals($expected, $provided)
    ) {
        respondError(401, 'ADMIN_UNAUTHORIZED', 'Credencial de administración inválida.');
    }
}

function requireGame(PDO $pdo, string $slug): array
{
    $key = headerValue('X-Game-Key');

    if ($key === null) {
        respondError(401, 'GAME_KEY_REQUIRED', 'Falta el header X-Game-Key.');
    }

    $stmt = $pdo->prepare(
        'SELECT id, slug, name, api_key_hash, status
         FROM games
         WHERE slug = ?
         LIMIT 1'
    );
    $stmt->execute([$slug]);
    $game = $stmt->fetch();

    if (!$game) {
        respondError(404, 'GAME_NOT_FOUND', 'El juego indicado no existe.');
    }

    if ($game['status'] !== 'active') {
        respondError(403, 'GAME_INACTIVE', 'El juego está inactivo.');
    }

    if (!password_verify($key, $game['api_key_hash'])) {
        respondError(401, 'INVALID_GAME_KEY', 'API key del juego inválida.');
    }

    return $game;
}

function normalizedLimit(mixed $value, int $default = 20): int
{
    $limit = filter_var($value, FILTER_VALIDATE_INT);

    if (!$limit) {
        return $default;
    }

    return max(1, min(100, $limit));
}

function generateScoreId(): string
{
    return 'score_' . bin2hex(random_bytes(12));
}

function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function findGame(PDO $pdo, string $slug): array
{
    $stmt = $pdo->prepare(
        'SELECT id, slug, name, description, status, created_at, updated_at
         FROM games
         WHERE slug = ?
         LIMIT 1'
    );
    $stmt->execute([$slug]);
    $game = $stmt->fetch();

    if (!$game) {
        respondError(404, 'GAME_NOT_FOUND', 'Juego no encontrado.');
    }

    return $game;
}

function findSeason(PDO $pdo, string $slug): array
{
    $stmt = $pdo->prepare(
        'SELECT id, slug, name, starts_at, ends_at, status, created_at
         FROM seasons
         WHERE slug = ?
         LIMIT 1'
    );
    $stmt->execute([$slug]);
    $season = $stmt->fetch();

    if (!$season) {
        respondError(404, 'SEASON_NOT_FOUND', 'Temporada no encontrada.');
    }

    return $season;
}

/* ============================================================
   ESTADO DE LA API
============================================================ */

if ($method === 'GET' && in_array($path, ['/api/v1/health', '/health'], true)) {
    try {
        $pdo->query('SELECT 1');
        $database = 'ok';
    } catch (Throwable $e) {
        $database = 'error';
    }

    respondData([
        'status' => $database === 'ok' ? 'ok' : 'degraded',
        'version' => '2.0.0',
        'engine' => 'PHP 8.2+ / PDO / MariaDB',
        'database' => $database,
        'timestamp' => date(DATE_ATOM),
    ]);
}

/* ============================================================
   JUEGOS
============================================================ */

if ($method === 'GET' && $path === '/api/v1/games') {
    $status = $_GET['status'] ?? null;

    $sql = '
        SELECT
            g.id,
            g.slug,
            g.name,
            g.description,
            g.status,
            g.created_at,
            g.updated_at,
            COUNT(s.id) AS score_count
        FROM games g
        LEFT JOIN scores s ON s.game_id = g.id
    ';

    $params = [];

    if (in_array($status, ['active', 'inactive'], true)) {
        $sql .= ' WHERE g.status = ? ';
        $params[] = $status;
    }

    $sql .= '
        GROUP BY
            g.id, g.slug, g.name, g.description,
            g.status, g.created_at, g.updated_at
        ORDER BY g.name ASC
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    respondData($stmt->fetchAll());
}

if ($method === 'POST' && $path === '/api/v1/games') {
    requireAdmin($config);

    $input = bodyJson();
    $name = trim((string)($input['name'] ?? ''));
    $slug = slugify((string)($input['slug'] ?? $name));
    $description = trim((string)($input['description'] ?? ''));
    $status = (string)($input['status'] ?? 'active');

    if ($name === '' || $slug === '') {
        respondError(400, 'INVALID_GAME', 'name y slug son obligatorios.');
    }

    if (!in_array($status, ['active', 'inactive'], true)) {
        respondError(400, 'INVALID_STATUS', 'Estado de juego inválido.');
    }

    $plainKey = bin2hex(random_bytes(32));
    $hash = password_hash($plainKey, PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO games (slug, name, description, api_key_hash, status)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$slug, $name, $description ?: null, $hash, $status]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            respondError(409, 'GAME_ALREADY_EXISTS', 'Ya existe un juego con ese slug.');
        }
        throw $e;
    }

    respondData([
        'id' => (int)$pdo->lastInsertId(),
        'slug' => $slug,
        'name' => $name,
        'description' => $description,
        'status' => $status,
        'api_key' => $plainKey,
        'api_key_notice' => 'Guarda esta API key ahora. No se vuelve a mostrar.',
    ], 201);
}

if (preg_match('#^/api/v1/games/(\d+)$#', $path, $m)) {
    $gameId = (int)$m[1];

    if ($method === 'PATCH') {
        requireAdmin($config);
        $input = bodyJson();

        $allowed = [];
        $params = [];

        foreach (['name', 'description', 'status'] as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }

            if ($field === 'status' && !in_array($input[$field], ['active', 'inactive'], true)) {
                respondError(400, 'INVALID_STATUS', 'Estado de juego inválido.');
            }

            $allowed[] = "$field = ?";
            $params[] = $input[$field];
        }

        if (!$allowed) {
            respondError(400, 'NO_CHANGES', 'No se enviaron cambios válidos.');
        }

        $params[] = $gameId;

        $stmt = $pdo->prepare(
            'UPDATE games SET ' . implode(', ', $allowed) . ' WHERE id = ?'
        );
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            $check = $pdo->prepare('SELECT id FROM games WHERE id = ?');
            $check->execute([$gameId]);
            if (!$check->fetch()) {
                respondError(404, 'GAME_NOT_FOUND', 'Juego no encontrado.');
            }
        }

        respondData(['id' => $gameId, 'updated' => true]);
    }

    if ($method === 'DELETE') {
        requireAdmin($config);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM scores WHERE game_id = ?');
        $stmt->execute([$gameId]);
        $scoreCount = (int)$stmt->fetchColumn();

        if ($scoreCount > 0) {
            respondError(
                409,
                'GAME_HAS_SCORES',
                'No se puede eliminar un juego con puntajes. Desactívalo en su lugar.',
                ['score_count' => $scoreCount]
            );
        }

        $stmt = $pdo->prepare('DELETE FROM games WHERE id = ?');
        $stmt->execute([$gameId]);

        if ($stmt->rowCount() === 0) {
            respondError(404, 'GAME_NOT_FOUND', 'Juego no encontrado.');
        }

        respondData(['id' => $gameId, 'deleted' => true]);
    }
}

/* ============================================================
   TEMPORADAS
============================================================ */

if ($method === 'GET' && $path === '/api/v1/seasons') {
    $status = $_GET['status'] ?? null;

    $sql = '
        SELECT
            se.id,
            se.slug,
            se.name,
            se.starts_at,
            se.ends_at,
            se.status,
            se.created_at,
            COUNT(sc.id) AS score_count
        FROM seasons se
        LEFT JOIN scores sc ON sc.season_id = se.id
    ';

    $params = [];

    if (in_array($status, ['active', 'completed'], true)) {
        $sql .= ' WHERE se.status = ? ';
        $params[] = $status;
    }

    $sql .= '
        GROUP BY
            se.id, se.slug, se.name, se.starts_at,
            se.ends_at, se.status, se.created_at
        ORDER BY se.starts_at DESC
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    respondData($stmt->fetchAll());
}

if ($method === 'POST' && $path === '/api/v1/seasons') {
    requireAdmin($config);

    $input = bodyJson();

    $name = trim((string)($input['name'] ?? ''));
    $slug = slugify((string)($input['slug'] ?? $name));
    $startsAt = trim((string)($input['starts_at'] ?? ''));
    $endsAt = trim((string)($input['ends_at'] ?? ''));
    $status = (string)($input['status'] ?? 'active');

    if ($name === '' || $slug === '' || $startsAt === '' || $endsAt === '') {
        respondError(
            400,
            'INVALID_SEASON',
            'name, slug, starts_at y ends_at son obligatorios.'
        );
    }

    if (!in_array($status, ['active', 'completed'], true)) {
        respondError(400, 'INVALID_STATUS', 'Estado de temporada inválido.');
    }

    if (strtotime($startsAt) === false || strtotime($endsAt) === false) {
        respondError(400, 'INVALID_DATE', 'Las fechas no son válidas.');
    }

    if (strtotime($endsAt) <= strtotime($startsAt)) {
        respondError(400, 'INVALID_DATE_RANGE', 'ends_at debe ser posterior a starts_at.');
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO seasons (slug, name, starts_at, ends_at, status)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$slug, $name, $startsAt, $endsAt, $status]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            respondError(409, 'SEASON_ALREADY_EXISTS', 'Ya existe una temporada con ese slug.');
        }
        throw $e;
    }

    respondData([
        'id' => (int)$pdo->lastInsertId(),
        'slug' => $slug,
        'name' => $name,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'status' => $status,
    ], 201);
}

if (preg_match('#^/api/v1/seasons/(\d+)$#', $path, $m)) {
    $seasonId = (int)$m[1];

    if ($method === 'PATCH') {
        requireAdmin($config);

        $input = bodyJson();
        $allowed = [];
        $params = [];

        foreach (['name', 'starts_at', 'ends_at', 'status'] as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }

            if ($field === 'status' && !in_array($input[$field], ['active', 'completed'], true)) {
                respondError(400, 'INVALID_STATUS', 'Estado de temporada inválido.');
            }

            $allowed[] = "$field = ?";
            $params[] = $input[$field];
        }

        if (!$allowed) {
            respondError(400, 'NO_CHANGES', 'No se enviaron cambios válidos.');
        }

        $params[] = $seasonId;

        $stmt = $pdo->prepare(
            'UPDATE seasons SET ' . implode(', ', $allowed) . ' WHERE id = ?'
        );
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            $check = $pdo->prepare('SELECT id FROM seasons WHERE id = ?');
            $check->execute([$seasonId]);
            if (!$check->fetch()) {
                respondError(404, 'SEASON_NOT_FOUND', 'Temporada no encontrada.');
            }
        }

        respondData(['id' => $seasonId, 'updated' => true]);
    }

    if ($method === 'DELETE') {
        requireAdmin($config);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM scores WHERE season_id = ?');
        $stmt->execute([$seasonId]);
        $scoreCount = (int)$stmt->fetchColumn();

        if ($scoreCount > 0) {
            respondError(
                409,
                'SEASON_HAS_SCORES',
                'No se puede eliminar una temporada con puntajes. Márcala como completada.',
                ['score_count' => $scoreCount]
            );
        }

        $stmt = $pdo->prepare('DELETE FROM seasons WHERE id = ?');
        $stmt->execute([$seasonId]);

        if ($stmt->rowCount() === 0) {
            respondError(404, 'SEASON_NOT_FOUND', 'Temporada no encontrada.');
        }

        respondData(['id' => $seasonId, 'deleted' => true]);
    }
}

/* ============================================================
   PUNTAJES
============================================================ */

if ($method === 'POST' && $path === '/api/v1/scores') {
    $input = bodyJson();

    $gameSlug = trim((string)($input['game_slug'] ?? $input['game'] ?? ''));
    $seasonSlug = trim((string)($input['season_slug'] ?? $input['season'] ?? ''));
    $externalId = trim((string)($input['player_external_id'] ?? $input['external_id'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $nickname = trim((string)($input['nickname'] ?? 'Player'));
    $score = $input['score'] ?? null;
    $metadata = $input['metadata'] ?? new stdClass();

    if ($gameSlug === '' || $seasonSlug === '' || $score === null) {
        respondError(
            400,
            'INVALID_PAYLOAD',
            'game_slug, season_slug y score son obligatorios.'
        );
    }

    if (!is_numeric($score) || (int)$score < 0) {
        respondError(400, 'INVALID_SCORE', 'score debe ser un entero mayor o igual a 0.');
    }

    $game = requireGame($pdo, $gameSlug);
    $season = findSeason($pdo, $seasonSlug);

    if ($season['status'] !== 'active') {
        respondError(403, 'SEASON_INACTIVE', 'La temporada no está activa.');
    }

    if ($externalId === '' && $email === '') {
        respondError(
            400,
            'PLAYER_IDENTITY_REQUIRED',
            'Se requiere email o player_external_id.'
        );
    }

    $pdo->beginTransaction();

    try {
        $player = null;

        if ($externalId !== '') {
            $stmt = $pdo->prepare(
                'SELECT id, external_id, email, nickname
                 FROM players
                 WHERE external_id = ?
                 LIMIT 1'
            );
            $stmt->execute([$externalId]);
            $player = $stmt->fetch();
        }

        if (!$player && $email !== '') {
            $stmt = $pdo->prepare(
                'SELECT id, external_id, email, nickname
                 FROM players
                 WHERE email = ?
                 LIMIT 1'
            );
            $stmt->execute([$email]);
            $player = $stmt->fetch();
        }

        if (!$player) {
            // La tabla actual exige correo electrónico. Para jugadores con external_id sin
            // correo se crea uno técnico y único, fácil de migrar después si
            // se decide permitir valores nulos en email.
            if ($email === '') {
                $safeExternal = preg_replace('/[^a-zA-Z0-9._-]/', '', $externalId) ?: bin2hex(random_bytes(6));
                $email = 'visitor+' . $safeExternal . '@spicycrust.invalid';
            }

            $stmt = $pdo->prepare(
                'INSERT INTO players (external_id, email, nickname)
                 VALUES (?, ?, ?)'
            );
            $stmt->execute([
                $externalId !== '' ? $externalId : null,
                $email,
                $nickname !== '' ? $nickname : 'Player',
            ]);

            $playerId = (int)$pdo->lastInsertId();
        } else {
            $playerId = (int)$player['id'];

            if ($nickname !== '' && $nickname !== $player['nickname']) {
                $stmt = $pdo->prepare(
                    'UPDATE players SET nickname = ? WHERE id = ?'
                );
                $stmt->execute([$nickname, $playerId]);
            }
        }

        $scoreId = generateScoreId();

        $stmt = $pdo->prepare(
            'INSERT INTO scores
                (id, game_id, player_id, season_id, score, metadata)
             VALUES
                (?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $scoreId,
            (int)$game['id'],
            $playerId,
            (int)$season['id'],
            (int)$score,
            json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $pdo->commit();

        respondData([
            'id' => $scoreId,
            'game_slug' => $gameSlug,
            'season_slug' => $seasonSlug,
            'player_id' => $playerId,
            'player_external_id' => $externalId !== '' ? $externalId : null,
            'nickname' => $nickname !== '' ? $nickname : 'Player',
            'score' => (int)$score,
            'metadata' => $metadata,
            'created_at' => date(DATE_ATOM),
        ], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

if ($method === 'DELETE' && preg_match('#^/api/v1/scores/([^/]+)$#', $path, $m)) {
    requireAdmin($config);

    $scoreId = urldecode($m[1]);

    $stmt = $pdo->prepare('DELETE FROM scores WHERE id = ?');
    $stmt->execute([$scoreId]);

    if ($stmt->rowCount() === 0) {
        respondError(404, 'SCORE_NOT_FOUND', 'Puntaje no encontrado.');
    }

    respondData([
        'id' => $scoreId,
        'deleted' => true,
    ]);
}

/* ============================================================
   TABLA DE POSICIONES
============================================================ */

if ($method === 'GET' && $path === '/api/v1/leaderboard') {
    $gameSlug = trim((string)($_GET['game'] ?? 'rhythm-slice'));
    $seasonSlug = trim((string)($_GET['season'] ?? 'season-01'));
    $search = trim((string)($_GET['search'] ?? ''));
    $limit = normalizedLimit($_GET['limit'] ?? 20);

    $game = findGame($pdo, $gameSlug);
    $season = findSeason($pdo, $seasonSlug);

    // Una fila por jugador: su mejor puntaje para juego + temporada.
    // La posición se calcula antes del filtro de búsqueda para conservar
    // la posición global del jugador.
    $sql = '
        SELECT *
        FROM (
            SELECT
                ranked.player_id,
                ranked.nickname,
                ranked.email,
                ranked.score,
                ranked.score_id,
                ranked.created_at,
                ROW_NUMBER() OVER (
                    ORDER BY ranked.score DESC, ranked.created_at ASC
                ) AS `rank`
            FROM (
                SELECT
                    p.id AS player_id,
                    p.nickname,
                    p.email,
                    s.score,
                    s.id AS score_id,
                    s.created_at,
                    ROW_NUMBER() OVER (
                        PARTITION BY p.id
                        ORDER BY s.score DESC, s.created_at ASC
                    ) AS player_best
                FROM scores s
                INNER JOIN players p ON p.id = s.player_id
                WHERE s.game_id = ?
                  AND s.season_id = ?
            ) ranked
            WHERE ranked.player_best = 1
        ) leaderboard
    ';

    $params = [(int)$game['id'], (int)$season['id']];

    if ($search !== '') {
        $sql .= ' WHERE nickname LIKE ? OR email LIKE ? ';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= ' ORDER BY `rank` ASC LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $ranking = array_map(static function (array $row): array {
        return [
            'rank' => (int)$row['rank'],
            'player_id' => (int)$row['player_id'],
            'nickname' => $row['nickname'],
            'email' => $row['email'],
            'score' => (int)$row['score'],
            'score_id' => $row['score_id'],
            'created_at' => $row['created_at'],
        ];
    }, $rows);

    respondData([
        'game' => [
            'id' => (int)$game['id'],
            'slug' => $game['slug'],
            'name' => $game['name'],
        ],
        'season' => [
            'id' => (int)$season['id'],
            'slug' => $season['slug'],
            'name' => $season['name'],
        ],
        'limit' => $limit,
        'search' => $search,
        'ranking' => $ranking,
    ]);
}

/* ============================================================
   JUGADORES
============================================================ */

if ($method === 'GET' && $path === '/api/v1/players') {
    $search = trim((string)($_GET['search'] ?? ''));

    $sql = '
        SELECT id, external_id, email, nickname, created_at, updated_at
        FROM players
    ';

    $params = [];

    if ($search !== '') {
        $sql .= ' WHERE nickname LIKE ? OR email LIKE ? OR external_id LIKE ? ';
        $like = '%' . $search . '%';
        $params = [$like, $like, $like];
    }

    $sql .= ' ORDER BY created_at DESC ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $players = $stmt->fetchAll();

    respondData([
        'total' => count($players),
        'players' => $players,
    ]);
}

if ($method === 'GET' && preg_match('#^/api/v1/players/(\d+)$#', $path, $m)) {
    $playerId = (int)$m[1];

    $stmt = $pdo->prepare(
        'SELECT id, external_id, email, nickname, created_at, updated_at
         FROM players
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$playerId]);
    $player = $stmt->fetch();

    if (!$player) {
        respondError(404, 'PLAYER_NOT_FOUND', 'Jugador no encontrado.');
    }

    $gameSlug = trim((string)($_GET['game'] ?? ''));
    $seasonSlug = trim((string)($_GET['season'] ?? ''));

    $sql = '
        SELECT
            sc.id,
            sc.score,
            sc.metadata,
            sc.created_at,
            g.id AS game_id,
            g.slug AS game_slug,
            g.name AS game_name,
            se.id AS season_id,
            se.slug AS season_slug,
            se.name AS season_name
        FROM scores sc
        INNER JOIN games g ON g.id = sc.game_id
        INNER JOIN seasons se ON se.id = sc.season_id
        WHERE sc.player_id = ?
    ';

    $params = [$playerId];

    if ($gameSlug !== '') {
        $sql .= ' AND g.slug = ? ';
        $params[] = $gameSlug;
    }

    if ($seasonSlug !== '') {
        $sql .= ' AND se.slug = ? ';
        $params[] = $seasonSlug;
    }

    $sql .= ' ORDER BY sc.created_at DESC ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $scores = $stmt->fetchAll();

    foreach ($scores as &$scoreRow) {
        $scoreRow['score'] = (int)$scoreRow['score'];
        $scoreRow['metadata'] = $scoreRow['metadata']
            ? json_decode($scoreRow['metadata'], true)
            : null;
    }
    unset($scoreRow);

    respondData([
        'player' => $player,
        'scores' => $scores,
    ]);
}

/* ============================================================
   ESTADÍSTICAS
============================================================ */

if ($method === 'GET' && $path === '/api/v1/stats') {
    $gameSlug = trim((string)($_GET['game'] ?? ''));
    $seasonSlug = trim((string)($_GET['season'] ?? ''));

    $where = [];
    $params = [];

    if ($gameSlug !== '') {
        $where[] = 'g.slug = ?';
        $params[] = $gameSlug;
    }

    if ($seasonSlug !== '') {
        $where[] = 'se.slug = ?';
        $params[] = $seasonSlug;
    }

    $whereSql = $where
        ? ' WHERE ' . implode(' AND ', $where)
        : '';

    $sql = '
        SELECT
            COUNT(DISTINCT sc.player_id) AS total_players,
            COUNT(sc.id) AS total_scores,
            COALESCE(MAX(sc.score), 0) AS highest_score,
            COALESCE(SUM(
                CASE
                    WHEN DATE(sc.created_at) = CURDATE() THEN 1
                    ELSE 0
                END
            ), 0) AS scores_today
        FROM scores sc
        INNER JOIN games g ON g.id = sc.game_id
        INNER JOIN seasons se ON se.id = sc.season_id
    ' . $whereSql;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $stats = $stmt->fetch();

    respondData([
        'total_players' => (int)($stats['total_players'] ?? 0),
        'total_scores' => (int)($stats['total_scores'] ?? 0),
        'highest_score' => (int)($stats['highest_score'] ?? 0),
        'scores_today' => (int)($stats['scores_today'] ?? 0),
    ]);
}

/* ============================================================
   RUTA NO ENCONTRADA Y MANEJO DE ERRORES
============================================================ */

respondError(404, 'NOT_FOUND', 'Endpoint no encontrado.');
