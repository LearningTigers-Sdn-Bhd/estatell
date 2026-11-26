/** A crop area in the image's natural pixels. It can reach past the image edges when the image is zoomed out. */
export type CropArea = { x: number; y: number; width: number; height: number };

/** Where the whole image lands on a square canvas of `size` pixels. */
export type CropPlan = {
    size: number;
    dx: number;
    dy: number;
    dw: number;
    dh: number;
};

/** The longest side of a saved logo, in pixels. */
export const LOGO_MAX_SIZE = 512;

/**
 * Plans the canvas for a square crop. It draws the whole image, offset and scaled, so a crop area past the image
 * edges leaves empty space instead of stretching the image.
 */
export function cropPlan(
    area: CropArea,
    natural: { width: number; height: number },
    maxSize: number = LOGO_MAX_SIZE,
): CropPlan {
    const size = Math.max(1, Math.round(Math.min(area.width, maxSize)));
    const scale = size / area.width;

    return {
        size,
        dx: -area.x * scale,
        dy: -area.y * scale,
        dw: natural.width * scale,
        dh: natural.height * scale,
    };
}

function loadImage(src: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error('The image could not be read.'));
        image.src = src;
    });
}

/**
 * Crops an image to a square file. A PNG keeps its transparency. A JPEG gets a white background where the crop
 * reaches past the image.
 */
export async function cropImage(
    src: string,
    area: CropArea,
    type: 'image/png' | 'image/jpeg',
): Promise<Blob> {
    const image = await loadImage(src);
    const plan = cropPlan(area, {
        width: image.naturalWidth,
        height: image.naturalHeight,
    });

    const canvas = document.createElement('canvas');
    canvas.width = plan.size;
    canvas.height = plan.size;

    const context = canvas.getContext('2d');

    if (!context) {
        throw new Error('The image could not be cropped.');
    }

    if (type === 'image/jpeg') {
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, plan.size, plan.size);
    }

    context.imageSmoothingQuality = 'high';
    context.drawImage(image, plan.dx, plan.dy, plan.dw, plan.dh);

    return new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) =>
                blob
                    ? resolve(blob)
                    : reject(new Error('The image could not be cropped.')),
            type,
            0.9,
        );
    });
}
