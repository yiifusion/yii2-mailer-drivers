<?php

namespace yiifusion\mail\transports\smtp;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Throwable;
use yii\base\InvalidConfigException;
use yii\symfonymailer\Mailer as SymfonyMailer;
use yiifusion\exceptions\MailTransportException;
use yiifusion\mail\Message;
use yiifusion\mail\transports\BaseTransport;

use function current;
use function get_class;
use function is_int;
use function is_string;
use function key;
use function method_exists;
use function ucfirst;

/**
 * SmtpTransport implements a mail transport using SMTP protocol via Symfony Mailer.
 *
 * To use SmtpTransport, you should configure it in the application configuration like the following:
 *
 * ```php
 * 'components' => [
 *     'mailer' => [
 *         'class' => 'yiifusion\mail\Mailer',
 *         'transportClass' => 'yiifusion\mail\transports\smtp\SmtpTransport',
 *         'transportConfig' => [
 *             'host' => 'smtp.example.com',
 *             'port' => 587,
 *             'username' => 'your-username',
 *             'password' => 'your-password',
 *             'encryption' => 'tls', // 'tls', 'ssl', or null for unencrypted
 *         ],
 *     ],
 * ],
 * ```
 *
 * This transport serves as a proxy to Symfony Mailer's SMTP transport.
 *
 * @author YiiFusion Team
 */
class SmtpTransport extends BaseTransport
{
    /**
     * @var string the SMTP server host
     */
    public string $host = 'localhost';

    /**
     * @var int the SMTP server port
     */
    public int $port = 25;

    /**
     * @var string|null the encryption type ('tls', 'ssl', or null for unencrypted)
     */
    public ?string $encryption = null;

    /**
     * @var string|null the SMTP server username
     */
    public ?string $username = null;

    /**
     * @var string|null the SMTP server password
     */
    public ?string $password = null;

    /**
     * @var int timeout in seconds for the SMTP connection
     */
    public int $timeout = 30;

    /**
     * @var string|null the local domain name used in SMTP HELO/EHLO command
     */
    public ?string $localDomain = null;

    /**
     * @var array<string, mixed> additional options for the SMTP transport
     */
    public array $smtpOptions = [];

    /**
     * @var SymfonyMailer|null the Symfony Mailer instance
     */
    private ?SymfonyMailer $symfonyMailer = null;

    // SECTION: Initialization
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        if (empty($this->host)) {
            throw new InvalidConfigException('SmtpTransport::host must be set.');
        }

        if (!is_int($this->port) || $this->port <= 0 || $this->port > 65535) {
            throw new InvalidConfigException('SmtpTransport::port must be a valid port number.');
        }

        parent::init();

