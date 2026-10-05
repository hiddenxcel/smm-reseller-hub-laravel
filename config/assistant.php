<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Show the chat widget without an AI key
    |--------------------------------------------------------------------------
    |
    | The written answers work with no key, and the widget offers a person when
    | it cannot help, so it is shown either way. Set ASSISTANT_ALWAYS_ON=false to
    | go back to showing it only once the AI key is configured.
    |
    */

    'always_on' => env('ASSISTANT_ALWAYS_ON', true),

];
