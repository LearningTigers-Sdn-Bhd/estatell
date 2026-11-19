import { expect, test } from 'vite-plus/test';
import { buildOutputReport, parseInventoryReport } from './ecrfid';

const request = [0xec, 0x07, 0xff, 0xfd, 0x01, 0x00, 0xaa, 0xbb];

test('unnumbered output report is [length, frame, padding] at the descriptor size', () => {
    const device = {
        collections: [
            {
                outputReports: [
                    {
                        reportId: 0,
                        items: [{ reportSize: 8, reportCount: 64 }],
                    },
                ],
            },
        ],
    };
    const { id, data } = buildOutputReport(device, request);

    expect(id).toBe(0);
    expect(data.length).toBe(64);
    expect(Array.from(data.slice(0, 9))).toEqual([8, ...request]);
});

test('numbered output report uses the frame length as ID', () => {
    const device = {
        collections: [{ outputReports: [{ reportId: 8, items: [] }] }],
    };
    const { id, data } = buildOutputReport(device, request);

    expect(id).toBe(8);
    expect(Array.from(data)).toEqual(request);
});

test('inventory reply parses in both numbered and unnumbered layouts', () => {
    const body = [0x0b, 0xff, 0xfd, 0x01, 0x00, 0x0d, 0xd2, 0x5c, 0xe7];
    let crc = 0xffff;
    for (const b of body) {
        crc ^= b;
        for (let i = 0; i < 8; i++) {
            crc = crc & 1 ? (crc >> 1) ^ 0xa001 : crc >> 1;
        }
    }
    const frame = [0xec, ...body, crc & 0xff, crc >> 8];

    expect(parseInventoryReport(frame)).toEqual([0x0d, 0xd2, 0x5c, 0xe7]);
    expect(parseInventoryReport([frame.length, ...frame, 0, 0, 0])).toEqual([
        0x0d, 0xd2, 0x5c, 0xe7,
    ]);
});
