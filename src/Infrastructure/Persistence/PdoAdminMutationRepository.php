<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use PDO;
use Ramsey\Uuid\Uuid;

final class PdoAdminMutationRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<int,string> $categorySlugs
     */
    public function upsertProduct(array $payload, array $categorySlugs = []): string
    {
        $id = isset($payload['id']) && is_string($payload['id']) && Uuid::isValid($payload['id'])
            ? $payload['id']
            : Uuid::uuid4()->toString();
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare('SELECT id FROM products WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $exists = $stmt->fetchColumn() !== false;

        $params = [
            'id' => $id,
            'sku' => (string) ($payload['sku'] ?? ''),
            'name' => (string) ($payload['name'] ?? ''),
            'slug' => (string) ($payload['slug'] ?? ''),
            'description' => $payload['description'] ?? null,
            'price' => (float) ($payload['price'] ?? 0),
            'sale_price' => isset($payload['salePrice']) ? (float) $payload['salePrice'] : null,
            'status' => (string) ($payload['status'] ?? 'draft'),
            'type' => (string) ($payload['type'] ?? 'simple'),
            'seo_title' => $payload['seoTitle'] ?? null,
            'seo_description' => $payload['seoDescription'] ?? null,
            'gtin' => $payload['gtin'] ?? null,
            'mpn' => $payload['mpn'] ?? null,
            'editorial_author' => $payload['editorialAuthor'] ?? null,
            'editorial_reviewer' => $payload['editorialReviewer'] ?? null,
            'reviewed_at' => $payload['reviewedAt'] ?? null,
            'updated_at' => $now,
        ];

        if ($exists) {
            $update = $this->pdo->prepare(
                'UPDATE products
                 SET sku = :sku, name = :name, slug = :slug, description = :description, price = :price, sale_price = :sale_price,
                     status = :status, type = :type, seo_title = :seo_title, seo_description = :seo_description, gtin = :gtin,
                     mpn = :mpn, editorial_author = :editorial_author, editorial_reviewer = :editorial_reviewer, reviewed_at = :reviewed_at,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $update->execute($params);
        } else {
            $insert = $this->pdo->prepare(
                'INSERT INTO products
                 (id, sku, name, slug, description, price, sale_price, status, type, seo_title, seo_description, gtin, mpn, editorial_author, editorial_reviewer, reviewed_at, created_at, updated_at)
                 VALUES
                 (:id, :sku, :name, :slug, :description, :price, :sale_price, :status, :type, :seo_title, :seo_description, :gtin, :mpn, :editorial_author, :editorial_reviewer, :reviewed_at, :created_at, :updated_at)'
            );
            $insert->execute(array_merge($params, ['created_at' => $now]));
        }

        $this->syncProductCategories($id, $categorySlugs);
        return $id;
    }

    public function deleteProduct(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM products WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function upsertCategory(array $payload): int
    {
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        $name = (string) ($payload['name'] ?? '');
        $slug = (string) ($payload['slug'] ?? '');
        $parentId = isset($payload['parentId']) ? (int) $payload['parentId'] : null;

        if ($id > 0) {
            $update = $this->pdo->prepare(
                'UPDATE product_categories
                 SET parent_id = :parent_id, name = :name, slug = :slug
                 WHERE id = :id'
            );
            $update->execute([
                'id' => $id,
                'parent_id' => $parentId,
                'name' => $name,
                'slug' => $slug,
            ]);
            return $id;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO product_categories (parent_id, name, slug)
             VALUES (:parent_id, :name, :slug)'
        );
        $insert->execute([
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => $slug,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function deleteCategory(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM product_categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function deleteCoupon(string $code): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM coupons WHERE code = :code');
        $stmt->execute(['code' => strtoupper(trim($code))]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param mixed $value
     */
    public function upsertSetting(string $key, $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = 'null';
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (`key`, value) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        $stmt->execute([
            'key' => $key,
            'value' => $json,
        ]);
    }

    public function deleteSetting(string $key): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM settings WHERE `key` = :key');
        $stmt->execute(['key' => $key]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<int,array<string,mixed>> $operations
     * @return array{updated:int,errors:array<int,string>}
     */
    public function bulkUpdateProducts(array $operations): array
    {
        $updated = 0;
        $errors = [];
        foreach ($operations as $idx => $op) {
            if (!is_array($op)) {
                $errors[] = "row {$idx}: invalid_operation";
                continue;
            }
            $id = isset($op['id']) ? (string) $op['id'] : '';
            if ($id === '' || !Uuid::isValid($id)) {
                $errors[] = "row {$idx}: invalid_product_id";
                continue;
            }

            $sets = [];
            $params = ['id' => $id];
            if (array_key_exists('price', $op)) {
                $price = (float) $op['price'];
                if ($price <= 0) {
                    $errors[] = "row {$idx}: invalid_price";
                    continue;
                }
                $sets[] = 'price = :price';
                $params['price'] = $price;
            }
            if (array_key_exists('salePrice', $op)) {
                $sets[] = 'sale_price = :sale_price';
                $params['sale_price'] = $op['salePrice'] === null ? null : (float) $op['salePrice'];
            }
            if (array_key_exists('status', $op)) {
                $status = strtolower(trim((string) $op['status']));
                if (!in_array($status, ['draft', 'published', 'archived'], true)) {
                    $errors[] = "row {$idx}: invalid_status";
                    continue;
                }
                $sets[] = 'status = :status';
                $params['status'] = $status;
            }
            if (array_key_exists('seoTitle', $op)) {
                $sets[] = 'seo_title = :seo_title';
                $params['seo_title'] = $op['seoTitle'];
            }
            if (array_key_exists('seoDescription', $op)) {
                $sets[] = 'seo_description = :seo_description';
                $params['seo_description'] = $op['seoDescription'];
            }
            if (array_key_exists('gtin', $op)) {
                $sets[] = 'gtin = :gtin';
                $params['gtin'] = $op['gtin'];
            }
            if (array_key_exists('mpn', $op)) {
                $sets[] = 'mpn = :mpn';
                $params['mpn'] = $op['mpn'];
            }
            if (array_key_exists('editorialAuthor', $op)) {
                $sets[] = 'editorial_author = :editorial_author';
                $params['editorial_author'] = $op['editorialAuthor'];
            }
            if (array_key_exists('editorialReviewer', $op)) {
                $sets[] = 'editorial_reviewer = :editorial_reviewer';
                $params['editorial_reviewer'] = $op['editorialReviewer'];
            }
            if (array_key_exists('reviewedAt', $op)) {
                $sets[] = 'reviewed_at = :reviewed_at';
                $params['reviewed_at'] = $op['reviewedAt'];
            }
            if ($sets === []) {
                $errors[] = "row {$idx}: no_fields_to_update";
                continue;
            }
            $sets[] = 'updated_at = :updated_at';
            $params['updated_at'] = (new DateTimeImmutable())->format('Y-m-d H:i:s');

            $sql = 'UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = :id';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->rowCount() > 0) {
                $updated++;
            }
        }

        return ['updated' => $updated, 'errors' => $errors];
    }

    /**
     * @param array<int,string> $categorySlugs
     */
    private function syncProductCategories(string $productId, array $categorySlugs): void
    {
        $this->pdo->prepare('DELETE FROM product_category_pivot WHERE product_id = :product_id')
            ->execute(['product_id' => $productId]);

        if ($categorySlugs === []) {
            return;
        }

        $findCategory = $this->pdo->prepare('SELECT id FROM product_categories WHERE slug = :slug LIMIT 1');
        $attach = $this->pdo->prepare(
            'INSERT IGNORE INTO product_category_pivot (product_id, category_id)
             VALUES (:product_id, :category_id)'
        );
        foreach ($categorySlugs as $slug) {
            $findCategory->execute(['slug' => $slug]);
            $categoryId = $findCategory->fetchColumn();
            if ($categoryId === false) {
                continue;
            }
            $attach->execute([
                'product_id' => $productId,
                'category_id' => (int) $categoryId,
            ]);
        }
    }
}
