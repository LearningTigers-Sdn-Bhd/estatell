import { expect, test } from 'vite-plus/test';
import { cropPlan } from './crop-image';

test('a large crop scales down to the logo size', () => {
    expect(
        cropPlan(
            { x: 100, y: 50, width: 1024, height: 1024 },
            { width: 2000, height: 1200 },
        ),
    ).toEqual({ size: 512, dx: -50, dy: -25, dw: 1000, dh: 600 });
});

test('a small crop keeps its own size', () => {
    expect(
        cropPlan(
            { x: 10, y: 0, width: 200, height: 200 },
            { width: 300, height: 200 },
        ),
    ).toEqual({ size: 200, dx: -10, dy: -0, dw: 300, dh: 200 });
});

test('a zoomed-out crop places the whole image inside the square', () => {
    expect(
        cropPlan(
            { x: 0, y: -100, width: 300, height: 300 },
            { width: 300, height: 100 },
        ),
    ).toEqual({ size: 300, dx: -0, dy: 100, dw: 300, dh: 100 });
});
