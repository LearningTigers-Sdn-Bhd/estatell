<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the free-text crew of departures (`default_crew`) and the day runs (`boat_departure_runs`) to crew members,
 * departure crew and main sailings, then drops what they replace. See docs/24-boats/04-sailings.md#migration.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            // 1. One crew member per distinct name of each property, matched ignoring case.
            $names = [];

            foreach (DB::table('boat_departures')->whereNotNull('default_crew')->get(['property_id', 'default_crew']) as $row) {
                $names[$row->property_id] = [...($names[$row->property_id] ?? []), ...$this->split($row->default_crew)];
            }

            foreach (DB::table('boat_departure_runs')->whereNotNull('crew')->get(['property_id', 'crew']) as $row) {
                $names[$row->property_id] = [...($names[$row->property_id] ?? []), ...$this->split($row->crew)];
            }

            $memberIds = [];

            foreach ($names as $propertyId => $list) {
                $existing = DB::table('boat_crew_members')->where('property_id', $propertyId)->get(['id', 'name'])
                    ->mapWithKeys(fn (object $member): array => [mb_strtolower($member->name) => $member->id]);

                foreach ($list as $name) {
                    $key = mb_strtolower($name);

                    if (! $existing->has($key)) {
                        $existing[$key] = DB::table('boat_crew_members')->insertGetId([
                            'property_id' => $propertyId,
                            'name' => $name,
                            'active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                $memberIds[$propertyId] = $existing;
            }

            // 2. Departure crew from default_crew. A departure that already has crew rows keeps them.
            foreach (DB::table('boat_departures')->whereNotNull('default_crew')->get(['id', 'property_id', 'default_crew']) as $departure) {
                if (DB::table('boat_departure_crew')->where('boat_departure_id', $departure->id)->exists()) {
                    continue;
                }

                $this->insertCrew('boat_departure_crew', 'boat_departure_id', $departure->id, $this->split($departure->default_crew), $memberIds[$departure->property_id]);
            }

            // 3. One main sailing per run, with its boat and crew. A date that already has a sailing is left as it is.
            $runs = DB::table('boat_departure_runs')
                ->join('boat_departures', 'boat_departures.id', '=', 'boat_departure_runs.boat_departure_id')
                ->orderBy('boat_departure_runs.id')
                ->get(['boat_departure_runs.*', 'boat_departures.direction']);

            foreach ($runs as $run) {
                if (DB::table('boat_sailings')->where('boat_departure_id', $run->boat_departure_id)->where('date', $run->date)->exists()) {
                    continue;
                }

                $sailingId = DB::table('boat_sailings')->insertGetId([
                    'property_id' => $run->property_id,
                    'date' => $run->date,
                    'kind' => 'guests',
                    'direction' => $run->direction,
                    'boat_departure_id' => $run->boat_departure_id,
                    'boat_id' => $run->boat_id,
                    'created_at' => $run->created_at,
                    'updated_at' => $run->updated_at,
                ]);

                $this->insertCrew('boat_sailing_crew', 'boat_sailing_id', $sailingId, $this->split((string) $run->crew), $memberIds[$run->property_id] ?? collect());
            }
        });

        // 4. Drop what they replace.
        Schema::drop('boat_departure_runs');
        Schema::table('boat_departures', function (Blueprint $table) {
            $table->dropColumn('default_crew');
        });
    }

    /**
     * Reverse the migrations. The main sailings go back to day runs and the departure crew to `default_crew`, with the
     * crew names joined by " / ", the one in charge first. The sailings stay, and a second run skips a date that has one.
     */
    public function down(): void
    {
        Schema::table('boat_departures', function (Blueprint $table) {
            $table->string('default_crew', 120)->nullable()->after('default_boat_id');
        });

        Schema::create('boat_departure_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boat_departure_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('boat_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('crew', 120)->nullable();
            $table->timestamps();
            $table->unique(['boat_departure_id', 'date']);
        });

        DB::statement('ALTER TABLE boat_departure_runs ADD CONSTRAINT boat_departure_runs_not_empty_check CHECK (boat_id IS NOT NULL OR crew IS NOT NULL)');

        foreach (DB::table('boat_departures')->get(['id']) as $departure) {
            $crew = $this->joinedCrew('boat_departure_crew', 'boat_departure_id', $departure->id);

            if ($crew !== null) {
                DB::table('boat_departures')->where('id', $departure->id)->update(['default_crew' => $crew]);
            }
        }

        $mainIds = DB::table('boat_sailings')
            ->where('kind', 'guests')
            ->whereNotNull('boat_departure_id')
            ->groupBy('boat_departure_id', 'date')
            ->selectRaw('min(id) as id')
            ->pluck('id');

        foreach (DB::table('boat_sailings')->whereIn('id', $mainIds)->orderBy('id')->get() as $sailing) {
            $crew = $this->joinedCrew('boat_sailing_crew', 'boat_sailing_id', $sailing->id);

            if ($sailing->boat_id === null && $crew === null) {
                continue;
            }

            DB::table('boat_departure_runs')->insert([
                'property_id' => $sailing->property_id,
                'boat_departure_id' => $sailing->boat_departure_id,
                'date' => $sailing->date,
                'boat_id' => $sailing->boat_id,
                'crew' => $crew,
                'created_at' => $sailing->created_at,
                'updated_at' => $sailing->updated_at,
            ]);
        }
    }

    /**
     * Crew names from "Awang / Daus, Din": split on "/" and ",", trimmed, empty parts dropped, repeated names (ignoring
     * case) kept once, in the order they come.
     *
     * @return list<string>
     */
    private function split(string $text): array
    {
        $names = [];

        foreach (preg_split('/[\/,]/', $text) ?: [] as $part) {
            $name = mb_substr(trim($part), 0, 60);

            if ($name !== '' && ! in_array(mb_strtolower($name), array_map(mb_strtolower(...), $names), true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  list<string>  $names  The first one is in charge.
     * @param  Collection<string, int>  $memberIds  Crew member ids by lower-case name.
     */
    private function insertCrew(string $table, string $ownerColumn, int $ownerId, array $names, Collection $memberIds): void
    {
        foreach ($names as $position => $name) {
            DB::table($table)->insert([
                $ownerColumn => $ownerId,
                'boat_crew_member_id' => $memberIds[mb_strtolower($name)],
                'in_charge' => $position === 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function joinedCrew(string $table, string $ownerColumn, int $ownerId): ?string
    {
        $names = DB::table($table)
            ->join('boat_crew_members', 'boat_crew_members.id', '=', "{$table}.boat_crew_member_id")
            ->where("{$table}.{$ownerColumn}", $ownerId)
            ->orderByDesc("{$table}.in_charge")
            ->orderBy('boat_crew_members.name')
            ->pluck('boat_crew_members.name')
            ->all();

        return $names === [] ? null : mb_substr(implode(' / ', $names), 0, 120);
    }
};
