<?php

namespace App\Services\Staff;

use Illuminate\Support\Arr;

/**
 * Makes temp passwords that are easy to read out and type, for example `Kopi-7342-Meja`.
 * Words avoid the letter l and a leading I or O, so nothing looks like a digit.
 */
class TemporaryPasswordGenerator
{
    /**
     * @var list<string>
     */
    private const array WORDS = [
        'Kopi', 'Teh', 'Nasi', 'Roti', 'Meja', 'Pintu', 'Bunga', 'Gajus', 'Susu', 'Kunci',
        'Tikar', 'Sabun', 'Kipas', 'Pasir', 'Pantai', 'Hujan', 'Bukit', 'Sungai', 'Awan', 'Bintang',
        'Mangga', 'Durian', 'Rambutan', 'Pisang', 'Padi', 'Ayam', 'Kucing', 'Rusa', 'Gajah', 'Harimau',
        'Merah', 'Hijau', 'Biru', 'Ungu', 'Putih', 'Emas', 'Perak', 'Batu', 'Kayu', 'Angin',
        'Pagi', 'Senja', 'Teratai', 'Cempaka', 'Nanas', 'Jambu', 'Ceri', 'Kurma', 'Tembikai', 'Api',
        'Coffee', 'Garden', 'River', 'Sunny', 'Pepper', 'Mango', 'Window', 'Tiger', 'Panda', 'Rocket',
        'Paper', 'Green', 'Amber', 'Cedar', 'Daisy', 'Ember', 'Forest', 'Harbor', 'Jasmine', 'Mint',
        'Peach', 'Quartz', 'Rain', 'Sugar', 'Topaz', 'Winter', 'Zebra', 'Breeze', 'Canyon', 'Meadow',
    ];

    public function generate(): string
    {
        return Arr::random(self::WORDS).'-'.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT).'-'.Arr::random(self::WORDS);
    }
}
