<?php

namespace yiifusion\mail;

use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;
use yii\mail\BaseMailer;
use yii\mail\BaseMessage;
use yii\mail\MessageInterface;
use yiifusion\mail\logging\MailLogger;
use yiifusion\mail\logging\MailLoggerInterface;
use yiifusion\mail\transports\TransportInterface;

use function sprintf;

/**
 * Mailer implements a mailer based on Yii2's BaseMailer with support for multiple transports.
 *
 * This class serves as the main entry point for sending emails through various transports
 * like SendGrid, Mailgun, Brevo, or SMTP.
 *
 * Configuration example:
 * ```php
 * return [
 *     'components' => [
 *         'mailer' => [
 *             'class' => 'yiifusion\mail\Mailer',
 *             'transport' => [
 *                 'class' => 'yiifusion\mail\transports\sendgrid\SendGridTransport',
 *                 'apiKey' => 'your-api-key-here',
 *             ],
 *             'messageClass' => 'yiifusion\mail\Message',
 *         ],
 *     ],
 * ];
 * ```
 *
 * Usage example:
 * ```php
 * // Simple usage without view rendering
 * Yii::$app->mailer->compose()
 *     ->setFrom('from@example.com')
 *     ->setTo('to@example.com')
 *     ->setSubject('Test email')
 *     ->setTextBody('Plain text content')
 *     ->setHtmlBody('<b>HTML content</b>')
 *     ->send();
 *
 * // Using view rendering
 * Yii::$app->mailer->compose('contact', ['contactForm' => $form])
 *     ->setFrom('from@example.com')
 *     ->setTo('to@example.com')
 *     ->setSubject('Contact Form')
 *     ->send();
 * ```
 *
 * @property TransportInterface  $transport The mail transport instance.
 * @property MailLoggerInterface $logger    The mail logger instance.
 *
 * @author YiiFusion Team
 */
class Mailer extends BaseMailer
{
    /**
     * @var class-string|string|null the transport configuration.
     */
    public string|null $transportClass = null;

    /**
     * @var array<string, mixed> configuration options for the transport
     */
    public array $transportConfig = [];

    /**
     * @var class-string|null the logger configuration.
     */
    public string|null $loggerClass = MailLogger::class;

    /**
     * @var array<string, mixed> configuration options for the logger
     */
    public array $loggerConfig = [];

    /**
     * @var bool whether to enable logging.
     */
    public bool $loggerEnabled = true;

    // SECTION: Composing
    /**
     * Creates a new message instance and optionally configures it with the given parameters.
     *
     * This method ensures that the message is properly configured with the mailer instance.
     *
     * @param string|array<int, string>|null $view the view to be used for rendering the message body.
     * @param array<string, mixed>           $params the parameters to be passed to the view.
     *
     * @return MessageInterface message instance.
     *
     * @throws InvalidConfigException if the message class is invalid.
     */
    public function compose($view = null, array $params = [])
    {
        $message = parent::compose($view, $params);

        if ($message instanceof BaseMessage) {
            $message->mailer = $this;
        }

        return $message;
    }

    // SECTION: Sending
    /**
     * Sends the given email message.
     *
     * This method overrides the parent implementation to use our transport system.
     * It is called by the parent's [[send()]] method after performing common tasks
     * like logging and triggering events.
     *
     * @param Message $message email message instance to be sent
     * @return bool whether the message has been sent successfully
     */
    protected function sendMessage($message)
    {
        return $this->getTransport()->send($message);
    }

    /**
     * Sends multiple messages at once.
     *
     * This method overrides the parent implementation to use our transport system.
     * It can potentially be more efficient than sending messages one by one.
     *
     * @param array<int, MessageInterface> $messages list of email messages, which should be sent.
     *
     * @return int number of messages that are successfully sent.
     */
    public function sendMultiple(array $messages)
    {
        return $this->getTransport()->sendMultiple($messages);
    }

    // SECTION: Getters and Setters
    /**
     * @var TransportInterface|null the transport object.
     */
    private ?TransportInterface $localTransport = null;

    /**
     * @var MailLoggerInterface|null the logger instance.
     */
    private ?MailLoggerInterface $localLogger = null;

    // SUBSECTION: Getters
    /**
     * Returns the transport instance.
     *
     * If the transport was specified as a configuration array or class name,
     * it will be instantiated before returning.
     *
     * Example:
     * ```php
     * $transport = Yii::$app->mailer->getTransport();
     * // Now $transport is an instance of TransportInterface
     * ```
     *
     * @return TransportInterface the transport instance
     *
     * @throws InvalidConfigException if [[transport]] is invalid
     */
    public function getTransport(): TransportInterface
    {
        if ($this->localTransport === null) {
            $this->localTransport = $this->createTransport((string)$this->transportClass);
        }

        return $this->localTransport;
    }

    /**
     * Gets the logger instance.
     *
     * @return MailLoggerInterface|null the logger instance
     */
    public function getLogger(): ?MailLoggerInterface
    {
        if ($this->localLogger === null) {
            $this->localLogger = $this->createMailLogger((string)$this->loggerClass);
        }

        return $this->localLogger;
    }

    // SUBSECTION: Setters
    /**
     * Sets the transport to use for sending emails.
     *
     * @param TransportInterface $transport transport object.
     */
    public function setTransport(TransportInterface $transport): void
    {
        $this->localTransport = $transport;
    }

    /**
     * Sets the logger to use for logging.
     *
     * @param MailLoggerInterface $logger logger object.
     */
    public function setLogger(MailLoggerInterface $logger): void
    {
        $this->localLogger = $logger;
    }

    // SECTION: Helpers
    /**
     * Creates a transport instance from the given configuration.
     *
     * This method is called internally by [[getTransport()]] to create the transport
     * instance when it has not been created yet.
     *
     * @param string $class transport class.
     *
     * @return TransportInterface transport instance.
     *
     * @throws InvalidConfigException if the configuration is invalid.
     */
    protected function createTransport(string $class): TransportInterface
    {
        $config    = ArrayHelper::merge($this->transportConfig, ['class' => $class, 'mailer' => $this]);
        $transport = Yii::createObject($config);

        if ($transport instanceof TransportInterface) {
            return $transport;
        }

        throw new InvalidConfigException(sprintf('Transport class must implement %s.', TransportInterface::class));
    }

    /**
     * Creates a logger instance from the given configuration.
     *
     * This method is called internally by [[getLogger()]] to create the logger
     * instance when it has not been created yet.
     *
     * @param string $class logger class.
     *
     * @return MailLoggerInterface logger instance.
     *
     * @throws InvalidConfigException if the configuration is invalid.
     */
    protected function createMailLogger(string $class): MailLoggerInterface
    {
        $config = ArrayHelper::merge($this->loggerConfig, ['class' => $class]);
        $logger = Yii::createObject($config);

        if ($logger instanceof MailLoggerInterface) {
            return $logger;
        }

        throw new InvalidConfigException(sprintf('MailerLogger class must implement %s.', MailLoggerInterface::class));
    }
}
