<?php

namespace Tests\Support;

class FakeOrderBotHandler extends FakeBotHandler
{
    public function bot(): string
    {
        return 'order';
    }
}
