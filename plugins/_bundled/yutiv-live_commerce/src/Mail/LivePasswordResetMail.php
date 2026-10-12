<?php

namespace Plugins\Yutiv\LiveCommerce\Mail;

use Illuminate\Mail\Mailable;

class LivePasswordResetMail extends Mailable
{
    public function __construct(public readonly string $brand, public readonly string $resetUrl) {}

    public function build(): self
    {
        return $this->from(config('mail.from.address'), $this->brand)->subject($this->brand.' 비밀번호 재설정')->view('teewide::mail.reset', ['brand' => $this->brand, 'resetUrl' => $this->resetUrl]);
    }
}
