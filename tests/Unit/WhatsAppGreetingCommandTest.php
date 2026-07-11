<?php

namespace Tests\Unit;

use App\Services\WhatsApp\WhatsAppBotHandler;
use ReflectionMethod;
use Tests\TestCase;

class WhatsAppGreetingCommandTest extends TestCase
{
    public function test_greeting_commands_accept_hello_with_name(): void
    {
        $handler = app(WhatsAppBotHandler::class);
        $method = new ReflectionMethod(WhatsAppBotHandler::class, 'isGreetingOrMenuCommand');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($handler, 'HI'));
        $this->assertTrue($method->invoke($handler, 'HELLO'));
        $this->assertTrue($method->invoke($handler, 'HELLO OMEGA'));
        $this->assertTrue($method->invoke($handler, 'HI THERE'));
        $this->assertTrue($method->invoke($handler, 'HEY'));
        $this->assertTrue($method->invoke($handler, 'GOOD MORNING'));
        $this->assertTrue($method->invoke($handler, 'MENU'));

        // Quantity-style product searches must not be treated as greetings.
        $this->assertFalse($method->invoke($handler, 'HI 2 SUGAR'));
        $this->assertFalse($method->invoke($handler, 'HALISI'));
        $this->assertFalse($method->invoke($handler, '2 HELLO'));
    }
}
