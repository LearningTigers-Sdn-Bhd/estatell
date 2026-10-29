<?php

use App\Models\Room;

beforeEach(function () {
    $this->signInAsScenariosOwner();

    $this->rowKey = fn (string $number): string => 'room-'.Room::query()->where('property_id', $this->property->id)->where('number', $number)->value('id');

    /**
     * Drag a stay bar (or one of its edge handles) with pointer events, as a hand on a mouse would: down, a run of small
     * moves, up. A column is 88 pixels wide at the docs viewport. Without `$release` the pointer stays down, so the
     * screenshot shows the drag in progress.
     */
    $this->drag = function ($page, string $bar, int $columns = 0, ?string $rowKey = null, ?string $edge = null, bool $release = true) {
        $options = json_encode(['bar' => $bar, 'columns' => $columns, 'rowKey' => $rowKey, 'edge' => $edge, 'release' => $release], JSON_THROW_ON_ERROR);

        $end = $page->script(<<<JS
        (async () => {
            const options = {$options};
            const bar = document.querySelector(options.bar);
            const box = bar.getBoundingClientRect();
            let source = bar;
            let startX = box.x + box.width / 2;

            if (options.edge !== null) {
                const findHandle = () => [...bar.parentElement.querySelectorAll('.cursor-col-resize')].find((handle) => {
                    const handleBox = handle.getBoundingClientRect();

                    return options.edge === 'left' ? Math.abs(handleBox.left - box.left) < 2 : Math.abs(handleBox.right - box.right) < 2;
                });

                for (let attempt = 0; attempt < 50 && findHandle() === undefined; attempt++) {
                    await new Promise((resolve) => setTimeout(resolve, 100));
                }

                source = findHandle();
                const edgeBox = source.getBoundingClientRect();
                startX = edgeBox.x + edgeBox.width / 2;
            }

            const startY = box.y + box.height / 2;
            const row = options.rowKey === null ? null : document.querySelector('[data-row-key="' + options.rowKey + '"]').getBoundingClientRect();
            const endX = startX + options.columns * 88;
            const endY = row === null ? startY : row.y + row.height / 2;
            const fire = (target, type, x, y) => target.dispatchEvent(new PointerEvent(type, { bubbles: true, cancelable: true, clientX: x, clientY: y, pointerId: 1, pointerType: 'mouse', isPrimary: true, button: 0, buttons: type === 'pointerup' ? 0 : 1 }));
            const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

            fire(source, 'pointerdown', startX, startY);
            for (let step = 1; step <= 12; step++) {
                fire(document, 'pointermove', startX + (endX - startX) * step / 12, startY + (endY - startY) * step / 12);
                await sleep(30);
            }
            await sleep(600);

            return [endX, endY];
        })()
        JS);

        if ($release) {
            $page->script("document.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerId: 1, pointerType: 'mouse', isPrimary: true, button: 0, clientX: {$end[0]}, clientY: {$end[1]} }));");
        }

        return $page;
    };
});

test('a bar being dragged to another room', function () {
    $page = $this->open(route('front-desk.timeline', [$this->property, 'from' => '2026-10-11', 'to' => '2026-10-24']), height: 1100)
        ->waitForText('Available · Occupancy')
        ->wait(1);

    $this->shoot($page, 'timeline-before-drag', badges: ['Nur Izzati binti Azman', 'Daniel Liew']);

    ($this->drag)($page, 'button[aria-label^="Nur Izzati"]', 0, ($this->rowKey)('104'), release: false);
    $page->wait(1);

    $this->shoot($page, 'drag-room', checkErrors: false);

    $page->script("document.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerId: 1, pointerType: 'mouse', isPrimary: true, button: 0, clientX: 896, clientY: 537 }));");
    $page->waitForText('Move to Room 104')->wait(1);

    $this->shoot($page, 'move-room-dialog', badges: ['From', 'Reason'], frame: '[role="dialog"]');
});

test('the right edge of a bar dragged one day later', function () {
    $page = $this->open(route('front-desk.timeline', $this->property), height: 1100)
        ->waitForText('Available · Occupancy')
        ->wait(2);

    ($this->drag)($page, 'button[aria-label^="Nur Izzati"]', 1, edge: 'right');
    $page->waitForText('Save dates')->wait(1);

    $this->shoot($page, 'change-dates-dialog', badges: ['Was', 'Now', 'Difference'], frame: '[role="dialog"]');
});

test('a bar dragged sideways and to another room', function () {
    $page = $this->open(route('front-desk.timeline', $this->property), height: 1100)
        ->waitForText('Available · Occupancy')
        ->wait(2);

    ($this->drag)($page, 'button[aria-label^="Nur Izzati"]', 1, ($this->rowKey)('107'));
    $page->waitForText('Move stay')->wait(1);

    $this->shoot($page, 'move-stay-dialog', frame: '[role="dialog"]');
});

test('an empty cell', function () {
    $page = $this->open(route('front-desk.timeline', $this->property), height: 1100)
        ->waitForText('Available · Occupancy')
        ->wait(1);

    $page->click('[aria-label="Actions, Room 104, Sat, Oct 17"]')
        ->waitForText('New booking')
        ->wait(1);

    $this->shoot($page, 'empty-cell', badges: ['New booking', 'Block room']);

    $page->click('Block room')
        ->waitForText('A blocked room or bed cannot be sold')
        ->wait(1);

    $this->shoot($page, 'block-room-dialog', frame: '[role="dialog"]');
});
