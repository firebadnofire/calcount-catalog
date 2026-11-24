<?php
declare(strict_types=1);

$directory = __DIR__;
$files = glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [];

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    $units = ['KB', 'MB', 'GB', 'TB'];
    $value = $bytes / 1024;

    foreach ($units as $unit) {
        if ($value < 1024 || $unit === 'TB') {
            return number_format($value, $value >= 10 ? 1 : 2) . ' ' . $unit;
        }

        $value /= 1024;
    }

    return $bytes . ' B';
}

function humanizeFilename(string $filename): string
{
    $name = pathinfo($filename, PATHINFO_FILENAME);

    // Split CamelCase filenames as a last-resort fallback.
    $name = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $name) ?? $name;
    $name = preg_replace('/[._-]+/', ' ', $name) ?? $name;

    return ucwords(trim($name));
}

function stringValue(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);

    return $value === '' ? null : $value;
}

function numericValue(mixed $value): int|float|null
{
    if (is_int($value) || is_float($value)) {
        return $value;
    }

    if (is_string($value) && is_numeric($value)) {
        return (float) $value;
    }

    return null;
}

function formatNumber(int|float $value): string
{
    return fmod((float) $value, 1.0) === 0.0
        ? number_format((float) $value, 0)
        : rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
}

/**
 * Return the food object from either of these supported structures:
 *
 * {
 *     "name": "Heinz Tomato Ketchup"
 * }
 *
 * or:
 *
 * {
 *     "food": {
 *         "name": "Heinz Tomato Ketchup"
 *     }
 * }
 */
function getFoodObject(array $data): array
{
    if (
        isset($data['food'])
        && is_array($data['food'])
        && !array_is_list($data['food'])
    ) {
        return $data['food'];
    }

    return $data;
}

