<?php
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

// Load .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$app = AppFactory::create();

// Build PostgreSQL connection string from env vars
$pgConnStr = sprintf(
    "host=%s port=%s dbname=%s user=%s password=%s sslmode=%s options='endpoint=%s'",
    $_ENV['DB_HOST'],
    $_ENV['DB_PORT'],
    $_ENV['DB_NAME'],
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    $_ENV['DB_SSLMODE'],
    $_ENV['DB_ENDPOINT']
);
$db = pg_connect($pgConnStr);
if (!$db) {
    die("❌ Database connection failed.");
}

// Convert JSONB fields
function parseJsonFields(&$row) {
    $fields = ['tags', 'dimensions', 'meta', 'images'];
    foreach ($fields as $field) {
        $row[$field] = json_decode($row[$field] ?? 'null', true);
    }
}

$app->add(function (Request $request, RequestHandlerInterface $handler): Response {
    $response = $handler->handle($request);

    // Handle CORS
    $origin = $request->getHeaderLine('Origin') ?: '*';

    return $response
        ->withHeader('Access-Control-Allow-Origin', $origin)
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
        ->withHeader('Access-Control-Allow-Credentials', 'true'); // if needed
});

// Handle preflight requests
$app->options('/{routes:.+}', function (Request $request, Response $response) {
    return $response;
});


$app->get('/favicon.ico', function (Request $request, Response $response) {
    $faviconPath = __DIR__ . '/../public/favicon.ico';
    if (file_exists($faviconPath)) {
        $response->getBody()->write(file_get_contents($faviconPath));
        return $response->withHeader('Content-Type', 'image/x-icon');
    }
    return $response->withStatus(404);
});