        $this->log(
            'SmtpTransport initialized',
            [
                'host'      => $this->host,
                'port'      => $this->port,
                'encrypted' => $this->encryption !== null,
            ]
        );
    }

    // SECTION: Actions
    /**
     * @inheritdoc
     */
    public function send(Message $message): bool
    {
        try {
            $this->log(
                'Preparing to send email via SMTP',
                [
                    'subject' => $message->getSubject(),
                    'to'      => $message->getTo(),
                    'from'    => $message->getFrom(),
                ]
            );

            $this->validateMessage($message);

            // Convert our Message to Symfony Message
            $symfonyMessage = $this->createSymfonyMessage($message);

            $this->getSymfonyMailer()->send($symfonyMessage);

            $this->logSendOperation(
                $message,
                true,
                [
                    'messageId' => $symfonyMessage->getHeaders()->get('Message-ID')?->getBodyAsString(),
                ]
            );

            return true;
        } catch (MailTransportException $e) {
            $this->addError($e->getCode(), $e->getMessage(), $e->getDetails());

            $this->logSendOperation(
                $message,
                false,
                [
                    'exception' => 'MailTransportException',
                    'message'   => $e->getMessage(),
                ]
            );

            return false;
        } catch (Throwable $e) {
            $this->addError(500, 'SMTP error: ' . $e->getMessage());

            $this->logSendOperation(
                $message,
                false,
                [
                    'exception' => get_class($e),
                    'message'   => $e->getMessage(),
                ]
            );

            return false;
        }
    }

    // SECTION: Helpers

    /**
     * Creates a Symfony Message instance from our Message instance.
     *
     * @param Message $message the message to be sent
     *
     * @return \Symfony\Component\Mime\Email the Symfony email message
     */
    protected function createSymfonyMessage(Message $message): \Symfony\Component\Mime\Email
    {
        // Create a Symfony Email instance
        $email = new \Symfony\Component\Mime\Email();

        // Set the From address
        $fromAddresses = $message->getFrom();
        if (!empty($fromAddresses)) {
            $fromEmail = key($fromAddresses);
            $fromName  = current($fromAddresses);
            $email->from(new \Symfony\Component\Mime\Address($fromEmail, $fromName));
        }

        // Set the To addresses
        foreach ($message->getTo() as $toEmail => $toName) {
            $email->addTo(new \Symfony\Component\Mime\Address($toEmail, $toName));
        }

        // Set the Cc addresses
        foreach ($message->getCc() as $ccEmail => $ccName) {
            $email->addCc(new \Symfony\Component\Mime\Address($ccEmail, $ccName));
        }

        // Set the Bcc addresses
        foreach ($message->getBcc() as $bccEmail => $bccName) {
            $email->addBcc(new \Symfony\Component\Mime\Address($bccEmail, $bccName));
        }

        // Set the Reply-To addresses
        $replyToAddresses = $message->getReplyTo();
        if (!empty($replyToAddresses)) {
            $replyToEmail = key($replyToAddresses);
            $replyToName  = current($replyToAddresses);
            $email->replyTo(new \Symfony\Component\Mime\Address($replyToEmail, $replyToName));
        }

        // Set the subject
        $email->subject($message->getSubject() ?? '');

        // Set the body (HTML and/or text)
        if ($htmlBody = $message->getHtmlBody()) {
            $email->html($htmlBody);
        }

        if ($textBody = $message->getTextBody()) {
            $email->text($textBody);
        }

        // Add attachments
        if ($attachments = $message->getAttachments()) {
            foreach ($attachments as $name => $attachment) {
                if (isset($attachment['content']) && isset($attachment['fileName'])) {
                    $contentType = $attachment['contentType'] ?? 'application/octet-stream';
                    $email->attach(
                        $attachment['content'],
                        $attachment['fileName'],
                        $contentType
                    );
                }
            }
        }

        // Add custom headers
        if ($headers = $message->getHeaders()) {
            foreach ($headers as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $email->getHeaders()->add(new \Symfony\Component\Mime\Header\UnstructuredHeader($name, $value));
                }
            }
        }

        return $email;
    }

    /**
     * Gets or creates a Symfony Mailer instance.
     *
     * @return SymfonyMailer the Symfony Mailer instance
     */
    protected function getSymfonyMailer(): SymfonyMailer
    {
        if ($this->symfonyMailer === null) {
            $this->symfonyMailer = new SymfonyMailer();
            $this->symfonyMailer->setTransport($this->createSymfonyTransport());
        }

        return $this->symfonyMailer;
    }

    /**
     * Creates a Symfony SMTP transport.
     *
     * @return EsmtpTransport the Symfony SMTP transport
     */
    protected function createSymfonyTransport(): EsmtpTransport
    {
        $transport = new EsmtpTransport($this->host, $this->port);

        if ($this->encryption !== null) {
            $transport->setEncryption($this->encryption);
        }

        if ($this->username !== null && $this->password !== null) {
            $transport->setUsername($this->username);
            $transport->setPassword($this->password);
        }

        $transport->setTimeout($this->timeout);

        if ($this->localDomain !== null) {
            $transport->setLocalDomain($this->localDomain);
        }

        // Apply additional options from smtpOptions
        foreach ($this->smtpOptions as $option => $value) {
            $method = 'set' . ucfirst($option);
            if (method_exists($transport, $method)) {
                $transport->$method($value);
            }
        }

        return $transport;
    }
}
