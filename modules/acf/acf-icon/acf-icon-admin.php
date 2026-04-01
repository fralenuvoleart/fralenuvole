<?php

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', 'frl_acf_icons_register_rest', 10);
function frl_acf_icons_register_rest()
{
    if (!function_exists('register_rest_route')) {
        return;
    }
    register_rest_route('frl/v1', '/icons', [
        'methods'             => 'GET',
        'permission_callback' => function () {
            return frl_has_access();
        },
        'callback'            => 'frl_acf_icons_rest_search',
        'args'                => [
            'search'   => ['type' => 'string', 'required' => false],
            'page'     => ['type' => 'integer', 'required' => false, 'default' => 1],
            'pageSize' => ['type' => 'integer', 'required' => false, 'default' => 30],
            'folder'   => ['type' => 'string', 'required' => false],
            // Include entries whose relative path starts with `${root}/`
            'root'     => ['type' => 'string', 'required' => false],
            // Exclude entries whose relative path starts with `${exclude_root}/`
            'exclude_root' => ['type' => 'string', 'required' => false],
            // Exclude entries whose relative path starts with ANY of the provided roots: `${r}/`
            'exclude_roots' => ['type' => 'array', 'required' => false, 'items' => ['type' => 'string']],
        ],
    ]);
}

function frl_acf_icon_get_listing(): array
{
    $base_dir = rtrim(FRL_DIR_PATH . FRL_ICONS_RELATIVE_PATH, '/');
    $dir_mtime = is_dir($base_dir) ? filemtime($base_dir) : 0;
    $key = FRL_ICONS_LISTING_CACHE_KEY . '_' . $dir_mtime;

    $result = frl_cache_remember(FRL_ICONS_CACHE_GROUP_ADMIN, $key, static function () use ($base_dir) {
        $base_len = strlen($base_dir) + 1;
        $listing = [];

        if (!is_dir($base_dir)) {
            return [];
        }

        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base_dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($rii as $file) {
            if (!$file->isFile()) continue;
            $path = $file->getPathname();
            $basename = $file->getBasename();
            if (str_starts_with($basename, '.')) continue;
            if (is_link($path)) continue;
            if (!str_ends_with(strtolower($basename), '.svg')) continue;

            $rel = substr($path, $base_len);
            $group = dirname($rel);
            if ($group === '.') $group = '';

            $filename = pathinfo($basename, PATHINFO_FILENAME);
            $parts = $group !== '' ? explode('/', $group) : [];
            $parts[] = $filename;

            $label = implode(' / ', array_map(function($part) {
                return ucwords(str_replace(['-', '_'], ' ', $part));
            }, $parts));

            if (!isset($listing[$group])) $listing[$group] = [];
            $listing[$group][$rel] = $label;
        }

        ksort($listing, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($listing as &$items) {
            asort($items, SORT_NATURAL | SORT_FLAG_CASE);
        }
        unset($items);

        return $listing;
    });

    return is_array($result) ? $result : [];
}

function frl_acf_icons_get_flat_index(): array
{
    $base_dir = rtrim(FRL_DIR_PATH . FRL_ICONS_RELATIVE_PATH, '/');
    $dir_mtime = is_dir($base_dir) ? filemtime($base_dir) : 0;
    $key = 'icons_index_' . $dir_mtime;

    $index = frl_cache_remember(FRL_ICONS_CACHE_GROUP_ADMIN, $key, static function () {
        $listing = frl_acf_icon_get_listing();
        $out = [];
        foreach ($listing as $group => $items) {
            foreach ($items as $rel => $label) {
                $out[] = ['rel' => $rel, 'label' => $label, 'folder' => $group, 'lc' => strtolower($label . ' ' . $rel)];
            }
        }
        return $out;
    });
    return is_array($index) ? $index : [];
}

function frl_acf_icons_rest_search(WP_REST_Request $req)
{
    $q = trim((string)$req->get_param('search'));
    $page = max(1, (int)$req->get_param('page'));
    $pageSize = max(1, min(100, (int)$req->get_param('pageSize') ?: 30));
    $folder = trim((string)$req->get_param('folder'));
    $root = trim((string)$req->get_param('root'));
    $excludeRoot = trim((string)$req->get_param('exclude_root'));
    $excludeRoots = $req->get_param('exclude_roots');
    $excludeRoots = is_array($excludeRoots) ? array_values(array_filter(array_map('strval', $excludeRoots))) : [];

    $all = frl_acf_icons_get_flat_index();

    if ($folder !== '') {
        $all = array_values(array_filter($all, function ($it) use ($folder) {
            return $it['folder'] === $folder;
        }));
    }

    // Prefix include: only items whose `rel` starts with `${root}/`
    if ($root !== '') {
        $prefix = rtrim($root, '/') . '/';
        $all = array_values(array_filter($all, function ($it) use ($prefix) {
            return isset($it['rel']) && str_starts_with($it['rel'], $prefix);
        }));
    }

    // Prefix exclude: remove items whose `rel` starts with `${exclude_root}/`
    if ($excludeRoot !== '') {
        $prefix = rtrim($excludeRoot, '/') . '/';
        $all = array_values(array_filter($all, function ($it) use ($prefix) {
            return !(isset($it['rel']) && str_starts_with($it['rel'], $prefix));
        }));
    }

    if (!empty($excludeRoots)) {
        $prefixes = array_map(function($r){ return rtrim($r, '/') . '/'; }, $excludeRoots);
        $all = array_values(array_filter($all, function ($it) use ($prefixes) {
            if (!isset($it['rel'])) return true;
            foreach ($prefixes as $p) {
                if (str_starts_with($it['rel'], $p)) return false;
            }
            return true;
        }));
    }

    if ($q !== '') {
        $lq = strtolower($q);
        $all = array_values(array_filter($all, function ($it) use ($lq) {
            return str_contains($it['lc'], $lq);
        }));
    }

    if (FRL_ICONS_FAVORITES_FOLDER !== '') {
        usort($all, function($a, $b) {
            $a_fav = ($a['folder'] === FRL_ICONS_FAVORITES_FOLDER);
            $b_fav = ($b['folder'] === FRL_ICONS_FAVORITES_FOLDER);
            if ($a_fav === $b_fav) return 0;
            return $a_fav ? -1 : 1;
        });
    }

    $total = count($all);
    $offset = ($page - 1) * $pageSize;
    $slice = $offset < $total ? array_slice($all, $offset, $pageSize) : [];

    $items = array_map(function ($it) {
        return ['id' => $it['rel'], 'text' => $it['label']];
    }, $slice);

    $response = new WP_REST_Response([
        'results' => $items,
        'pagination' => ['more' => ($offset + $pageSize) < $total]
    ], 200);

    $response->header('Cache-Control', 'private, max-age=3600');
    return $response;
}
