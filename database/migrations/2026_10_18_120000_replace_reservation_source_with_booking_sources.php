<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces `reservations.source` and `reservations.channel_name` with a link to the property's booking sources, and
 * backfills every reservation. Plain DB writes with literal values: a migration must not depend on app enums or Actions.
 * See docs/12-reservations/11-booking-sources.md#backfill.
 */
return new class extends Migration
{
    private const ICONS = "'phone','message-circle','mail','globe','users','handshake','briefcase-business','building-2','footprints','ticket','megaphone','star'";

    private const COLORS = "'gray','red','orange','amber','green','teal','blue','indigo','violet','pink'";

    public function up(): void
    {
        $this->createBookingSources();
        $this->insertDefaults();
        $this->insertCredentialSources();

        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('booking_source_id')->nullable()->constrained('booking_sources')->restrictOnDelete();
        });

        $this->mapSimpleSources();
        $this->mapChannelSources();

        if (DB::table('reservations')->whereNull('booking_source_id')->exists()) {
            throw new RuntimeException('Some reservations have no booking source after the backfill.');
        }

        DB::statement('ALTER TABLE reservations ALTER COLUMN booking_source_id SET NOT NULL');
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT reservations_source_check');

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['source', 'channel_name']);
        });
    }

    /**
     * Maps back from the type. It is lossy: a Direct source other than Phone becomes `direct`, and a Company booking
     * made by staff becomes `portal`.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('source', 20)->nullable();
            $table->string('channel_name', 100)->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE reservations r
            SET source = CASE
                    WHEN s.type = 'ota' THEN 'channel'
                    WHEN s.type = 'company' THEN 'portal'
                    WHEN s.type = 'walk_in' THEN 'walk_in'
                    WHEN lower(s.name) = 'phone' THEN 'phone'
                    ELSE 'direct'
                END,
                channel_name = CASE WHEN s.type = 'ota' THEN left(s.name, 100) ELSE NULL END
            FROM booking_sources s
            WHERE s.id = r.booking_source_id
        SQL);

        DB::statement('ALTER TABLE reservations ALTER COLUMN source SET NOT NULL');
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_source_check CHECK (source IN ('direct', 'walk_in', 'phone', 'channel', 'portal'))");

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_source_id');
        });

        Schema::dropIfExists('booking_sources');
    }

    private function createBookingSources(): void
    {
        Schema::create('booking_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('type', 20);
            $table->foreignId('ota_channel_id')->nullable()->constrained('ota_channels')->restrictOnDelete();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order');
            $table->string('badge_icon', 30)->nullable();
            $table->string('badge_letters', 2)->nullable();
            $table->string('badge_color', 20);
            $table->timestamps();

            $table->index(['property_id', 'sort_order']);
        });

        DB::statement('CREATE UNIQUE INDEX booking_sources_property_name_unique ON booking_sources (property_id, lower(name))');
        DB::statement('CREATE UNIQUE INDEX booking_sources_one_system_per_type ON booking_sources (property_id, type) WHERE is_system');
        DB::statement("ALTER TABLE booking_sources ADD CONSTRAINT booking_sources_type_check CHECK (type IN ('direct', 'ota', 'company', 'walk_in'))");
        DB::statement("ALTER TABLE booking_sources ADD CONSTRAINT booking_sources_ota_channel_check CHECK ((type = 'ota') = (ota_channel_id IS NOT NULL))");
        DB::statement("ALTER TABLE booking_sources ADD CONSTRAINT booking_sources_system_type_check CHECK (type NOT IN ('company', 'walk_in') OR is_system)");
        DB::statement('ALTER TABLE booking_sources ADD CONSTRAINT booking_sources_badge_check CHECK ((badge_icon IS NULL) <> (badge_letters IS NULL))');
        DB::statement('ALTER TABLE booking_sources ADD CONSTRAINT booking_sources_badge_icon_check CHECK (badge_icon IS NULL OR badge_icon IN ('.self::ICONS.'))');
        DB::statement("ALTER TABLE booking_sources ADD CONSTRAINT booking_sources_badge_letters_check CHECK (badge_letters IS NULL OR badge_letters ~ '^[A-Z0-9]{1,2}$')");
        DB::statement('ALTER TABLE booking_sources ADD CONSTRAINT booking_sources_badge_color_check CHECK (badge_color IN ('.self::COLORS.'))');
        DB::statement("ALTER TABLE booking_sources ADD CONSTRAINT booking_sources_name_check CHECK (btrim(name) <> '')");
    }

    private function insertDefaults(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO booking_sources (property_id, name, type, is_system, is_active, sort_order, badge_icon, badge_color, created_at, updated_at)
            SELECT p.id, d.name, d.type, d.is_system, true, d.sort_order, d.icon, d.color, NOW(), NOW()
            FROM properties p
            CROSS JOIN (VALUES
                ('Phone', 'direct', false, 1, 'phone', 'gray'),
                ('WhatsApp', 'direct', false, 2, 'message-circle', 'green'),
                ('Email', 'direct', false, 3, 'mail', 'blue'),
                ('Company / Travel agent', 'company', true, 1000, 'briefcase-business', 'amber'),
                ('Walk-in', 'walk_in', true, 1001, 'footprints', 'teal')
            ) AS d(name, type, is_system, sort_order, icon, color)
            ON CONFLICT DO NOTHING
        SQL);
    }

    /**
     * One OTA row for each saved OTA login: the channel's name, or the typed name on Other.
     */
    private function insertCredentialSources(): void
    {
        $rows = DB::table('property_ota_credentials as c')
            ->join('ota_channels as ch', 'ch.id', '=', 'c.ota_channel_id')
            ->orderBy('c.id')
            ->get(['c.property_id', 'c.channel_name', 'ch.id as channel_id', 'ch.code', 'ch.name', 'ch.is_other']);

        foreach ($rows as $row) {
            $name = $row->is_other ? (trim((string) $row->channel_name) ?: 'OTA') : $row->name;
            $this->ensureOtaSource((int) $row->property_id, $name, (int) $row->channel_id, (string) $row->code);
        }
    }

    private function mapSimpleSources(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO booking_sources (property_id, name, type, is_system, is_active, sort_order, badge_icon, badge_color, created_at, updated_at)
            SELECT r.property_id, 'Direct', 'direct', false, true,
                COALESCE((SELECT MAX(b.sort_order) FROM booking_sources b WHERE b.property_id = r.property_id AND NOT b.is_system), 0) + 1,
                'globe', 'indigo', NOW(), NOW()
            FROM reservations r
            WHERE r.source = 'direct'
            GROUP BY r.property_id
            ON CONFLICT DO NOTHING
        SQL);

        DB::statement(<<<'SQL'
            UPDATE reservations r SET booking_source_id = s.id
            FROM booking_sources s
            WHERE s.property_id = r.property_id AND (
                (r.source = 'phone' AND s.type = 'direct' AND s.name = 'Phone')
                OR (r.source = 'walk_in' AND s.type = 'walk_in')
                OR (r.source = 'portal' AND s.type = 'company')
                OR (r.source = 'direct' AND s.type = 'direct' AND s.name = 'Direct')
            )
        SQL);
    }

    /**
     * An old channel booking goes to the OTA row with the same name, then to the real channel with that name, then to
     * Other with the typed name. No name: Other named "OTA".
     */
    private function mapChannelSources(): void
    {
        $pairs = DB::table('reservations')->where('source', 'channel')
            ->select('property_id', 'channel_name')->distinct()->get();

        foreach ($pairs as $pair) {
            $name = mb_substr(trim((string) $pair->channel_name), 0, 60);
            $propertyId = (int) $pair->property_id;
            $source = $name === '' ? null : DB::table('booking_sources')
                ->where('property_id', $propertyId)->where('type', 'ota')->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

            if ($source === null) {
                $channel = $name === '' ? null : DB::table('ota_channels')->where('is_other', false)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
                $channel ??= $this->otherChannel();
                $isOther = (bool) $channel->is_other;
                $sourceId = $this->ensureOtaSource($propertyId, $isOther ? ($name === '' ? 'OTA' : $name) : $channel->name, (int) $channel->id, (string) $channel->code);
            } else {
                $sourceId = (int) $source->id;
            }

            $query = DB::table('reservations')->where('source', 'channel')->where('property_id', $propertyId);
            $pair->channel_name === null ? $query->whereNull('channel_name') : $query->where('channel_name', $pair->channel_name);
            $query->update(['booking_source_id' => $sourceId]);
        }
    }

    private function otherChannel(): stdClass
    {
        $other = DB::table('ota_channels')->where('is_other', true)->first();

        if ($other === null) {
            DB::table('ota_channels')->insert([
                'code' => 'other', 'name' => 'Other', 'sort_order' => ((int) DB::table('ota_channels')->max('sort_order')) + 1,
                'is_active' => true, 'is_other' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $other = DB::table('ota_channels')->where('is_other', true)->first();
        }

        return $other;
    }

    /**
     * Find or create the property's OTA row of this name and return its id. A name taken by another type gets " (OTA)".
     */
    private function ensureOtaSource(int $propertyId, string $name, int $channelId, string $channelCode): int
    {
        $name = mb_substr($name, 0, 60);
        $existing = DB::table('booking_sources')->where('property_id', $propertyId)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        if ($existing !== null && $existing->type === 'ota') {
            return (int) $existing->id;
        }

        if ($existing !== null) {
            $name = mb_substr($name, 0, 54).' (OTA)';
            $again = DB::table('booking_sources')->where('property_id', $propertyId)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

            if ($again !== null) {
                return (int) $again->id;
            }
        }

        preg_match('/[A-Za-z0-9]/', $name, $match);

        return (int) DB::table('booking_sources')->insertGetId([
            'property_id' => $propertyId,
            'name' => $name,
            'type' => 'ota',
            'ota_channel_id' => $channelId,
            'is_system' => false,
            'is_active' => true,
            'sort_order' => ((int) DB::table('booking_sources')->where('property_id', $propertyId)->where('is_system', false)->max('sort_order')) + 1,
            'badge_icon' => null,
            'badge_letters' => strtoupper($match[0] ?? 'O'),
            'badge_color' => match ($channelCode) {
                'booking_com' => 'blue',
                'agoda' => 'red',
                'expedia' => 'amber',
                'airbnb' => 'pink',
                'hostelworld' => 'orange',
                default => 'gray',
            },
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
