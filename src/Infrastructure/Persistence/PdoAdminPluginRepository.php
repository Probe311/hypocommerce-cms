<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use PDO;

final class PdoAdminPluginRepository
{
    private const ALLOWED_MODES = ['sandbox', 'live'];

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT ap.plugin_key, ap.plugin_type, ap.category_id, pc.slug AS category_slug, pc.label AS category_label,
                    ap.label, ap.description, ap.description_long, ap.docs_url, ap.is_enabled, ap.sort_order, ap.mode, ap.config, ap.updated_at
             FROM admin_plugins ap
             INNER JOIN plugin_categories pc ON pc.id = ap.category_id
             ORDER BY ap.sort_order ASC, ap.plugin_key ASC'
        );
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($items)) {
            return [];
        }

        return array_map(function (array $item): array {
            $item['is_enabled'] = ((int) ($item['is_enabled'] ?? 0)) === 1;
            $item['category_id'] = (int) ($item['category_id'] ?? 0);
            $item['sort_order'] = (int) ($item['sort_order'] ?? 100);
            $decoded = json_decode((string) ($item['config'] ?? '{}'), true);
            $item['config'] = is_array($decoded) ? $decoded : [];

            return $item;
        }, $items);
    }

    public function isEnabled(string $pluginKey): bool
    {
        $pluginKey = strtolower(trim($pluginKey));
        if ($pluginKey === '' || !$this->pluginKeyExists($pluginKey)) {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT is_enabled FROM admin_plugins WHERE plugin_key = :plugin_key LIMIT 1');
        $stmt->execute(['plugin_key' => $pluginKey]);

        return ((int) $stmt->fetchColumn()) === 1;
    }

    /**
     * @param array<string,mixed> $config
     */
    public function upsertConfig(string $pluginKey, string $mode, int $priority, ?string $publicLabel, array $config = []): bool
    {
        $pluginKey = strtolower(trim($pluginKey));
        if (!$this->pluginKeyExists($pluginKey)) {
            return false;
        }
        if (!in_array($mode, self::ALLOWED_MODES, true)) {
            return false;
        }

        $mergedConfig = [
            'publicLabel' => $publicLabel ?? ucfirst($pluginKey),
            'priority' => max(1, min(999, $priority)),
        ];

        foreach ($config as $k => $v) {
            if (!is_string($k) || $k === '') {
                continue;
            }
            if (in_array($k, ['publicLabel', 'priority'], true)) {
                continue;
            }
            if (is_scalar($v) || $v === null) {
                $mergedConfig[$k] = $v;
            }
        }

        $stmt = $this->pdo->prepare(
            'UPDATE admin_plugins
             SET mode = :mode, sort_order = :sort_order, config = :config, updated_at = :updated_at
             WHERE plugin_key = :plugin_key'
        );

        return $stmt->execute([
            'mode' => $mode,
            'sort_order' => max(1, min(999, $priority)),
            'config' => json_encode($mergedConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'plugin_key' => $pluginKey,
        ]);
    }

    public function setEnabled(string $pluginKey, bool $enabled): bool
    {
        $pluginKey = strtolower(trim($pluginKey));
        if (!$this->pluginKeyExists($pluginKey)) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE admin_plugins
             SET is_enabled = :enabled, updated_at = :updated_at
             WHERE plugin_key = :plugin_key'
        );

        return $stmt->execute([
            'enabled' => $enabled ? 1 : 0,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'plugin_key' => $pluginKey,
        ]);
    }

    /**
     * @return array{mode:string,sort_order:int,config:array<string,mixed>}|null
     */
    public function getRuntimeConfig(string $pluginKey): ?array
    {
        $pluginKey = strtolower(trim($pluginKey));
        if ($pluginKey === '' || !$this->pluginKeyExists($pluginKey)) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT mode, sort_order, config
             FROM admin_plugins
             WHERE plugin_key = :plugin_key
             LIMIT 1'
        );
        $stmt->execute(['plugin_key' => $pluginKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $decoded = json_decode((string) ($row['config'] ?? '{}'), true);
        return [
            'mode' => in_array((string) ($row['mode'] ?? ''), self::ALLOWED_MODES, true) ? (string) $row['mode'] : 'sandbox',
            'sort_order' => (int) ($row['sort_order'] ?? 100),
            'config' => is_array($decoded) ? $decoded : [],
        ];
    }

    private function pluginKeyExists(string $pluginKey): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM admin_plugins WHERE plugin_key = :plugin_key LIMIT 1');
        $stmt->execute(['plugin_key' => $pluginKey]);

        return (bool) $stmt->fetchColumn();
    }
}
