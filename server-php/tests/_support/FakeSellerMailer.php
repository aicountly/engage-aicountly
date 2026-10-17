<?php

namespace Tests\Support;

use App\Services\Seller\SellerMailer;

/** Seller mailer stand-in: no HTTP / SMTP. Configure whether it is "configured" and whether sends succeed. */
class FakeSellerMailer extends SellerMailer
{
    public ?string $fakeChannel = null;
    public bool $succeed = true;

    /** @var list<array{trigger:string, email:string, vars:array}> */
    public array $sent = [];

    public function channel(): ?string
    {
        return $this->fakeChannel;
    }

    public function send(string $trigger, string $email, string $name, array $vars, string $idempotencyKey): array
    {
        if ($this->fakeChannel === null) {
            return ['sent' => false, 'channel' => null, 'reason' => 'not_configured'];
        }
        if (! $this->succeed) {
            return ['sent' => false, 'channel' => $this->fakeChannel, 'reason' => 'template_inactive_or_gateway'];
        }
        $this->sent[] = ['trigger' => $trigger, 'email' => $email, 'vars' => $vars];

        return ['sent' => true, 'channel' => $this->fakeChannel, 'reason' => null];
    }
}
