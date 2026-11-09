<?php

namespace App\Support;

use Illuminate\Http\Request;
use InvalidArgumentException;

final readonly class ActionContext
{
    /** @var list<string> */
    private const CHANNELS = ['dashboard', 'telegram', 'system', 'cli'];

    public string $channel;

    public ?string $ipAddress;

    public ?string $userAgent;

    public ?string $externalActorId;

    public function __construct(
        string $channel,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $externalActorId = null,
    ) {
        if (! in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException("Unsupported action channel [{$channel}].");
        }

        $this->channel = $channel;
        $this->ipAddress = $ipAddress === null ? null : mb_substr($ipAddress, 0, 45);
        $this->userAgent = $userAgent === null ? null : mb_substr($userAgent, 0, 1024);
        $this->externalActorId = $externalActorId;
    }

    public static function dashboard(Request $request): self
    {
        return new self('dashboard', $request->ip(), $request->userAgent());
    }
}
