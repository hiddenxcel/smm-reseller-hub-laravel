<?php

/*
|--------------------------------------------------------------------------
| The public demo account
|--------------------------------------------------------------------------
|
| An account whose credentials are published, so anyone can look around. That
| makes it a different thing from every other tenant: the person signed in is
| not the person who owns the data, and there may be several of them at once.
|
| Two mechanisms keep it safe, and both are needed:
|
|   read-only — every write is refused. This is the one that prevents harm,
|               because ten minutes is long enough to change the password,
|               point a panel at someone else's URL, or spend AI credits.
|   reset     — the account is rebuilt on a schedule, so anything that does
|               get through (or any state a visitor leaves behind) is
|               temporary. A second line, not the first.
|
| Empty `email` disables both. That is the default deliberately: an install
| that has never heard of the demo must not have a lock looking for an account
| that does not exist, and must not run a destructive command on a guess.
|
*/

return [

    /*
    | Which tenant is the demo, by email. Blank means there isn't one, and
    | every demo behaviour switches itself off.
    |
    | It is matched against the authenticated tenant, so pointing this at a
    | real customer's account would make that account read-only and then
    | delete it on the next reset. Set it to the seeded demo and nothing else.
    */
    'email' => env('DEMO_EMAIL', ''),

    /*
    | How often the account is rebuilt, in minutes. The schedule reads this,
    | so changing it here is enough.
    */
    'reset_minutes' => (int) env('DEMO_RESET_MINUTES', 10),

    /*
    | What a visitor is told when they try to change something. Written as an
    | explanation rather than an error: they have not done anything wrong, and
    | the demo is working exactly as intended.
    */
    'message' => 'This is a demo account, so changes are switched off. Everything else works — have a look around.',

];
