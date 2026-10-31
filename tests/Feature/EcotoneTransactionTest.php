<?php

declare(strict_types=1);

use Tests\Support\Ecotone\NativeDeliveryFixture;

it('delivers catalogue history, PREMIS classification and client projection through the application bus', function (): void {
    $f = new NativeDeliveryFixture();
    try {
        $f->database->exec('CREATE TABLE event_probe (kind TEXT, id UUID, fields JSONB, PRIMARY KEY (kind, id))');
        $result = $f->run('event-publish');
        expect($result['hidden'])->toBeTrue();
        expect($result['rolled_back'])->toBeTrue();
        expect($f->count('enqueue'))->toBe(3);
        $f->run('event-consume', ['channel' => 'history']);
        $f->run('event-consume', ['channel' => 'premis']);
        $f->run('event-consume', ['channel' => 'client', 'fail' => true]);
        expect($f->rows('SELECT kind FROM event_probe ORDER BY kind'))->toBe([['kind' => 'history'], ['kind' => 'premis']]);
        expect($f->rows('SELECT DISTINCT id FROM event_probe'))->toBe([['id' => $result['id']]]);
        expect($f->count('enqueue'))->toBe(1);
        $f->expire();
        $f->run('event-consume', ['channel' => 'client']);
        expect($f->count('enqueue'))->toBe(0);
        $client = $f->rows("SELECT id, fields FROM event_probe WHERE kind = 'client'")[0];
        expect($client['id'])->toBe($result['id']);
        expect(json_decode($client['fields'], true, flags: JSON_THROW_ON_ERROR))->toBe(['itemId' => 'item-1', 'checkId' => 'check-1']);
    } finally {
        $f->dispose();
    }
});