function readJsonMetadata(string $path, string $filename): array
{
    $metadata = [
        'name' => humanizeFilename($filename),
        'brand' => null,
        'description' => 'CalCount food data',
        'serving' => null,
        'type' => null,
        'barcode' => null,
        'calories' => null,
        'carbs' => null,
        'protein' => null,
        'fat' => null,
        'valid' => true,
        'readable' => true,
        'usedFilenameFallback' => true,
    ];

    $size = filesize($path);

    if ($size === false || $size > 2 * 1024 * 1024) {
        $metadata['description'] = 'Metadata preview unavailable';
        $metadata['readable'] = false;

        return $metadata;
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
        $metadata['description'] = 'Could not read this file';
        $metadata['readable'] = false;

        return $metadata;
    }

    try {
        $data = json_decode(
            $contents,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        $metadata['description'] = 'This file contains invalid JSON';
        $metadata['valid'] = false;

        return $metadata;
    }

    if (!is_array($data) || array_is_list($data)) {
        $metadata['description'] = 'This JSON does not contain a food object';
        $metadata['valid'] = false;

        return $metadata;
    }

    $food = getFoodObject($data);

    /*
     * Read the authoritative display name from JSON.
     *
     * The nested food object's name is checked first. The top-level name is
     * also supported. The filename is used only if neither value exists.
     */
    $jsonName = stringValue($food['name'] ?? null)
        ?? stringValue($data['name'] ?? null);

    if ($jsonName !== null) {
        $metadata['name'] = $jsonName;
        $metadata['usedFilenameFallback'] = false;
    }

    $metadata['brand'] = stringValue($food['brand'] ?? null)
        ?? stringValue($data['brand'] ?? null);

    $metadata['serving'] =
        stringValue($food['servingDescription'] ?? null)
        ?? stringValue($food['serving_description'] ?? null)
        ?? stringValue($data['servingDescription'] ?? null)
        ?? stringValue($data['serving_description'] ?? null);

    $metadata['barcode'] =
        stringValue($food['barcode'] ?? null)
        ?? stringValue($data['barcode'] ?? null);

    $rawType =
        stringValue($food['type'] ?? null)
        ?? stringValue($food['kind'] ?? null)
        ?? stringValue($data['type'] ?? null)
        ?? stringValue($data['kind'] ?? null);

    if ($rawType !== null) {
        $normalizedType = strtolower($rawType);

        if (in_array($normalizedType, ['solid', 'liquid'], true)) {
            $metadata['type'] = ucfirst($normalizedType);
        }
    }

    $explicitDescription =
        stringValue($food['description'] ?? null)
        ?? stringValue($data['description'] ?? null);

    if ($explicitDescription !== null) {
        $metadata['description'] = $explicitDescription;
    } else {
        $descriptionParts = [];

        if ($metadata['brand'] !== null) {
            $descriptionParts[] = $metadata['brand'];
        }

        if ($metadata['serving'] !== null) {
            $descriptionParts[] = 'Serving: ' . $metadata['serving'];
        }

        if ($descriptionParts !== []) {
            $metadata['description'] = implode(' · ', $descriptionParts);
        }
    }

    $nutrition =
        $food['nutritionPerServing']
        ?? $food['nutrition_per_serving']
        ?? $data['nutritionPerServing']
        ?? $data['nutrition_per_serving']
        ?? null;

    if (is_array($nutrition)) {
        $metadata['calories'] = numericValue(
            $nutrition['calories'] ?? null
        );

        $metadata['carbs'] = numericValue(
            $nutrition['carbsGrams']
            ?? $nutrition['carbs_grams']
            ?? null
        );

        $metadata['protein'] = numericValue(
            $nutrition['proteinGrams']
            ?? $nutrition['protein_grams']
            ?? null
        );

        $metadata['fat'] = numericValue(
            $nutrition['fatGrams']
            ?? $nutrition['fat_grams']
            ?? null
        );
    }

    return $metadata;
}

$items = [];

foreach ($files as $path) {
    $filename = basename($path);
    $size = filesize($path);
    $modified = filemtime($path);
    $metadata = readJsonMetadata($path, $filename);

    $searchParts = [
        $metadata['name'],
        $metadata['brand'],
        $metadata['description'],
        $metadata['serving'],
        $metadata['type'],
        $metadata['barcode'],
        $filename,
    ];

    $searchParts = array_filter(
        $searchParts,
        static fn(mixed $value): bool => is_string($value) && $value !== ''
    );

    $items[] = [
        'filename' => $filename,
        'name' => $metadata['name'],
        'brand' => $metadata['brand'],
        'description' => $metadata['description'],
        'serving' => $metadata['serving'],
        'type' => $metadata['type'],
        'barcode' => $metadata['barcode'],
        'calories' => $metadata['calories'],
        'carbs' => $metadata['carbs'],
        'protein' => $metadata['protein'],
        'fat' => $metadata['fat'],
        'valid' => $metadata['valid'],
        'readable' => $metadata['readable'],
        'usedFilenameFallback' => $metadata['usedFilenameFallback'],
        'size' => $size === false ? 0 : $size,
        'modified' => $modified === false ? 0 : $modified,
        'url' => './' . rawurlencode($filename),
        'search' => strtolower(implode(' ', $searchParts)),
    ];
}

usort(
    $items,
    static fn(array $a, array $b): int =>
        strnatcasecmp($a['name'], $b['name'])
);

$totalSize = array_sum(array_column($items, 'size'));

$validCount = count(
    array_filter(
        $items,
        static fn(array $item): bool => $item['valid']
    )
);

$latestModified = empty($items)
    ? null
    : max(array_column($items, 'modified'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <meta name="color-scheme" content="light dark">

    <meta
        name="description"
        content="Download ready-to-import food JSON files for CalCount on Android."
    >

    <title>CalCount Catalog</title>

    <style>
        :root {
            color-scheme: light dark;

            --background: #f5f7fb;
            --surface: #ffffff;
            --surface-raised: #ffffff;
            --surface-muted: #f7f9fc;

            --text: #162033;
            --muted: #647084;
            --border: #d9e0ea;

            --accent: #2f62f4;
            --accent-hover: #214fd2;
            --accent-soft: #e9efff;

            --success: #157347;
            --success-soft: #e8f7ef;

            --warning: #9a6700;
            --warning-soft: #fff4ce;

            --danger: #b42318;
            --danger-soft: #fee9e7;

            --focus: rgba(47, 98, 244, 0.32);
            --shadow: 0 16px 42px rgba(21, 32, 51, 0.08);
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --background: #0c121c;
                --surface: #141c28;
                --surface-raised: #182231;
                --surface-muted: #101722;

                --text: #eff4fb;
                --muted: #a8b3c3;
                --border: #2a3647;

                --accent: #8da9ff;
                --accent-hover: #adc0ff;
                --accent-soft: #202f5e;

                --success: #7ee2ad;
                --success-soft: #17392a;

                --warning: #f6c85f;
                --warning-soft: #3a2e13;

                --danger: #ffaaa2;
                --danger-soft: #482321;

                --focus: rgba(141, 169, 255, 0.34);
                --shadow: 0 18px 46px rgba(0, 0, 0, 0.28);
            }
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            min-height: 100vh;

            background:
                radial-gradient(
                    circle at 12% 0%,
                    rgba(47, 98, 244, 0.16),
                    transparent 34rem
                ),
                var(--background);

            color: var(--text);

            font-family:
                Inter,
                ui-sans-serif,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;

            line-height: 1.5;
        }

        a {
            color: inherit;
        }

        button,
        input,
        select {
            font: inherit;
        }

        code {
            font-family:
                ui-monospace,
                SFMono-Regular,
                Consolas,
                "Liberation Mono",
                monospace;
        }

        .container {
            width: min(1160px, calc(100% - 2rem));
            margin: 0 auto;
            padding: 3.5rem 0 2rem;
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        .hidden {
            display: none !important;
        }

        .hero {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 2rem;
            align-items: end;
            margin-bottom: 1.5rem;
        }

        .brand-row {
            display: flex;
            gap: 0.75rem;
            align-items: center;
            margin-bottom: 1rem;
        }

        .app-mark {
            display: grid;
            width: 3rem;
            height: 3rem;
            place-items: center;
            border-radius: 1rem;
            background: var(--accent);
            color: white;
            box-shadow: var(--shadow);
        }

        .app-mark svg {
            width: 1.55rem;
            height: 1.55rem;
        }

        .eyebrow {
            margin: 0;
            color: var(--accent);
            font-size: 0.78rem;
            font-weight: 850;
            letter-spacing: 0.11em;
            text-transform: uppercase;
        }

        .brand-name {
            margin: 0.1rem 0 0;
            font-size: 0.95rem;
            font-weight: 750;
        }

        h1 {
            margin: 0;
            font-size: clamp(2.4rem, 6vw, 4.8rem);
            line-height: 0.98;
            letter-spacing: -0.055em;
        }

        .intro {
            max-width: 46rem;
            margin: 1.15rem 0 0;
            color: var(--muted);
            font-size: 1.05rem;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(2, minmax(8.25rem, 1fr));
            gap: 0.75rem;
        }

        .stat {
            padding: 1rem 1.1rem;
            border: 1px solid var(--border);
            border-radius: 1rem;
            background: var(--surface);
            box-shadow: var(--shadow);
        }

        .stat strong,
        .stat span {
            display: block;
        }

        .stat strong {
            font-size: 1.2rem;
            line-height: 1.2;
        }

        .stat span {
            margin-top: 0.25rem;
            color: var(--muted);
            font-size: 0.76rem;
        }

        .mission {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 0.9rem;
            align-items: start;
            margin: 1.5rem 0;
            padding: 1rem 1.1rem;
            border: 1px solid var(--border);
            border-radius: 1rem;
            background: var(--surface);
        }

        .mission-icon {
            display: grid;
            width: 2.25rem;
            height: 2.25rem;
            place-items: center;
            border-radius: 0.75rem;
            background: var(--accent-soft);
            color: var(--accent);
        }

        .mission-icon svg {
            width: 1.15rem;
            height: 1.15rem;
        }

        .mission h2 {
            margin: 0;
            font-size: 0.98rem;
        }

        .mission p {
            margin: 0.25rem 0 0;
            color: var(--muted);
            font-size: 0.9rem;
        }

        .toolbar {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 0.8rem;
            align-items: center;
            margin-bottom: 0.75rem;
            padding: 0.85rem;
            border: 1px solid var(--border);
            border-radius: 1.1rem;
            background: var(--surface);
            box-shadow: var(--shadow);
        }

        .search-wrap {
            position: relative;
            display: block;
        }

        .search-wrap > svg {
            position: absolute;
            top: 50%;
            left: 0.95rem;
            width: 1.15rem;
            height: 1.15rem;
            transform: translateY(-50%);
            color: var(--muted);
            pointer-events: none;
        }

        input,
        select {
            min-height: 3rem;
            border: 1px solid var(--border);
            border-radius: 0.8rem;
            background: var(--surface-muted);
            color: var(--text);
        }

        input {
            width: 100%;
            padding: 0 3rem 0 2.8rem;
        }

        select {
            min-width: 12rem;
            padding: 0 2.5rem 0 0.9rem;
        }

        input:focus,
        select:focus,
        .button:focus-visible,
        .clear-search:focus-visible,
        .contact-link:focus-visible {
            outline: 3px solid var(--focus);
            outline-offset: 2px;
        }

        .clear-search {
            position: absolute;
            top: 50%;
            right: 0.5rem;
            display: grid;
            width: 2rem;
            height: 2rem;
            padding: 0;
            place-items: center;
            transform: translateY(-50%);
            border: 0;
            border-radius: 0.6rem;
            background: transparent;
            color: var(--muted);
            cursor: pointer;
        }

        .clear-search:hover {
            background: var(--accent-soft);
            color: var(--accent);
        }

        .clear-search svg {
            width: 1rem;
            height: 1rem;
        }

        .results-row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            margin: 0 0 1rem;
            color: var(--muted);
            font-size: 0.82rem;
        }

        .file-grid {
            display: grid;
            grid-template-columns:
                repeat(auto-fit, minmax(min(100%, 20rem), 1fr));
            gap: 1rem;
        }

        .file-card {
            display: flex;
            flex-direction: column;
            min-height: 20rem;
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 1.2rem;
            background: var(--surface-raised);
            box-shadow: var(--shadow);

            transition:
                transform 160ms ease,
                border-color 160ms ease,
                box-shadow 160ms ease;
        }

        .file-card:hover {
            transform: translateY(-3px);
            border-color: var(--accent);
            box-shadow: 0 20px 48px rgba(21, 32, 51, 0.12);
        }

        .file-main {
            flex: 1;
            padding: 1.25rem;
        }

        .file-heading {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 0.9rem;
            align-items: start;
        }

        .food-icon {
            display: grid;
            width: 3rem;
            height: 3rem;
            place-items: center;
            border-radius: 0.9rem;
            background: var(--accent-soft);
            color: var(--accent);
        }

        .food-icon svg {
            width: 1.4rem;
            height: 1.4rem;
        }

        .file-title {
            margin: 0;
            font-size: 1.12rem;
            line-height: 1.3;
            overflow-wrap: anywhere;
        }

        .file-name {
            margin: 0.28rem 0 0;
            color: var(--muted);

            font-family:
                ui-monospace,
                SFMono-Regular,
                Consolas,
                "Liberation Mono",
                monospace;

            font-size: 0.74rem;
            overflow-wrap: anywhere;
        }

        .description {
            min-height: 2.75rem;
            margin: 1rem 0;
            color: var(--muted);
            font-size: 0.9rem;
        }

        .nutrition {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.45rem;
            margin-bottom: 1rem;
        }

        .nutrition-item {
            min-width: 0;
            padding: 0.55rem 0.35rem;
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            background: var(--surface-muted);
            text-align: center;
        }

        .nutrition-item strong,
        .nutrition-item span {
            display: block;
        }

        .nutrition-item strong {
            font-size: 0.86rem;
            white-space: nowrap;
        }

        .nutrition-item span {
            margin-top: 0.15rem;
            color: var(--muted);
            font-size: 0.64rem;
            text-transform: uppercase;
        }

        .badges {
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
        }

        .badge {
            padding: 0.28rem 0.58rem;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: var(--surface-muted);
            color: var(--muted);
            font-size: 0.7rem;
            font-weight: 750;
        }

        .badge.valid {
            border-color: transparent;
            background: var(--success-soft);
            color: var(--success);
        }

        .badge.warning {
            border-color: transparent;
            background: var(--warning-soft);
            color: var(--warning);
        }

        .badge.invalid {
            border-color: transparent;
            background: var(--danger-soft);
            color: var(--danger);
        }

        .file-footer {
            display: flex;
            gap: 0.75rem;
            align-items: center;
            justify-content: space-between;
            padding: 0.9rem 1.25rem;
            border-top: 1px solid var(--border);
            background: var(--surface-muted);
        }

        .modified {
            color: var(--muted);
            font-size: 0.74rem;
        }

        .button {
            display: inline-flex;
            gap: 0.45rem;
            align-items: center;
            justify-content: center;
            min-height: 2.5rem;
            padding: 0 0.9rem;
            border-radius: 0.72rem;
            background: var(--accent);
            color: white;
            font-size: 0.82rem;
            font-weight: 800;
            text-decoration: none;
            transition:
                background 150ms ease,
                transform 150ms ease;
        }

        .button:hover {
            background: var(--accent-hover);
        }

        .button:active {
            transform: translateY(1px);
        }

        .button svg {
            width: 1rem;
            height: 1rem;
        }

        .button.disabled {
            background: var(--border);
            color: var(--muted);
            cursor: not-allowed;
        }

        .file-actions {
            display: flex;
            gap: 0.55rem;
            align-items: center;
        }

        .button.secondary {
            border: 1px solid var(--accent);
            background: transparent;
            color: var(--accent);
            cursor: pointer;
        }

        .button.secondary:hover {
            background: var(--accent-soft);
        }

        .qr-dialog {
            width: min(42rem, calc(100% - 1.5rem));
            max-height: calc(100dvh - 2rem);
            padding: 0;
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 1.25rem;
            background: var(--surface-raised);
            color: var(--text);
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.34);
        }

        .qr-dialog::backdrop {
            background: rgba(0, 0, 0, 0.62);
            backdrop-filter: blur(3px);
        }

        .qr-dialog-inner {
            display: flex;
            max-height: calc(100dvh - 2rem);
            flex-direction: column;
        }

        .qr-dialog-header {
            display: flex;
            gap: 1rem;
            align-items: start;
            justify-content: space-between;
            padding: 1.2rem 1.25rem 0;
        }

        .qr-dialog-header h2 {
            margin: 0;
            font-size: 1.35rem;
        }

        .qr-dialog-title {
            min-width: 0;
        }

        .qr-food-name {
            margin: 0.25rem 0 0;
            color: var(--muted);
            font-size: 0.86rem;
            overflow-wrap: anywhere;
        }

        .qr-close-icon {
            display: grid;
            width: 2.4rem;
            height: 2.4rem;
            flex: 0 0 auto;
            padding: 0;
            place-items: center;
            border: 0;
            border-radius: 0.7rem;
            background: transparent;
            color: var(--muted);
            cursor: pointer;
        }

        .qr-close-icon:hover {
            background: var(--accent-soft);
            color: var(--accent);
        }

        .qr-close-icon svg {
            width: 1.15rem;
            height: 1.15rem;
        }

        .qr-dialog-body {
            min-height: 0;
            padding: 1rem 1.25rem;
            overflow: hidden;
        }

        .qr-help {
            margin: 0 0 1rem;
            color: var(--muted);
        }

        .qr-stage {
            display: grid;
            min-height: 0;
            padding: 0.7rem;
            place-items: center;
            border: 1px solid var(--border);
            border-radius: 0.9rem;
            background: #ffffff;
        }

        #qr-output {
            display: grid;
            width: 100%;
            place-items: center;
        }

        #qr-output canvas,
        #qr-output img {
            display: block;
            width: min(
                100%,
                32rem,
                calc(100dvh - 18.5rem)
            ) !important;
            height: auto !important;
            max-height: calc(100dvh - 18.5rem);
            object-fit: contain;
            image-rendering: pixelated;
        }

        .qr-status {
            margin: 0;
            color: #334155;
            text-align: center;
        }

        .qr-status.error {
            color: #b42318;
        }

        .qr-dialog-footer {
            display: flex;
            justify-content: flex-end;
            padding: 0.9rem 1.25rem 1.2rem;
            border-top: 1px solid var(--border);
        }

        .qr-dialog-footer .button {
            border: 0;
            cursor: pointer;
        }

        .empty-state {
            padding: 4rem 1.5rem;
            border: 1px dashed var(--border);
            border-radius: 1.2rem;
            background: var(--surface);
            color: var(--muted);
            text-align: center;
        }

        .empty-state h2 {
            margin: 0 0 0.5rem;
            color: var(--text);
        }

        .contact-card {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 1.25rem;
            align-items: center;
            margin-top: 1.5rem;
            padding: 1.25rem;
            border: 1px solid var(--border);
            border-radius: 1.15rem;
            background:
                linear-gradient(
                    135deg,
                    var(--surface),
                    var(--accent-soft)
                );
            box-shadow: var(--shadow);
        }

        .contact-card h2 {
            margin: 0;
            font-size: 1.05rem;
        }

        .contact-card p {
            margin: 0.35rem 0 0;
            color: var(--muted);
            font-size: 0.9rem;
        }

        .contact-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 2.75rem;
            padding: 0 1rem;
            border: 1px solid var(--accent);
            border-radius: 0.75rem;
            background: var(--surface);
            color: var(--accent);
            font-size: 0.84rem;
            font-weight: 800;
            text-decoration: none;
            white-space: nowrap;
        }

        .contact-link:hover {
            background: var(--accent);
            color: white;
        }

        .site-footer {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem 1.5rem;
            justify-content: space-between;
            margin-top: 1.5rem;
            padding: 0 0.2rem;
            color: var(--muted);
            font-size: 0.78rem;
        }

        @media (max-width: 800px) {
            .container {
                padding-top: 2.5rem;
            }

            .hero {
                grid-template-columns: 1fr;
                align-items: start;
            }

            .stats {
                width: 100%;
            }

            .toolbar {
                grid-template-columns: 1fr;
            }

            select {
                width: 100%;
            }

            .contact-card {
                grid-template-columns: 1fr;
            }

            .contact-link {
                width: 100%;
            }
        }

        @media (max-width: 440px) {
            .container {
                width: min(100% - 1rem, 1160px);
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .nutrition {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .file-footer {
                align-items: stretch;
                flex-direction: column;
            }

            .button {
                width: 100%;
            }

            .file-actions {
                width: 100%;
                flex-direction: column;
            }

            .qr-dialog {
                width: calc(100% - 0.75rem);
                max-height: calc(100dvh - 0.75rem);
            }

            .qr-dialog-inner {
                max-height: calc(100dvh - 0.75rem);
            }

            .qr-dialog-header {
                padding: 0.9rem 1rem 0;
            }

            .qr-dialog-body {
                padding: 0.75rem 1rem;
            }

            .qr-help {
                margin-bottom: 0.75rem;
            }

            .qr-dialog-footer {
                padding: 0.7rem 1rem 0.85rem;
            }

            #qr-output canvas,
            #qr-output img {
                width: min(
                    100%,
                    calc(100dvh - 16.5rem)
                ) !important;
                max-height: calc(100dvh - 16.5rem);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>

<body>
    <main class="container">
        <header class="hero">
            <div>
                <div class="brand-row">
                    <div class="app-mark" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M7 3v8M4.5 3v4.5A2.5 2.5 0 0 0 7 10m2.5-7v4.5A2.5 2.5 0 0 1 7 10m0 0v11M16 3c2.2 2 3 4.2 3 6.7 0 2.2-1.2 3.8-3 4.3V3Zm0 11v7"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                    </div>

                    <div>
                        <p class="eyebrow">CalCount for Android</p>
                        <p class="brand-name">Common food catalog</p>
                    </div>
                </div>

                <h1>CalCount Catalog</h1>

                <p class="intro">
                    Download ready-to-import JSON files for common foods and
                    drinks, with serving and nutrition data already filled in.
                </p>
            </div>

            <div class="stats" aria-label="Catalog statistics">
                <div class="stat">
                    <strong><?= $validCount ?></strong>

                    <span>
                        <?= $validCount === 1
                            ? 'valid food file'
                            : 'valid food files' ?>
                    </span>
                </div>

                <div class="stat">
                    <strong><?= escape(formatBytes($totalSize)) ?></strong>
                    <span>combined download size</span>
                </div>
            </div>
        </header>

        <section class="mission" aria-labelledby="mission-title">
            <div class="mission-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none">
                    <path
                        d="M12 3v12m0 0 5-5m-5 5-5-5M5 21h14"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
            </div>

            <div>
                <h2 id="mission-title">Built for quick CalCount imports</h2>

                <p>
                    Choose a food, download its JSON file, then import it into
                    CalCount. Always verify nutrition values against the
                    product label before relying on them.
                </p>
            </div>
        </section>

        <?php if ($items): ?>
            <section class="toolbar" aria-label="Catalog controls">
                <label class="search-wrap">
                    <span class="sr-only">Search foods</span>

                    <svg
                        aria-hidden="true"
                        viewBox="0 0 24 24"
                        fill="none"
                    >
                        <path
                            d="m21 21-4.35-4.35m2.35-5.65a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                        />
                    </svg>

                    <input
                        id="search"
                        type="search"
                        placeholder="Search by food, brand, or filename"
                        autocomplete="off"
                    >

                    <button
                        id="clear-search"
                        class="clear-search hidden"
                        type="button"
                        aria-label="Clear search"
                    >
                        <svg
                            aria-hidden="true"
                            viewBox="0 0 24 24"
                            fill="none"
                        >
                            <path
                                d="m6 6 12 12M18 6 6 18"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            />
                        </svg>
                    </button>
                </label>

                <label>
                    <span class="sr-only">Sort foods</span>

                    <select id="sort">
                        <option value="name-asc">Name: A to Z</option>
                        <option value="name-desc">Name: Z to A</option>
                        <option value="newest">Newest first</option>
                        <option value="oldest">Oldest first</option>
                        <option value="size-desc">
                            Largest file first
                        </option>
                        <option value="size-asc">
                            Smallest file first
                        </option>
                    </select>
                </label>
            </section>

            <div class="results-row">
                <span id="result-count" aria-live="polite">
                    <?= count($items) ?>
                    <?= count($items) === 1 ? 'food' : 'foods' ?> shown
                </span>

                <span>
                    Display names come from each file's
                    <code>name</code> field.
                </span>
            </div>

            <section
                id="file-grid"
                class="file-grid"
                aria-label="Available CalCount food files"
            >
                <?php foreach ($items as $item): ?>
                    <?php
                    $hasNutrition =
                        $item['calories'] !== null
                        || $item['carbs'] !== null
                        || $item['protein'] !== null
                        || $item['fat'] !== null;
                    ?>

                    <article
                        class="file-card"
                        data-search="<?= escape($item['search']) ?>"
                        data-name="<?= escape(strtolower($item['name'])) ?>"
                        data-size="<?= $item['size'] ?>"
                        data-modified="<?= $item['modified'] ?>"
                    >
                        <div class="file-main">
                            <div class="file-heading">
                                <div class="food-icon" aria-hidden="true">
                                    <svg
                                        viewBox="0 0 24 24"
                                        fill="none"
                                    >
                                        <path
                                            d="M7 3v8M4.5 3v4.5A2.5 2.5 0 0 0 7 10m2.5-7v4.5A2.5 2.5 0 0 1 7 10m0 0v11M16 3c2.2 2 3 4.2 3 6.7 0 2.2-1.2 3.8-3 4.3V3Zm0 11v7"
                                            stroke="currentColor"
                                            stroke-width="2"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                        />
                                    </svg>
                                </div>

                                <div>
                                    <h2 class="file-title">
                                        <?= escape($item['name']) ?>
                                    </h2>

                                    <p class="file-name">
                                        <?= escape($item['filename']) ?>
                                    </p>
                                </div>
                            </div>

                            <p class="description">
                                <?= escape($item['description']) ?>
                            </p>

                            <?php if ($hasNutrition): ?>
                                <div
                                    class="nutrition"
                                    aria-label="Nutrition per serving"
                                >
                                    <div class="nutrition-item">
                                        <strong>
                                            <?= $item['calories'] !== null
                                                ? escape(
                                                    formatNumber(
                                                        $item['calories']
                                                    )
                                                )
                                                : '—' ?>
                                        </strong>

                                        <span>Calories</span>
                                    </div>

                                    <div class="nutrition-item">
                                        <strong>
                                            <?= $item['carbs'] !== null
                                                ? escape(
                                                    formatNumber(
                                                        $item['carbs']
                                                    )
                                                ) . ' g'
                                                : '—' ?>
                                        </strong>

                                        <span>Carbs</span>
                                    </div>

                                    <div class="nutrition-item">
                                        <strong>
                                            <?= $item['protein'] !== null
                                                ? escape(
                                                    formatNumber(
                                                        $item['protein']
                                                    )
                                                ) . ' g'
                                                : '—' ?>
                                        </strong>

                                        <span>Protein</span>
                                    </div>

                                    <div class="nutrition-item">
                                        <strong>
                                            <?= $item['fat'] !== null
                                                ? escape(
                                                    formatNumber(
                                                        $item['fat']
                                                    )
                                                ) . ' g'
                                                : '—' ?>
                                        </strong>

                                        <span>Fat</span>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="badges">
                                <span class="badge">
                                    <?= escape(
                                        formatBytes($item['size'])
                                    ) ?>
                                </span>

                                <?php if ($item['type'] !== null): ?>
                                    <span class="badge">
                                        <?= escape($item['type']) ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($item['barcode'] !== null): ?>
                                    <span class="badge">
                                        UPC <?= escape($item['barcode']) ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($item['valid']): ?>
                                    <span class="badge valid">
                                        Valid JSON
                                    </span>
                                <?php else: ?>
                                    <span class="badge invalid">
                                        Invalid JSON
                                    </span>
                                <?php endif; ?>

                                <?php if (
                                    $item['valid']
                                    && $item['usedFilenameFallback']
                                ): ?>
                                    <span class="badge warning">
                                        Missing name field
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <footer class="file-footer">
                            <span class="modified">
                                <?php if ($item['modified'] > 0): ?>
                                    Updated
                                    <?= escape(
                                        date(
                                            'M j, Y',
                                            $item['modified']
                                        )
                                    ) ?>
                                <?php else: ?>
                                    Date unavailable
                                <?php endif; ?>
                            </span>

                            <?php if ($item['valid']): ?>
                                <div class="file-actions">
                                    <button
                                        class="button secondary qr-button"
                                        type="button"
                                        data-url="<?= escape($item['url']) ?>"
                                        data-name="<?= escape($item['name']) ?>"
                                    >
                                        <svg
                                            aria-hidden="true"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                        >
                                            <path
                                                d="M4 4h6v6H4V4Zm10 0h6v6h-6V4ZM4 14h6v6H4v-6Zm11 0h2v2h-2v-2Zm3 0h2v4h-2v-4Zm-4 4h2v2h-2v-2Zm4 2h2v-2"
                                                stroke="currentColor"
                                                stroke-width="2"
                                                stroke-linejoin="round"
                                            />
                                        </svg>

                                        QR
                                    </button>

                                    <a
                                        class="button"
                                        href="<?= escape($item['url']) ?>"
                                        download
                                    >
                                        <svg
                                            aria-hidden="true"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                        >
                                            <path
                                                d="M12 3v12m0 0 5-5m-5 5-5-5M5 21h14"
                                                stroke="currentColor"
                                                stroke-width="2"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                            />
                                        </svg>

                                        Download
                                    </a>
                                </div>
                            <?php else: ?>
                                <span
                                    class="button disabled"
                                    aria-disabled="true"
                                >
                                    Unavailable
                                </span>
                            <?php endif; ?>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </section>

            <div id="no-results" class="empty-state hidden">
                <h2>No matching foods</h2>

                <p>
                    Try a product name, brand, barcode, or filename.
                </p>
            </div>
        <?php else: ?>
            <section class="empty-state">
                <h2>No food files are available yet</h2>

                <p>
                    Add one or more <code>.json</code> files beside
                    <code>index.php</code>.
                </p>
            </section>
        <?php endif; ?>

        <section
            class="contact-card"
            aria-labelledby="submission-title"
        >
            <div>
                <h2 id="submission-title">Submit a food file</h2>

                <p>
                    To submit for review, send an email with an attached JSON
                    file to <strong>contact@archuser.org</strong>.
                </p>
            </div>

            <a
                class="contact-link"
                href="mailto:contact@archuser.org?subject=CalCount%20JSON%20submission"
            >
                Email a submission
            </a>
        </section>

        <footer class="site-footer">
            <span>CalCount community food catalog</span>

            <span>
                <?php if ($latestModified !== null): ?>
                    Catalog last changed
                    <?= escape(
                        date(
                            'F j, Y \a\t g:i A',
                            $latestModified
                        )
                    ) ?>
                <?php else: ?>
                    The catalog updates automatically when files are added or
                    removed.
                <?php endif; ?>
            </span>
        </footer>
    </main>

    <?php if ($items): ?>
        <dialog
            id="qr-dialog"
            class="qr-dialog"
            aria-labelledby="qr-dialog-title"
        >
            <div class="qr-dialog-inner">
                <header class="qr-dialog-header">
                    <div class="qr-dialog-title">
                        <h2 id="qr-dialog-title">QR Export</h2>
                        <p id="qr-food-name" class="qr-food-name"></p>
                    </div>

                    <button
                        id="qr-close-icon"
                        class="qr-close-icon"
                        type="button"
                        aria-label="Close QR export"
                    >
                        <svg
                            aria-hidden="true"
                            viewBox="0 0 24 24"
                            fill="none"
                        >
                            <path
                                d="m6 6 12 12M18 6 6 18"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            />
                        </svg>
                    </button>
                </header>

                <div class="qr-dialog-body">
                    <p class="qr-help">
                        Scan this QR code from another device to import the
                        exact JSON food file.
                    </p>

                    <div class="qr-stage">
                        <div
                            id="qr-output"
                            class="hidden"
                            role="img"
                            aria-label="QR code containing the JSON food file"
                        ></div>

                        <p id="qr-status" class="qr-status">
                            Preparing QR code…
                        </p>
                    </div>
                </div>

                <footer class="qr-dialog-footer">
                    <button
                        id="qr-close"
                        class="button"
                        type="button"
                    >
                        OK
                    </button>
                </footer>
            </div>
        </dialog>

        <script>
var QRCode;!function(){function a(a){this.mode=c.MODE_8BIT_BYTE,this.data=a,this.parsedData=[];for(var b=[],d=0,e=this.data.length;e>d;d++){var f=this.data.charCodeAt(d);f>65536?(b[0]=240|(1835008&f)>>>18,b[1]=128|(258048&f)>>>12,b[2]=128|(4032&f)>>>6,b[3]=128|63&f):f>2048?(b[0]=224|(61440&f)>>>12,b[1]=128|(4032&f)>>>6,b[2]=128|63&f):f>128?(b[0]=192|(1984&f)>>>6,b[1]=128|63&f):b[0]=f,this.parsedData=this.parsedData.concat(b)}this.parsedData.length!=this.data.length&&(this.parsedData.unshift(191),this.parsedData.unshift(187),this.parsedData.unshift(239))}function b(a,b){this.typeNumber=a,this.errorCorrectLevel=b,this.modules=null,this.moduleCount=0,this.dataCache=null,this.dataList=[]}function i(a,b){if(void 0==a.length)throw new Error(a.length+"/"+b);for(var c=0;c<a.length&&0==a[c];)c++;this.num=new Array(a.length-c+b);for(var d=0;d<a.length-c;d++)this.num[d]=a[d+c]}function j(a,b){this.totalCount=a,this.dataCount=b}function k(){this.buffer=[],this.length=0}function m(){return"undefined"!=typeof CanvasRenderingContext2D}function n(){var a=!1,b=navigator.userAgent;return/android/i.test(b)&&(a=!0,aMat=b.toString().match(/android ([0-9]\.[0-9])/i),aMat&&aMat[1]&&(a=parseFloat(aMat[1]))),a}function r(a,b){for(var c=1,e=s(a),f=0,g=l.length;g>=f;f++){var h=0;switch(b){case d.L:h=l[f][0];break;case d.M:h=l[f][1];break;case d.Q:h=l[f][2];break;case d.H:h=l[f][3]}if(h>=e)break;c++}if(c>l.length)throw new Error("Too long data");return c}function s(a){var b=encodeURI(a).toString().replace(/\%[0-9a-fA-F]{2}/g,"a");return b.length+(b.length!=a?3:0)}a.prototype={getLength:function(){return this.parsedData.length},write:function(a){for(var b=0,c=this.parsedData.length;c>b;b++)a.put(this.parsedData[b],8)}},b.prototype={addData:function(b){var c=new a(b);this.dataList.push(c),this.dataCache=null},isDark:function(a,b){if(0>a||this.moduleCount<=a||0>b||this.moduleCount<=b)throw new Error(a+","+b);return this.modules[a][b]},getModuleCount:function(){return this.moduleCount},make:function(){this.makeImpl(!1,this.getBestMaskPattern())},makeImpl:function(a,c){this.moduleCount=4*this.typeNumber+17,this.modules=new Array(this.moduleCount);for(var d=0;d<this.moduleCount;d++){this.modules[d]=new Array(this.moduleCount);for(var e=0;e<this.moduleCount;e++)this.modules[d][e]=null}this.setupPositionProbePattern(0,0),this.setupPositionProbePattern(this.moduleCount-7,0),this.setupPositionProbePattern(0,this.moduleCount-7),this.setupPositionAdjustPattern(),this.setupTimingPattern(),this.setupTypeInfo(a,c),this.typeNumber>=7&&this.setupTypeNumber(a),null==this.dataCache&&(this.dataCache=b.createData(this.typeNumber,this.errorCorrectLevel,this.dataList)),this.mapData(this.dataCache,c)},setupPositionProbePattern:function(a,b){for(var c=-1;7>=c;c++)if(!(-1>=a+c||this.moduleCount<=a+c))for(var d=-1;7>=d;d++)-1>=b+d||this.moduleCount<=b+d||(this.modules[a+c][b+d]=c>=0&&6>=c&&(0==d||6==d)||d>=0&&6>=d&&(0==c||6==c)||c>=2&&4>=c&&d>=2&&4>=d?!0:!1)},getBestMaskPattern:function(){for(var a=0,b=0,c=0;8>c;c++){this.makeImpl(!0,c);var d=f.getLostPoint(this);(0==c||a>d)&&(a=d,b=c)}return b},createMovieClip:function(a,b,c){var d=a.createEmptyMovieClip(b,c),e=1;this.make();for(var f=0;f<this.modules.length;f++)for(var g=f*e,h=0;h<this.modules[f].length;h++){var i=h*e,j=this.modules[f][h];j&&(d.beginFill(0,100),d.moveTo(i,g),d.lineTo(i+e,g),d.lineTo(i+e,g+e),d.lineTo(i,g+e),d.endFill())}return d},setupTimingPattern:function(){for(var a=8;a<this.moduleCount-8;a++)null==this.modules[a][6]&&(this.modules[a][6]=0==a%2);for(var b=8;b<this.moduleCount-8;b++)null==this.modules[6][b]&&(this.modules[6][b]=0==b%2)},setupPositionAdjustPattern:function(){for(var a=f.getPatternPosition(this.typeNumber),b=0;b<a.length;b++)for(var c=0;c<a.length;c++){var d=a[b],e=a[c];if(null==this.modules[d][e])for(var g=-2;2>=g;g++)for(var h=-2;2>=h;h++)this.modules[d+g][e+h]=-2==g||2==g||-2==h||2==h||0==g&&0==h?!0:!1}},setupTypeNumber:function(a){for(var b=f.getBCHTypeNumber(this.typeNumber),c=0;18>c;c++){var d=!a&&1==(1&b>>c);this.modules[Math.floor(c/3)][c%3+this.moduleCount-8-3]=d}for(var c=0;18>c;c++){var d=!a&&1==(1&b>>c);this.modules[c%3+this.moduleCount-8-3][Math.floor(c/3)]=d}},setupTypeInfo:function(a,b){for(var c=this.errorCorrectLevel<<3|b,d=f.getBCHTypeInfo(c),e=0;15>e;e++){var g=!a&&1==(1&d>>e);6>e?this.modules[e][8]=g:8>e?this.modules[e+1][8]=g:this.modules[this.moduleCount-15+e][8]=g}for(var e=0;15>e;e++){var g=!a&&1==(1&d>>e);8>e?this.modules[8][this.moduleCount-e-1]=g:9>e?this.modules[8][15-e-1+1]=g:this.modules[8][15-e-1]=g}this.modules[this.moduleCount-8][8]=!a},mapData:function(a,b){for(var c=-1,d=this.moduleCount-1,e=7,g=0,h=this.moduleCount-1;h>0;h-=2)for(6==h&&h--;;){for(var i=0;2>i;i++)if(null==this.modules[d][h-i]){var j=!1;g<a.length&&(j=1==(1&a[g]>>>e));var k=f.getMask(b,d,h-i);k&&(j=!j),this.modules[d][h-i]=j,e--,-1==e&&(g++,e=7)}if(d+=c,0>d||this.moduleCount<=d){d-=c,c=-c;break}}}},b.PAD0=236,b.PAD1=17,b.createData=function(a,c,d){for(var e=j.getRSBlocks(a,c),g=new k,h=0;h<d.length;h++){var i=d[h];g.put(i.mode,4),g.put(i.getLength(),f.getLengthInBits(i.mode,a)),i.write(g)}for(var l=0,h=0;h<e.length;h++)l+=e[h].dataCount;if(g.getLengthInBits()>8*l)throw new Error("code length overflow. ("+g.getLengthInBits()+">"+8*l+")");for(g.getLengthInBits()+4<=8*l&&g.put(0,4);0!=g.getLengthInBits()%8;)g.putBit(!1);for(;;){if(g.getLengthInBits()>=8*l)break;if(g.put(b.PAD0,8),g.getLengthInBits()>=8*l)break;g.put(b.PAD1,8)}return b.createBytes(g,e)},b.createBytes=function(a,b){for(var c=0,d=0,e=0,g=new Array(b.length),h=new Array(b.length),j=0;j<b.length;j++){var k=b[j].dataCount,l=b[j].totalCount-k;d=Math.max(d,k),e=Math.max(e,l),g[j]=new Array(k);for(var m=0;m<g[j].length;m++)g[j][m]=255&a.buffer[m+c];c+=k;var n=f.getErrorCorrectPolynomial(l),o=new i(g[j],n.getLength()-1),p=o.mod(n);h[j]=new Array(n.getLength()-1);for(var m=0;m<h[j].length;m++){var q=m+p.getLength()-h[j].length;h[j][m]=q>=0?p.get(q):0}}for(var r=0,m=0;m<b.length;m++)r+=b[m].totalCount;for(var s=new Array(r),t=0,m=0;d>m;m++)for(var j=0;j<b.length;j++)m<g[j].length&&(s[t++]=g[j][m]);for(var m=0;e>m;m++)for(var j=0;j<b.length;j++)m<h[j].length&&(s[t++]=h[j][m]);return s};for(var c={MODE_NUMBER:1,MODE_ALPHA_NUM:2,MODE_8BIT_BYTE:4,MODE_KANJI:8},d={L:1,M:0,Q:3,H:2},e={PATTERN000:0,PATTERN001:1,PATTERN010:2,PATTERN011:3,PATTERN100:4,PATTERN101:5,PATTERN110:6,PATTERN111:7},f={PATTERN_POSITION_TABLE:[[],[6,18],[6,22],[6,26],[6,30],[6,34],[6,22,38],[6,24,42],[6,26,46],[6,28,50],[6,30,54],[6,32,58],[6,34,62],[6,26,46,66],[6,26,48,70],[6,26,50,74],[6,30,54,78],[6,30,56,82],[6,30,58,86],[6,34,62,90],[6,28,50,72,94],[6,26,50,74,98],[6,30,54,78,102],[6,28,54,80,106],[6,32,58,84,110],[6,30,58,86,114],[6,34,62,90,118],[6,26,50,74,98,122],[6,30,54,78,102,126],[6,26,52,78,104,130],[6,30,56,82,108,134],[6,34,60,86,112,138],[6,30,58,86,114,142],[6,34,62,90,118,146],[6,30,54,78,102,126,150],[6,24,50,76,102,128,154],[6,28,54,80,106,132,158],[6,32,58,84,110,136,162],[6,26,54,82,110,138,166],[6,30,58,86,114,142,170]],G15:1335,G18:7973,G15_MASK:21522,getBCHTypeInfo:function(a){for(var b=a<<10;f.getBCHDigit(b)-f.getBCHDigit(f.G15)>=0;)b^=f.G15<<f.getBCHDigit(b)-f.getBCHDigit(f.G15);return(a<<10|b)^f.G15_MASK},getBCHTypeNumber:function(a){for(var b=a<<12;f.getBCHDigit(b)-f.getBCHDigit(f.G18)>=0;)b^=f.G18<<f.getBCHDigit(b)-f.getBCHDigit(f.G18);return a<<12|b},getBCHDigit:function(a){for(var b=0;0!=a;)b++,a>>>=1;return b},getPatternPosition:function(a){return f.PATTERN_POSITION_TABLE[a-1]},getMask:function(a,b,c){switch(a){case e.PATTERN000:return 0==(b+c)%2;case e.PATTERN001:return 0==b%2;case e.PATTERN010:return 0==c%3;case e.PATTERN011:return 0==(b+c)%3;case e.PATTERN100:return 0==(Math.floor(b/2)+Math.floor(c/3))%2;case e.PATTERN101:return 0==b*c%2+b*c%3;case e.PATTERN110:return 0==(b*c%2+b*c%3)%2;case e.PATTERN111:return 0==(b*c%3+(b+c)%2)%2;default:throw new Error("bad maskPattern:"+a)}},getErrorCorrectPolynomial:function(a){for(var b=new i([1],0),c=0;a>c;c++)b=b.multiply(new i([1,g.gexp(c)],0));return b},getLengthInBits:function(a,b){if(b>=1&&10>b)switch(a){case c.MODE_NUMBER:return 10;case c.MODE_ALPHA_NUM:return 9;case c.MODE_8BIT_BYTE:return 8;case c.MODE_KANJI:return 8;default:throw new Error("mode:"+a)}else if(27>b)switch(a){case c.MODE_NUMBER:return 12;case c.MODE_ALPHA_NUM:return 11;case c.MODE_8BIT_BYTE:return 16;case c.MODE_KANJI:return 10;default:throw new Error("mode:"+a)}else{if(!(41>b))throw new Error("type:"+b);switch(a){case c.MODE_NUMBER:return 14;case c.MODE_ALPHA_NUM:return 13;case c.MODE_8BIT_BYTE:return 16;case c.MODE_KANJI:return 12;default:throw new Error("mode:"+a)}}},getLostPoint:function(a){for(var b=a.getModuleCount(),c=0,d=0;b>d;d++)for(var e=0;b>e;e++){for(var f=0,g=a.isDark(d,e),h=-1;1>=h;h++)if(!(0>d+h||d+h>=b))for(var i=-1;1>=i;i++)0>e+i||e+i>=b||(0!=h||0!=i)&&g==a.isDark(d+h,e+i)&&f++;f>5&&(c+=3+f-5)}for(var d=0;b-1>d;d++)for(var e=0;b-1>e;e++){var j=0;a.isDark(d,e)&&j++,a.isDark(d+1,e)&&j++,a.isDark(d,e+1)&&j++,a.isDark(d+1,e+1)&&j++,(0==j||4==j)&&(c+=3)}for(var d=0;b>d;d++)for(var e=0;b-6>e;e++)a.isDark(d,e)&&!a.isDark(d,e+1)&&a.isDark(d,e+2)&&a.isDark(d,e+3)&&a.isDark(d,e+4)&&!a.isDark(d,e+5)&&a.isDark(d,e+6)&&(c+=40);for(var e=0;b>e;e++)for(var d=0;b-6>d;d++)a.isDark(d,e)&&!a.isDark(d+1,e)&&a.isDark(d+2,e)&&a.isDark(d+3,e)&&a.isDark(d+4,e)&&!a.isDark(d+5,e)&&a.isDark(d+6,e)&&(c+=40);for(var k=0,e=0;b>e;e++)for(var d=0;b>d;d++)a.isDark(d,e)&&k++;var l=Math.abs(100*k/b/b-50)/5;return c+=10*l}},g={glog:function(a){if(1>a)throw new Error("glog("+a+")");return g.LOG_TABLE[a]},gexp:function(a){for(;0>a;)a+=255;for(;a>=256;)a-=255;return g.EXP_TABLE[a]},EXP_TABLE:new Array(256),LOG_TABLE:new Array(256)},h=0;8>h;h++)g.EXP_TABLE[h]=1<<h;for(var h=8;256>h;h++)g.EXP_TABLE[h]=g.EXP_TABLE[h-4]^g.EXP_TABLE[h-5]^g.EXP_TABLE[h-6]^g.EXP_TABLE[h-8];for(var h=0;255>h;h++)g.LOG_TABLE[g.EXP_TABLE[h]]=h;i.prototype={get:function(a){return this.num[a]},getLength:function(){return this.num.length},multiply:function(a){for(var b=new Array(this.getLength()+a.getLength()-1),c=0;c<this.getLength();c++)for(var d=0;d<a.getLength();d++)b[c+d]^=g.gexp(g.glog(this.get(c))+g.glog(a.get(d)));return new i(b,0)},mod:function(a){if(this.getLength()-a.getLength()<0)return this;for(var b=g.glog(this.get(0))-g.glog(a.get(0)),c=new Array(this.getLength()),d=0;d<this.getLength();d++)c[d]=this.get(d);for(var d=0;d<a.getLength();d++)c[d]^=g.gexp(g.glog(a.get(d))+b);return new i(c,0).mod(a)}},j.RS_BLOCK_TABLE=[[1,26,19],[1,26,16],[1,26,13],[1,26,9],[1,44,34],[1,44,28],[1,44,22],[1,44,16],[1,70,55],[1,70,44],[2,35,17],[2,35,13],[1,100,80],[2,50,32],[2,50,24],[4,25,9],[1,134,108],[2,67,43],[2,33,15,2,34,16],[2,33,11,2,34,12],[2,86,68],[4,43,27],[4,43,19],[4,43,15],[2,98,78],[4,49,31],[2,32,14,4,33,15],[4,39,13,1,40,14],[2,121,97],[2,60,38,2,61,39],[4,40,18,2,41,19],[4,40,14,2,41,15],[2,146,116],[3,58,36,2,59,37],[4,36,16,4,37,17],[4,36,12,4,37,13],[2,86,68,2,87,69],[4,69,43,1,70,44],[6,43,19,2,44,20],[6,43,15,2,44,16],[4,101,81],[1,80,50,4,81,51],[4,50,22,4,51,23],[3,36,12,8,37,13],[2,116,92,2,117,93],[6,58,36,2,59,37],[4,46,20,6,47,21],[7,42,14,4,43,15],[4,133,107],[8,59,37,1,60,38],[8,44,20,4,45,21],[12,33,11,4,34,12],[3,145,115,1,146,116],[4,64,40,5,65,41],[11,36,16,5,37,17],[11,36,12,5,37,13],[5,109,87,1,110,88],[5,65,41,5,66,42],[5,54,24,7,55,25],[11,36,12],[5,122,98,1,123,99],[7,73,45,3,74,46],[15,43,19,2,44,20],[3,45,15,13,46,16],[1,135,107,5,136,108],[10,74,46,1,75,47],[1,50,22,15,51,23],[2,42,14,17,43,15],[5,150,120,1,151,121],[9,69,43,4,70,44],[17,50,22,1,51,23],[2,42,14,19,43,15],[3,141,113,4,142,114],[3,70,44,11,71,45],[17,47,21,4,48,22],[9,39,13,16,40,14],[3,135,107,5,136,108],[3,67,41,13,68,42],[15,54,24,5,55,25],[15,43,15,10,44,16],[4,144,116,4,145,117],[17,68,42],[17,50,22,6,51,23],[19,46,16,6,47,17],[2,139,111,7,140,112],[17,74,46],[7,54,24,16,55,25],[34,37,13],[4,151,121,5,152,122],[4,75,47,14,76,48],[11,54,24,14,55,25],[16,45,15,14,46,16],[6,147,117,4,148,118],[6,73,45,14,74,46],[11,54,24,16,55,25],[30,46,16,2,47,17],[8,132,106,4,133,107],[8,75,47,13,76,48],[7,54,24,22,55,25],[22,45,15,13,46,16],[10,142,114,2,143,115],[19,74,46,4,75,47],[28,50,22,6,51,23],[33,46,16,4,47,17],[8,152,122,4,153,123],[22,73,45,3,74,46],[8,53,23,26,54,24],[12,45,15,28,46,16],[3,147,117,10,148,118],[3,73,45,23,74,46],[4,54,24,31,55,25],[11,45,15,31,46,16],[7,146,116,7,147,117],[21,73,45,7,74,46],[1,53,23,37,54,24],[19,45,15,26,46,16],[5,145,115,10,146,116],[19,75,47,10,76,48],[15,54,24,25,55,25],[23,45,15,25,46,16],[13,145,115,3,146,116],[2,74,46,29,75,47],[42,54,24,1,55,25],[23,45,15,28,46,16],[17,145,115],[10,74,46,23,75,47],[10,54,24,35,55,25],[19,45,15,35,46,16],[17,145,115,1,146,116],[14,74,46,21,75,47],[29,54,24,19,55,25],[11,45,15,46,46,16],[13,145,115,6,146,116],[14,74,46,23,75,47],[44,54,24,7,55,25],[59,46,16,1,47,17],[12,151,121,7,152,122],[12,75,47,26,76,48],[39,54,24,14,55,25],[22,45,15,41,46,16],[6,151,121,14,152,122],[6,75,47,34,76,48],[46,54,24,10,55,25],[2,45,15,64,46,16],[17,152,122,4,153,123],[29,74,46,14,75,47],[49,54,24,10,55,25],[24,45,15,46,46,16],[4,152,122,18,153,123],[13,74,46,32,75,47],[48,54,24,14,55,25],[42,45,15,32,46,16],[20,147,117,4,148,118],[40,75,47,7,76,48],[43,54,24,22,55,25],[10,45,15,67,46,16],[19,148,118,6,149,119],[18,75,47,31,76,48],[34,54,24,34,55,25],[20,45,15,61,46,16]],j.getRSBlocks=function(a,b){var c=j.getRsBlockTable(a,b);if(void 0==c)throw new Error("bad rs block @ typeNumber:"+a+"/errorCorrectLevel:"+b);for(var d=c.length/3,e=[],f=0;d>f;f++)for(var g=c[3*f+0],h=c[3*f+1],i=c[3*f+2],k=0;g>k;k++)e.push(new j(h,i));return e},j.getRsBlockTable=function(a,b){switch(b){case d.L:return j.RS_BLOCK_TABLE[4*(a-1)+0];case d.M:return j.RS_BLOCK_TABLE[4*(a-1)+1];case d.Q:return j.RS_BLOCK_TABLE[4*(a-1)+2];case d.H:return j.RS_BLOCK_TABLE[4*(a-1)+3];default:return void 0}},k.prototype={get:function(a){var b=Math.floor(a/8);return 1==(1&this.buffer[b]>>>7-a%8)},put:function(a,b){for(var c=0;b>c;c++)this.putBit(1==(1&a>>>b-c-1))},getLengthInBits:function(){return this.length},putBit:function(a){var b=Math.floor(this.length/8);this.buffer.length<=b&&this.buffer.push(0),a&&(this.buffer[b]|=128>>>this.length%8),this.length++}};var l=[[17,14,11,7],[32,26,20,14],[53,42,32,24],[78,62,46,34],[106,84,60,44],[134,106,74,58],[154,122,86,64],[192,152,108,84],[230,180,130,98],[271,213,151,119],[321,251,177,137],[367,287,203,155],[425,331,241,177],[458,362,258,194],[520,412,292,220],[586,450,322,250],[644,504,364,280],[718,560,394,310],[792,624,442,338],[858,666,482,382],[929,711,509,403],[1003,779,565,439],[1091,857,611,461],[1171,911,661,511],[1273,997,715,535],[1367,1059,751,593],[1465,1125,805,625],[1528,1190,868,658],[1628,1264,908,698],[1732,1370,982,742],[1840,1452,1030,790],[1952,1538,1112,842],[2068,1628,1168,898],[2188,1722,1228,958],[2303,1809,1283,983],[2431,1911,1351,1051],[2563,1989,1423,1093],[2699,2099,1499,1139],[2809,2213,1579,1219],[2953,2331,1663,1273]],o=function(){var a=function(a,b){this._el=a,this._htOption=b};return a.prototype.draw=function(a){function g(a,b){var c=document.createElementNS("http://www.w3.org/2000/svg",a);for(var d in b)b.hasOwnProperty(d)&&c.setAttribute(d,b[d]);return c}var b=this._htOption,c=this._el,d=a.getModuleCount();Math.floor(b.width/d),Math.floor(b.height/d),this.clear();var h=g("svg",{viewBox:"0 0 "+String(d)+" "+String(d),width:"100%",height:"100%",fill:b.colorLight});h.setAttributeNS("http://www.w3.org/2000/xmlns/","xmlns:xlink","http://www.w3.org/1999/xlink"),c.appendChild(h),h.appendChild(g("rect",{fill:b.colorDark,width:"1",height:"1",id:"template"}));for(var i=0;d>i;i++)for(var j=0;d>j;j++)if(a.isDark(i,j)){var k=g("use",{x:String(i),y:String(j)});k.setAttributeNS("http://www.w3.org/1999/xlink","href","#template"),h.appendChild(k)}},a.prototype.clear=function(){for(;this._el.hasChildNodes();)this._el.removeChild(this._el.lastChild)},a}(),p="svg"===document.documentElement.tagName.toLowerCase(),q=p?o:m()?function(){function a(){this._elImage.src=this._elCanvas.toDataURL("image/png"),this._elImage.style.display="block",this._elCanvas.style.display="none"}function d(a,b){var c=this;if(c._fFail=b,c._fSuccess=a,null===c._bSupportDataURI){var d=document.createElement("img"),e=function(){c._bSupportDataURI=!1,c._fFail&&_fFail.call(c)},f=function(){c._bSupportDataURI=!0,c._fSuccess&&c._fSuccess.call(c)};return d.onabort=e,d.onerror=e,d.onload=f,d.src="data:image/gif;base64,iVBORw0KGgoAAAANSUhEUgAAAAUAAAAFCAYAAACNbyblAAAAHElEQVQI12P4//8/w38GIAXDIBKE0DHxgljNBAAO9TXL0Y4OHwAAAABJRU5ErkJggg==",void 0}c._bSupportDataURI===!0&&c._fSuccess?c._fSuccess.call(c):c._bSupportDataURI===!1&&c._fFail&&c._fFail.call(c)}if(this._android&&this._android<=2.1){var b=1/window.devicePixelRatio,c=CanvasRenderingContext2D.prototype.drawImage;CanvasRenderingContext2D.prototype.drawImage=function(a,d,e,f,g,h,i,j){if("nodeName"in a&&/img/i.test(a.nodeName))for(var l=arguments.length-1;l>=1;l--)arguments[l]=arguments[l]*b;else"undefined"==typeof j&&(arguments[1]*=b,arguments[2]*=b,arguments[3]*=b,arguments[4]*=b);c.apply(this,arguments)}}var e=function(a,b){this._bIsPainted=!1,this._android=n(),this._htOption=b,this._elCanvas=document.createElement("canvas"),this._elCanvas.width=b.width,this._elCanvas.height=b.height,a.appendChild(this._elCanvas),this._el=a,this._oContext=this._elCanvas.getContext("2d"),this._bIsPainted=!1,this._elImage=document.createElement("img"),this._elImage.style.display="none",this._el.appendChild(this._elImage),this._bSupportDataURI=null};return e.prototype.draw=function(a){var b=this._elImage,c=this._oContext,d=this._htOption,e=a.getModuleCount(),f=d.width/e,g=d.height/e,h=Math.round(f),i=Math.round(g);b.style.display="none",this.clear();for(var j=0;e>j;j++)for(var k=0;e>k;k++){var l=a.isDark(j,k),m=k*f,n=j*g;c.strokeStyle=l?d.colorDark:d.colorLight,c.lineWidth=1,c.fillStyle=l?d.colorDark:d.colorLight,c.fillRect(m,n,f,g),c.strokeRect(Math.floor(m)+.5,Math.floor(n)+.5,h,i),c.strokeRect(Math.ceil(m)-.5,Math.ceil(n)-.5,h,i)}this._bIsPainted=!0},e.prototype.makeImage=function(){this._bIsPainted&&d.call(this,a)},e.prototype.isPainted=function(){return this._bIsPainted},e.prototype.clear=function(){this._oContext.clearRect(0,0,this._elCanvas.width,this._elCanvas.height),this._bIsPainted=!1},e.prototype.round=function(a){return a?Math.floor(1e3*a)/1e3:a},e}():function(){var a=function(a,b){this._el=a,this._htOption=b};return a.prototype.draw=function(a){for(var b=this._htOption,c=this._el,d=a.getModuleCount(),e=Math.floor(b.width/d),f=Math.floor(b.height/d),g=['<table style="border:0;border-collapse:collapse;">'],h=0;d>h;h++){g.push("<tr>");for(var i=0;d>i;i++)g.push('<td style="border:0;border-collapse:collapse;padding:0;margin:0;width:'+e+"px;height:"+f+"px;background-color:"+(a.isDark(h,i)?b.colorDark:b.colorLight)+';"></td>');g.push("</tr>")}g.push("</table>"),c.innerHTML=g.join("");var j=c.childNodes[0],k=(b.width-j.offsetWidth)/2,l=(b.height-j.offsetHeight)/2;k>0&&l>0&&(j.style.margin=l+"px "+k+"px")},a.prototype.clear=function(){this._el.innerHTML=""},a}();QRCode=function(a,b){if(this._htOption={width:256,height:256,typeNumber:4,colorDark:"#000000",colorLight:"#ffffff",correctLevel:d.H},"string"==typeof b&&(b={text:b}),b)for(var c in b)this._htOption[c]=b[c];"string"==typeof a&&(a=document.getElementById(a)),this._android=n(),this._el=a,this._oQRCode=null,this._oDrawing=new q(this._el,this._htOption),this._htOption.text&&this.makeCode(this._htOption.text)},QRCode.prototype.makeCode=function(a){this._oQRCode=new b(r(a,this._htOption.correctLevel),this._htOption.correctLevel),this._oQRCode.addData(a),this._oQRCode.make(),this._el.title=a,this._oDrawing.draw(this._oQRCode),this.makeImage()},QRCode.prototype.makeImage=function(){"function"==typeof this._oDrawing.makeImage&&(!this._android||this._android>=3)&&this._oDrawing.makeImage()},QRCode.prototype.clear=function(){this._oDrawing.clear()},QRCode.CorrectLevel=d}();
        </script>

        <script>
            const searchInput = document.getElementById('search');
            const clearSearchButton =
                document.getElementById('clear-search');
            const sortSelect = document.getElementById('sort');
            const grid = document.getElementById('file-grid');
            const noResults = document.getElementById('no-results');
            const resultCount = document.getElementById('result-count');

            function updateCatalog() {
                const query = searchInput.value
                    .trim()
                    .toLocaleLowerCase();

                const cards = Array.from(
                    grid.querySelectorAll('.file-card')
                );

                for (const card of cards) {
                    const searchText = card.dataset.search ?? '';
                    const matches = searchText.includes(query);

                    card.classList.toggle('hidden', !matches);
                }

                const comparators = {
                    'name-asc': (a, b) =>
                        (a.dataset.name ?? '').localeCompare(
                            b.dataset.name ?? '',
                            undefined,
                            {numeric: true}
                        ),

                    'name-desc': (a, b) =>
                        (b.dataset.name ?? '').localeCompare(
                            a.dataset.name ?? '',
                            undefined,
                            {numeric: true}
                        ),

                    'newest': (a, b) =>
                        Number(b.dataset.modified)
                        - Number(a.dataset.modified),

                    'oldest': (a, b) =>
                        Number(a.dataset.modified)
                        - Number(b.dataset.modified),

                    'size-desc': (a, b) =>
                        Number(b.dataset.size)
                        - Number(a.dataset.size),

                    'size-asc': (a, b) =>
                        Number(a.dataset.size)
                        - Number(b.dataset.size)
                };

                const comparator =
                    comparators[sortSelect.value]
                    ?? comparators['name-asc'];

                cards.sort(comparator);

                for (const card of cards) {
                    grid.appendChild(card);
                }

                const visibleCount = cards.filter(
                    card => !card.classList.contains('hidden')
                ).length;

                noResults.classList.toggle(
                    'hidden',
                    visibleCount !== 0
                );

                grid.classList.toggle(
                    'hidden',
                    visibleCount === 0
                );

                clearSearchButton.classList.toggle(
                    'hidden',
                    query === ''
                );

                resultCount.textContent =
                    `${visibleCount} `
                    + `${visibleCount === 1 ? 'food' : 'foods'} shown`;
            }

            searchInput.addEventListener('input', updateCatalog);
            sortSelect.addEventListener('change', updateCatalog);

            clearSearchButton.addEventListener('click', () => {
                searchInput.value = '';
                searchInput.focus();
                updateCatalog();
            });

            const qrDialog = document.getElementById('qr-dialog');
            const qrOutput = document.getElementById('qr-output');
            const qrStatus = document.getElementById('qr-status');
            const qrFoodName = document.getElementById('qr-food-name');
            const qrClose = document.getElementById('qr-close');
            const qrCloseIcon =
                document.getElementById('qr-close-icon');

            function closeQrDialog() {
                qrDialog.close();
            }

            function setQrStatus(message, isError = false) {
                qrOutput.replaceChildren();
                qrOutput.classList.add('hidden');
                qrStatus.classList.remove('hidden');
                qrStatus.classList.toggle('error', isError);
                qrStatus.textContent = message;
            }

            async function openQrDialog(button) {
                const url = button.dataset.url;
                const name = button.dataset.name ?? 'Food export';

                qrFoodName.textContent = name;
                setQrStatus('Preparing QR code…');

                qrDialog.showModal();

                try {
                    if (typeof QRCode !== 'function') {
                        throw new Error(
                            'The embedded QR encoder could not be loaded.'
                        );
                    }

                    const response = await fetch(url, {
                        cache: 'no-store',
                        credentials: 'same-origin'
                    });

                    if (!response.ok) {
                        throw new Error(
                            `Could not load the JSON file `
                            + `(HTTP ${response.status}).`
                        );
                    }

                    const jsonText = await response.text();

                    /*
                     * Validate the file, but encode its original text exactly
                     * as served so the receiving device gets the same export.
                     */
                    JSON.parse(jsonText);

                    qrOutput.replaceChildren();

                    new QRCode(qrOutput, {
                        text: jsonText,
                        width: 640,
                        height: 640,
                        colorDark: '#000000',
                        colorLight: '#ffffff',
                        correctLevel: QRCode.CorrectLevel.L
                    });

                    qrStatus.classList.add('hidden');
                    qrStatus.classList.remove('error');
                    qrOutput.classList.remove('hidden');
                } catch (error) {
                    const message =
                        error instanceof Error
                            ? error.message
                            : 'Could not generate this QR code.';

                    const capacityHint =
                        /too long|code length overflow|overflow/i.test(
                            message
                        )
                            ? ' This JSON file is too large for one QR code. '
                                + 'Use the download instead.'
                            : '';

                    setQrStatus(message + capacityHint, true);
                }
            }

            for (
                const button of document.querySelectorAll('.qr-button')
            ) {
                button.addEventListener('click', () => {
                    openQrDialog(button);
                });
            }

            qrClose.addEventListener('click', closeQrDialog);
            qrCloseIcon.addEventListener('click', closeQrDialog);

            qrDialog.addEventListener('click', event => {
                if (event.target === qrDialog) {
                    closeQrDialog();
                }
            });
        </script>
    <?php endif; ?>
</body>
</html>
