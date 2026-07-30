<?php

namespace Tests\Support;

class FakeSupportBotHandler extends FakeBotHandler
{
    public function bot(): string
    {
        return 'support';
    }
}
