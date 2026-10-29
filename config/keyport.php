<?php

return [
    /*
     | How long an agent's claim call waits for work, in seconds. The wire contract lives in
     | ../estatell-keyport/docs/ESTATELL_CONTRACT.md.
     */
    'poll_seconds' => (int) env('KEYPORT_POLL_SECONDS', 25),

    /*
     | How long a claimed job is held for a device before Estatell gives up waiting for its result.
     */
    'lease_seconds' => 120,

    'pairing_minutes' => 10,

    /*
     | A device counts as online when it claimed within this many seconds.
     */
    'online_seconds' => 60,
];
