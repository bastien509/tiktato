<?php
declare(strict_types=1);

defined('TTT') || exit;

function page_head(string $title, string $bodyClass, array $bodyData = [], array $extraCss = [], string $description = ''): void
{
    $attrs = '';
    foreach ($bodyData as $k => $v) {
        $attrs .= ' data-' . e($k) . '="' . e($v) . '"';
    }
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#070d1c">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="robots" content="<?= $description !== '' ? 'index, follow' : 'noindex' ?>">
<?php if ($description !== ''): ?><meta name="description" content="<?= e($description) ?>">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($description) ?>">
<meta property="og:image" content="<?= e(abs_url('assets/landing/video-poster.jpg')) ?>">
<?php endif; ?>
<title><?= e($title) ?></title>
<link rel="icon" href="<?= e(asset('logo.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lilita+One&family=Nunito:wght@600;700;800;900&display=swap">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<?php foreach ($extraCss as $css): ?><link rel="stylesheet" href="<?= e(asset($css)) ?>">
<?php endforeach; ?>
</head>
<body class="<?= e($bodyClass) ?>" data-base="<?= e(base_path()) ?>"<?= $attrs ?>>
<?php
}

function page_foot(array $scripts): void
{
    foreach ($scripts as $s) {
        echo '<script src="' . e(asset($s)) . '" defer></script>' . "\n";
    }
    echo "</body>\n</html>\n";
}

function flash_html(): string
{
    $html = '';
    foreach (take_flashes() as $f) {
        $html .= '<div class="flash flash-' . e($f['kind']) . '" role="status">' . e($f['msg']) . '</div>';
    }
    return $html;
}

function logo_svg(string $class = 'logo'): string
{
    return '<svg class="' . e($class) . '" viewBox="0 0 64 64" aria-hidden="true">'
        . '<rect x="2" y="2" width="60" height="60" rx="16" fill="#0d1730" stroke="#F5C518" stroke-width="3"/>'
        . '<path d="M23 12v40M41 12v40M12 23h40M12 41h40" stroke="#2a3b63" stroke-width="3" stroke-linecap="round"/>'
        . '<path d="M14 14l6 6M20 14l-6 6" stroke="#ff4d5e" stroke-width="3.4" stroke-linecap="round"/>'
        . '<circle cx="32" cy="32" r="4.6" fill="none" stroke="#22d3ee" stroke-width="3.2"/>'
        . '<path d="M44 44l6 6M50 44l-6 6" stroke="#ff4d5e" stroke-width="3.4" stroke-linecap="round"/>'
        . '<circle cx="47" cy="17" r="4.6" fill="none" stroke="#F5C518" stroke-width="3.2"/>'
        . '</svg>';
}

/** Decorative floating X / O glyphs for the auth background. */
function glyph_field(): string
{
    $out = '<div class="glyphs" aria-hidden="true">';
    for ($i = 0; $i < 14; $i++) {
        $out .= '<span class="g g' . $i . '">' . ($i % 2 ? 'O' : 'X') . '</span>';
    }
    return $out . '</div>';
}
