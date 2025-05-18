<?php

namespace yiifusion\mail\logging;

use yii\httpclient\Request;
use yii\httpclient\Response;
use yiifusion\mail\Message;

/**
 * MailLoggerInterface defines the methods that must be implemented by mail loggers.
 *
 * This interface allows for different logger implementations while maintaining
 * a consistent API for the mail transport system.
 *
 * @author YiiFusion Team
 */
interface MailLoggerInterface
{
    /**
     * Logs a mail transport operation.
     *
     * @param string               $message the log message
     * @param array<string, mixed> $data    additional data to log
     * @param int|null             $level   the log level (if null, uses the default log level)
     */
    public function log(string $message, array $data = [], ?int $level = null): void;

    /**
     * Logs a message sending operation.
     *
     * @param Message              $message        the message being sent
     * @param bool                 $isSuccessful   whether the send operation was successful
     * @param string               $transportName  the name of the transport used
     * @param array<string, mixed> $additionalData additional data to log
     */
    public function logSendOperation(
        Message $message,
        bool $isSuccessful,
        string $transportName,
        array $additionalData = []
    ): void;

    /**
     * Logs an HTTP request.
     *
     * @param Request $request the HTTP request to log
     * @param string  $context additional context information
     */
    public function logRequest(Request $request, string $context = ''): void;

    /**
     * Logs an HTTP response.
     *
     * @param Response $response the HTTP response to log
     * @param string   $context  additional context information
     */
    public function logResponse(Response $response, string $context = ''): void;
}
