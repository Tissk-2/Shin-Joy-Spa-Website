<?php

namespace App\Support;

use RuntimeException;

/**
 * Sanitized carrier for Telegram delivery failures.
 *
 * Stores only the permanent/retryable classification and sanitized numeric
 * delivery metadata (HTTP status, Telegram numeric error code, and a valid
 * numeric retry_after). It deliberately never stores the bot token, the
 * tokenized Telegram URL, the chat ID, the message body, customer data, the
 * Telegram description, the response body, the request payload, or the raw
 * connection-exception message — and it never attaches the original exception
 * as `previous`, because that message may contain the tokenized request URL.
 *
 * The message is a single fixed, sanitized string. Callers cannot inject
 * arbitrary content, so the exception is structurally unable to retain
 * customer data, Telegram descriptions, response bodies, chat IDs, tokens, or
 * tokenized URLs.
 */
class TelegramDeliveryException extends RuntimeException
{
    public function __construct(
        private readonly bool $permanent,
        private readonly ?int $httpStatus = null,
        private readonly ?int $telegramErrorCode = null,
        private readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct('Telegram delivery failed.');
    }

    public function permanent(): bool
    {
        return $this->permanent;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function telegramErrorCode(): ?int
    {
        return $this->telegramErrorCode;
    }
}