// GET /products
$app->get('/products', function (Request $request, Response $response) use ($db) {
    $params = $request->getQueryParams();

    $whereClauses = [];
    $values = [];
    $i = 1;

    // Search by title, brand, or description
    if (!empty($params['search'])) {
        $whereClauses[] = "(LOWER(title) LIKE LOWER($$i) OR LOWER(brand) LIKE LOWER($$i) OR LOWER(description) LIKE LOWER($$i))";
        $values[] = '%' . $params['search'] . '%';
        $i++;
    }

    // Filter by category
    if (!empty($params['category'])) {
        $whereClauses[] = "category = $$i";
        $values[] = $params['category'];
        $i++;
    }

    // Filter by price range
    if (!empty($params['minPrice'])) {
        $whereClauses[] = "price >= $$i";
        $values[] = $params['minPrice'];
        $i++;
    }
    if (!empty($params['maxPrice'])) {
        $whereClauses[] = "price <= $$i";
        $values[] = $params['maxPrice'];
        $i++;
    }

    // Filter by tags (JSONB array contains)
    if (!empty($params['tags'])) {
        // Assume tags are comma-separated
        $tags = explode(',', $params['tags']);
        $placeholders = [];
        foreach ($tags as $tag) {
            $whereClauses[] = "tags @> $$i::jsonb";
            $values[] = json_encode([$tag]);
            $i++;
        }
    }

    // Construct WHERE clause
    $whereSQL = count($whereClauses) > 0 ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

    // Sorting
    $allowedSortFields = ['price', 'rating', 'title', 'stock', 'id'];
    $sortBy = in_array($params['sortBy'] ?? '', $allowedSortFields) ? $params['sortBy'] : 'id';
    $order = strtoupper($params['order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

    // Pagination
    $limit = is_numeric($params['limit'] ?? null) ? (int)$params['limit'] : 20;
    $offset = is_numeric($params['offset'] ?? null) ? (int)$params['offset'] : 0;

    $query = "SELECT * FROM products $whereSQL ORDER BY $sortBy $order LIMIT $limit OFFSET $offset";

    $result = pg_query_params($db, $query, $values);

    $products = [];
    while ($row = pg_fetch_assoc($result)) {
        parseJsonFields($row);
        $products[] = $row;
    }

    $response->getBody()->write(json_encode($products));
    return $response->withHeader('Content-Type', 'application/json');
});


// GET /product/{id}
$app->get('/product/{id}', function (Request $request, Response $response, $args) use ($db) {
    $id = (int)$args['id'];
    $result = pg_query_params($db, "SELECT * FROM products WHERE id = $1", [$id]);
    if ($row = pg_fetch_assoc($result)) {
        parseJsonFields($row);
        $response->getBody()->write(json_encode($row));
    } else {
        $response->getBody()->write(json_encode(['error' => 'Product not found']));
        return $response->withStatus(404);
    }
    return $response->withHeader('Content-Type', 'application/json');
});

// POST /product
$app->post('/product', function (Request $request, Response $response) use ($db) {
    $data = json_decode($request->getBody()->getContents(), true);

    $query = "INSERT INTO products (title, description, category, price, discountPercentage, rating, stock, tags, brand, sku, weight, dimensions, availabilityStatus, minimumOrderQuantity, meta, images, thumbnail)
              VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17) RETURNING *";

    $params = [
        $data['title'] ?? null,
        $data['description'] ?? null,
        $data['category'] ?? null,
        $data['price'] ?? null,
        $data['discountPercentage'] ?? null,
        $data['rating'] ?? null,
        $data['stock'] ?? null,
        json_encode($data['tags'] ?? []),
        $data['brand'] ?? null,
        $data['sku'] ?? null,
        $data['weight'] ?? null,
        json_encode($data['dimensions'] ?? []),
        $data['availabilityStatus'] ?? null,
        $data['minimumOrderQuantity'] ?? null,
        json_encode($data['meta'] ?? []),
        json_encode($data['images'] ?? []),
        $data['thumbnail'] ?? null,
    ];

    $result = pg_query_params($db, $query, $params);
    $product = pg_fetch_assoc($result);
    parseJsonFields($product);

    $response->getBody()->write(json_encode($product));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
});

// PUT /product/{id}
$app->put('/product/{id}', function (Request $request, Response $response, $args) use ($db) {
    $id = (int)$args['id'];
    $data = json_decode($request->getBody()->getContents(), true);

    $query = "UPDATE products SET
        title = $1, description = $2, category = $3, price = $4, discountPercentage = $5,
        rating = $6, stock = $7, tags = $8, brand = $9, sku = $10, weight = $11,
        dimensions = $12, availabilityStatus = $13, minimumOrderQuantity = $14,
        meta = $15, images = $16, thumbnail = $17 WHERE id = $18 RETURNING *";

    $params = [
        $data['title'] ?? null,
        $data['description'] ?? null,
        $data['category'] ?? null,
        $data['price'] ?? null,
        $data['discountPercentage'] ?? null,
        $data['rating'] ?? null,
        $data['stock'] ?? null,
        json_encode($data['tags'] ?? []),
        $data['brand'] ?? null,
        $data['sku'] ?? null,
        $data['weight'] ?? null,
        json_encode($data['dimensions'] ?? []),
        $data['availabilityStatus'] ?? null,
        $data['minimumOrderQuantity'] ?? null,
        json_encode($data['meta'] ?? []),
        json_encode($data['images'] ?? []),
        $data['thumbnail'] ?? null,
        $id
    ];

    $result = pg_query_params($db, $query, $params);
    if ($row = pg_fetch_assoc($result)) {
        parseJsonFields($row);
        $response->getBody()->write(json_encode($row));
    } else {
        $response->getBody()->write(json_encode(['error' => 'Product not found']));
        return $response->withStatus(404);
    }
    return $response->withHeader('Content-Type', 'application/json');
});

// Patch /product/{id}
$app->patch('/product/{id}', function (Request $request, Response $response, $args) use ($db) {
    $id = (int)$args['id'];
    $data = json_decode($request->getBody()->getContents(), true);

    if (!$data || count($data) === 0) {
        $response->getBody()->write(json_encode(['error' => 'No fields provided for update']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    $setClauses = [];
    $params = [];
    $i = 1;

    // JSON fields
    $jsonFields = ['tags', 'dimensions', 'meta', 'images'];

    foreach ($data as $key => $value) {
        $placeholder = '$' . $i;
        if (in_array($key, $jsonFields)) {
            $value = json_encode($value);
        }
        $setClauses[] = "\"$key\" = $placeholder";
        $params[] = $value;
        $i++;
    }

    $params[] = $id;
    $setClauseString = implode(', ', $setClauses);

    $query = "UPDATE products SET $setClauseString WHERE id = \$$i RETURNING *";
    $result = pg_query_params($db, $query, $params);

    if ($row = pg_fetch_assoc($result)) {
        parseJsonFields($row);
        $response->getBody()->write(json_encode($row));
    } else {
        $response->getBody()->write(json_encode(['error' => 'Product not found']));
        return $response->withStatus(404);
    }

    return $response->withHeader('Content-Type', 'application/json');
});


// DELETE /product/{id}
$app->delete('/product/{id}', function (Request $request, Response $response, $args) use ($db) {
    $id = (int)$args['id'];
    $result = pg_query_params($db, "DELETE FROM products WHERE id = $1 RETURNING *", [$id]);

    if ($row = pg_fetch_assoc($result)) {
        parseJsonFields($row);
        $response->getBody()->write(json_encode(['message' => 'Product deleted', 'product' => $row]));
    } else {
        $response->getBody()->write(json_encode(['error' => 'Product not found']));
        return $response->withStatus(404);
    }

    return $response->withHeader('Content-Type', 'application/json');
});


$app->get('/', function ($request, $response, $args) {
    $response->getBody()->write("Your php server is running....");
    return $response;
});


$app->run();
