<?php

/*
 * What OpenAI charges per model, in US dollars per million tokens. The AI assistant logs each turn's cost from these
 * (docs/35-ai-assistant/04-usage-and-cost.md). A model missing here costs nothing in the log, so add a row before
 * changing OPENAI_TEXT_MODEL. Checked against https://developers.openai.com/api/docs/pricing on 2026-11-24.
 */
return [

    'models' => [
        'gpt-6-luna' => ['input' => '0.10', 'cached_input' => '0.01', 'output' => '0.50'],
    ],

];
