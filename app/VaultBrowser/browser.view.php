<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$itemUrl = static fn (string $id): string => '/items/' . rawurlencode($id);
$assetUrl = static fn (string $id, string $version): string => '/assets/' . rawurlencode($id) . '/' . rawurlencode($version);
$record = $data['record'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title) ?> · Stashd</title>
    <link rel="icon" href="/favicon/favicon.ico">
    <link rel="stylesheet" href="/vault-browser.css">
</head>
<body class="bg-stone-50 text-stone-900 antialiased">
<header class="border-b border-stone-200 bg-white"><nav class="mx-auto flex max-w-6xl items-center justify-between px-6 py-5" aria-label="Main"><a class="text-2xl font-bold tracking-tight" href="/">stashd<span class="text-teal-700">.</span></a><span class="text-sm text-stone-500">Local Vault browser · read only</span></nav></header>
<main class="mx-auto max-w-6xl px-6 py-10">
    <a class="text-sm text-teal-700" href="/">← Vault</a>
    <div class="mb-8 mt-6"><p class="text-sm uppercase tracking-widest text-teal-700">Records &amp; Archives</p><h1 class="mt-2 text-4xl font-semibold tracking-tight"><?= $e(
        $title === 'Item' ? (is_string($record['metadata']['title'] ?? null) ? $record['metadata']['title'] : 'Untitled Item') : $title,
    ) ?></h1></div>
    <?php if (isset($data['error'])): ?>
        <section class="rounded-xl border border-amber-200 bg-amber-50 p-6"><h2 class="text-xl font-semibold"><?= $e(
            $data['error'],
        ) ?></h2><p class="mt-2">No verified record or file could be returned for this request. Check the exact identity, version and Vault content.</p></section>
    <?php elseif ($title === 'Vault'): ?>
        <section class="mb-8 rounded-xl border border-stone-200 bg-white p-6"><h2 class="text-lg font-semibold"><?= $e(
            $data['state'],
        ) ?></h2><p class="mt-2 break-all font-mono text-sm"><?= $e(
            $data['identity'] ?? 'No Vault identity loaded',
        ) ?></p><dl class="mt-4 text-sm text-stone-600"><dt>Configured storage</dt><dd class="break-all"><?= $e($config->provider) ?> · <?= $e($config->root) ?></dd></dl></section>
        <h2 class="mb-4 text-xl font-semibold">Archived Items <span class="text-stone-500"><?= count($data['items'] ?? []) ?></span></h2>
        <?php if (
            ($data['items'] ?? []) === []
        ): ?><p class="rounded-xl border border-dashed border-stone-300 p-8 text-stone-600">No readable Items to display. Populate an initialized Vault with the item:commit and asset:import CLI commands.</p><?php endif ?>
        <div class="grid gap-4">
        <?php foreach ($data['items'] ?? [] as $item): ?>
            <a class="block rounded-xl border border-stone-200 bg-white p-6 hover:border-teal-600" href="<?= $e($itemUrl(
                $item['object_id'],
            )) ?>"><div class="flex items-start justify-between gap-4"><h3 class="text-lg font-semibold"><?= $e(
                is_string($item['metadata']['title'] ?? null) ? $item['metadata']['title'] : 'Untitled Item',
            ) ?></h3><span class="rounded-full bg-teal-50 px-3 py-1 font-mono text-sm text-teal-800"><?= $e(
                $item['ocfl_version'],
            ) ?></span></div><p class="mt-2 break-all text-sm text-stone-600"><?= $e($item['uri']) ?></p></a>
        <?php endforeach ?>
        </div>
        <?php if (
            ($data['notices'] ?? []) !== []
        ): ?><details class="mt-8 rounded-xl border border-amber-200 bg-amber-50 p-6"><summary class="cursor-pointer font-semibold">Object notices (<?= count(
            $data['notices'],
        ) ?>)</summary><ul class="mt-3 space-y-2 text-sm"><?php foreach ($data['notices'] as $notice): ?><li class="break-all"><?= $e(
            $notice,
        ) ?></li><?php endforeach ?></ul></details><?php endif ?>
    <?php elseif ($title === 'Item'): ?>
        <section class="rounded-xl border border-stone-200 bg-white p-6"><h2 class="text-lg font-semibold">Item identity</h2><p class="mt-2 break-all"><?= $e(
            $record['uri'],
        ) ?></p><p class="mt-2 break-all font-mono text-xs text-stone-500"><?= $e(
            $record['object_id'],
        ) ?></p><h2 class="mb-3 mt-6 font-semibold">Revision history</h2><nav class="flex flex-wrap gap-2" aria-label="Item revisions"><?php foreach ($data['versions'] as $version => $details): ?><a class="rounded-lg border border-stone-300 px-4 py-2 font-mono text-sm hover:border-teal-600" href="<?= $e(
            $itemUrl($record['object_id']) . '?version=' . $version,
        ) ?>" <?= $version === $data['selected'] ? 'aria-current="page"' : '' ?>><?= $e(
            $version,
        ) ?></a><?php endforeach ?></nav><p class="mt-3 text-sm text-stone-600">Viewing exact Item revision <strong><?= $e(
            $data['selected'],
        ) ?></strong>. Asset pins below belong to this snapshot.</p></section>
        <section class="my-6 rounded-xl border border-stone-200 bg-white p-6"><h2 class="mb-4 text-lg font-semibold">Stored metadata</h2><pre class="overflow-auto whitespace-pre-wrap break-words text-sm"><?= $e(json_encode(
            $record['metadata'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )) ?></pre></section>
        <h2 class="mb-4 text-xl font-semibold">Pinned Assets</h2>
        <?php if (
            $data['pins'] === []
        ): ?><p class="rounded-xl border border-dashed border-stone-300 p-8 text-stone-600">Metadata-only revision. No preserved content is pinned by this Item revision.</p><?php endif ?>
        <?php foreach ($data['pins'] as $pin): ?>
        <section class="mb-4 rounded-xl border border-stone-200 bg-white p-6"><h3 class="text-lg font-semibold"><?= $e(
            $pin['asset']['asset_key'] ?? 'Unavailable Asset',
        ) ?> <span class="font-mono text-teal-700"><?= $e($pin['version']) ?></span></h3><p class="my-3 break-all font-mono text-xs text-stone-500"><?= $e($pin['object_id']) ?></p>
            <?php if (isset($pin['error'])): ?><p class="text-amber-800"><?= $e($pin['error']) ?></p><?php else: ?>
            <a class="text-sm text-teal-700 underline" href="<?= $e($assetUrl($pin['object_id'], $pin['version'])) ?>">Inspect exact Asset version →</a>
            <ul class="mt-4 space-y-2 text-sm"><?php foreach ($pin['asset']['files'] as $path => $file): ?><li class="break-all"><?= $e($path) ?> · <?= $e(
                $file['size'],
            ) ?> bytes · SHA-512 <span class="font-mono text-xs"><?= $e($file['digest']) ?></span></li><?php endforeach ?></ul><?php endif ?>
        </section><?php endforeach ?>
    <?php elseif ($title === 'Asset'): ?>
        <section class="rounded-xl border border-stone-200 bg-white p-6"><h2 class="text-xl font-semibold"><?= $e(
            $record['asset_key'],
        ) ?> <span class="font-mono text-teal-700"><?= $e($data['selected']) ?></span></h2><p class="mt-2 break-all font-mono text-xs text-stone-500"><?= $e(
            $record['object_id'],
        ) ?></p><p class="mt-5 text-sm">Owning Item: <a class="break-all text-teal-700 underline" href="<?= $e($itemUrl($record['item_object_id'])) ?>"><?= $e(
            $record['item_uri'],
        ) ?></a></p><p class="mt-3 text-sm text-stone-600">Exact archived version. File sizes and SHA-512 evidence verified against preserved bytes and OCFL state.</p></section>
        <h2 class="mb-4 mt-8 text-xl font-semibold">Logical files</h2>
        <?php foreach ($record['files'] as $path => $file): ?><section class="mb-4 rounded-xl border border-stone-200 bg-white p-6"><h3 class="break-all font-semibold"><?= $e(
            $path,
        ) ?></h3><p class="my-3 text-sm text-stone-600"><?= $e($file['size']) ?> bytes · SHA-512</p><p class="break-all font-mono text-xs text-stone-600"><?= $e(
            $file['digest'],
        ) ?></p><a class="mt-5 inline-block rounded-lg bg-teal-800 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700" href="<?= $e(
            $assetUrl($record['object_id'], $data['selected']) . '/download?path=' . rawurlencode($path),
        ) ?>">Download original file ↓</a></section><?php endforeach ?>
    <?php endif ?>
</main>
<footer class="mx-auto max-w-6xl border-t border-stone-200 px-6 py-6 text-xs text-stone-500">Canonical Vault records · No catalogue or search index · Local development interface</footer>
</body>
</html>
