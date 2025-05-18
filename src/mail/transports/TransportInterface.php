<?php

namespace yiifusion\mail\transports;

use yiifusion\mail\Message;

/**
 * TransportInterface is the interface that all mail transport classes must implement.
 *
 * A transport is responsible for the actual delivery of email messages.
 * It can use various methods like SMTP, API calls (SendGrid, Mailgun, etc.), or other means.
 *
 * @author YiiFusion Team
 */
interface TransportInterface
{
    // SECTION: Instance
    /**
     * Returns a new instance of the transport configured with the given options.
     *
     * This method is used by the Mailer to create a transport instance from configuration.
     *
     * Example:
     * ```php
     * $transport = SendGridTransport::getInstance([
     *     'apiKey' => 'your-api-key',
     *     'endpoint' => 'custom-endpoint-url',
     * ]);
     * ```
     *
     * @param array<string, mixed> $config the configuration array
     *
     * @return static the transport instance
     */
    public static function getInstance(array $config = []): static;

    // SECTION: Actions
    /**
     * Sends the given email message.
     *
     * Example:
     * ```php
     * $message = new Message();
     * $message->setFrom('from@example.com')
     *     ->setTo('to@example.com')
     *     ->setSubject('Test message')
     *     ->setTextBody('This is a test message');
     *
     * $transport = new SomeTransport();
     * $result = $transport->send($message);
     * ```
     *
     * @param Message $message the message to be sent
     * @return bool whether the message was sent successfully
     */
    public function send(Message $message): bool;

    /**
     * Sends multiple messages at once.
     *
     * This method allows the transport to optimize sending multiple messages
     * if the underlying transport mechanism supports batch sending.
     *
     * Example:
     * ```php
     * $messages = [
     *     $message1,
     *     $message2,
     *     $message3,
     * ];
     *
     * $transport = new SomeTransport();
     * $successCount = $transport->sendMultiple($messages);
     * ```
     *
     * @param array<int, Message> $messages an array of messages to be sent
     *
     * @return int the number of messages that were successfully sent
     */
    public function sendMultiple(array $messages): int;
}
