<?php

namespace MohammedMojaly\Laralyze\Tests\Fixtures;

use Illuminate\Mail\Mailable;

class WelcomeMail extends Mailable
{
    public function build(): static
    {
        return $this->subject('Welcome')->html('<p>Welcome aboard.</p>');
    }
}
