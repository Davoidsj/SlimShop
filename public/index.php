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
    "host=%s port=%s dbname=%s user=%s password=%s sslmode=%s",
    $_ENV['DB_HOST'],
    $_ENV['DB_PORT'],
    $_ENV['DB_NAME'],
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    $_ENV['DB_SSLMODE']
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

    // Search by general term
    if (!empty($params['search'])) {
        $whereClauses[] = "(LOWER(title) LIKE LOWER($$i) OR LOWER(brand) LIKE LOWER($$i) OR LOWER(description) LIKE LOWER($$i))";
        $values[] = '%' . $params['search'] . '%';
        $i++;
    }

    // Filter by exact title
    if (!empty($params['title'])) {
        $whereClauses[] = "LOWER(title) = LOWER($$i)";
        $values[] = $params['title'];
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

    // Filter by tags (JSONB contains)
    if (!empty($params['tags'])) {
        $tags = explode(',', $params['tags']);
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

    // Optional Pagination
    $limit = isset($params['limit']) && is_numeric($params['limit']) ? (int)$params['limit'] : null;
    $offset = isset($params['offset']) && is_numeric($params['offset']) ? (int)$params['offset'] : 0;

    $query = "SELECT * FROM products $whereSQL ORDER BY $sortBy $order";
    if ($limit !== null) {
        $query .= " LIMIT $limit OFFSET $offset";
    }

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

// GET /categories - fetch all categories
$app->get('/categories', function (Request $request, Response $response) use ($db) {
    $result = pg_query($db, "SELECT * FROM categories ORDER BY id ASC");

    $categories = [];
    while ($row = pg_fetch_assoc($result)) {
        $categories[] = $row;
    }

    $response->getBody()->write(json_encode($categories));
    return $response->withHeader('Content-Type', 'application/json');
});

// GET /category/{id} - fetch single category by ID
$app->get('/category/{id}', function (Request $request, Response $response, $args) use ($db) {
    $id = (int)$args['id'];

    $result = pg_query_params($db, "SELECT * FROM categories WHERE id = $1", [$id]);

    if ($row = pg_fetch_assoc($result)) {
        $response->getBody()->write(json_encode($row));
    } else {
        $response->getBody()->write(json_encode(['error' => 'Category not found']));
        return $response->withStatus(404);
    }

    return $response->withHeader('Content-Type', 'application/json');
});

// GET /trending
$app->get('/trending', function (Request $request, Response $response) use ($db) {
    $query = "
        SELECT 
            *,
            (
                (rating * 2) +
                (discountPercentage * 0.5) +
                (CASE WHEN stock = 0 THEN 0 ELSE (100.0 / stock) END) +
                (
                    CASE 
                        WHEN (meta->>'updatedAt') IS NOT NULL THEN 
                            (30.0 / (EXTRACT(DAY FROM NOW() - (meta->>'updatedAt')::timestamp) + 1))
                        ELSE 0
                    END
                )
            ) AS trending_score
        FROM products
        ORDER BY trending_score DESC
        LIMIT 10
    ";

    $result = pg_query($db, $query);

    $trending = [];
    while ($row = pg_fetch_assoc($result)) {
        parseJsonFields($row);
        $trending[] = $row;
    }

    $response->getBody()->write(json_encode($trending));
    return $response->withHeader('Content-Type', 'application/json');
});


// GET /similar/{id}
$app->get('/similar/{id}', function (Request $request, Response $response, $args) use ($db) {
    $id = (int)$args['id'];

    // Get the base product
    $result = pg_query_params($db, "SELECT * FROM products WHERE id = $1", [$id]);
    $product = pg_fetch_assoc($result);

    if (!$product) {
        $response->getBody()->write(json_encode(['error' => 'Product not found']));
        return $response->withStatus(404);
    }

    // Build similarity query by category and first tag
    $category = $product['category'];
    $tags = json_decode($product['tags'], true);
    $firstTag = $tags[0] ?? null;

    $sql = "SELECT * FROM products WHERE id != $1 AND category = $2";
    $params = [$id, $category];
    $i = 3;

    if ($firstTag) {
        $sql .= " AND tags @> $$i::jsonb";
        $params[] = json_encode([$firstTag]);
    }

    $sql .= " LIMIT 10";

    $result = pg_query_params($db, $sql, $params);

    $similar = [];
    while ($row = pg_fetch_assoc($result)) {
        parseJsonFields($row);
        $similar[] = $row;
    }

    $response->getBody()->write(json_encode($similar));
    return $response->withHeader('Content-Type', 'application/json');
});

// GET /imagecarousel - fetch image carousel entries
$app->get('/imagecarousel', function (Request $request, Response $response) use ($db) {
    $result = pg_query($db, "SELECT * FROM image_carousel ORDER BY id");

    $carouselImages = [];
    while ($row = pg_fetch_assoc($result)) {
        $carouselImages[] = [
            'id' => (int)$row['id'],
            'title' => $row['title'],
            'image_url' => $row['image_url']
        
        ];
    }

    $response->getBody()->write(json_encode($carouselImages));
    return $response->withHeader('Content-Type', 'application/json');
});

// GET /todays-sales - products on sale today
$app->get('/todays-sales', function (Request $request, Response $response) use ($db) {
    $query = "
        SELECT * FROM products
        WHERE is_on_sale = TRUE
          AND CURRENT_DATE BETWEEN sale_start AND sale_end
        ORDER BY discountPercentage DESC
        LIMIT 10
    ";

    $result = pg_query($db, $query);

    $sales = [];
    while ($row = pg_fetch_assoc($result)) {
        parseJsonFields($row);
        $sales[] = $row;
    }

    $response->getBody()->write(json_encode($sales));
    return $response->withHeader('Content-Type', 'application/json');
});

// GET /cart_items - fetch all or user-specific cart items
$app->get('/cart_items', function (Request $request, Response $response) use ($db) {
    $params = $request->getQueryParams();
    $values = [];
    $whereSQL = '';

    if (!empty($params['user_uid'])) {
        $whereSQL = 'WHERE user_uid = $1';
        $values[] = $params['user_uid'];
    }

    $query = "SELECT * FROM cart_items $whereSQL ORDER BY added_at DESC";
    $result = pg_query_params($db, $query, $values);

    $cartItems = [];
    while ($row = pg_fetch_assoc($result)) {
        $cartItems[] = [
            'id' => (int)$row['id'],
            'user_uid' => $row['user_uid'],
            'product_id' => (int)$row['product_id'],
            'quantity' => (int)$row['quantity'],
            'added_at' => $row['added_at']
        ];
    }

    $response->getBody()->write(json_encode($cartItems));
    return $response->withHeader('Content-Type', 'application/json');
});


$app->get('/', function ($request, $response, $args) {
    $response->getBody()->write("Your php server is running....");
    return $response;
});


$app->run();
