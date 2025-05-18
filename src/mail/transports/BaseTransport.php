<?php

namespace yiifusion\mail\transports;

use Yii;
use yii\base\BaseObject;
use yii\httpclient\Client as HttpClient;
use yii\httpclient\Request;
use yii\httpclient\Response;
use yiifusion\exceptions\MailTransportException;
use yiifusion\mail\logging\MailLoggerInterface;
use yiifusion\mail\Mailer;
use yiifusion\mail\Message;

use function array_merge;
use function sprintf;

/**
 * BaseTransport is the base class for all mail transport implementations.
 *
 * It provides common functionality and abstract methods that concrete
 * transport classes must implement.
 *
 * @property array<string, mixed>     $options
 * @property-read MailLoggerInterface $logger
 * @property-read HttpClient          $httpClient
 * @property-read array{
 *     code: int,
 *     message: string,
 *     details: string|array<int, string>|null
 * }                                  $error the last error information with keys 'code', 'message', and 'details'
 * @property-read array{
 *     code: int,
 *     message: string,
 *     details: string|array<int, string>|null
 * }[]                                $errors collection of errors, each one with keys 'code', 'message', and 'details'
 *
 * @author YiiFusion Team
 */
abstract class BaseTransport extends BaseObject implements TransportInterface
{
    /**
     * @var Mailer|null mailer instance
     */
    public ?Mailer $mailer = null;

    /**
     * @var bool whether to track the email message for statistics and logs
     */
    public bool $enableTracking = false;

    /**
     * @var MailLoggerInterface|null logger instance
     */
    public ?MailLoggerInterface $logger = null;

    /**
     * @var bool whether logging is enabled for this transport
     */
    public bool $enableLogging = true;

    /**
     * @var array{
     *     code: int,
     *     message: string,
     *     details: string|array<int, string>|null
     * } the last error that occurred during sending
     */
    protected array $error = [
        'code'    => 0,
        'message' => '',
        'details' => null,
    ];

    /**
     * @var array{
     *     code: int,
     *     message: string,
     *     details: string|array<int, string>|null
     * }[] the last errors that occurred during sending
     */
    protected array $errors = [];

    // SECTION: Initialization
    /**
     * @inheritdoc
     *
     * @return void
     */
    public function init()
    {
        parent::init();

        if ($this->mailer === null) {
            throw new MailTransportException('Mailer must be set.');
        }

        if (!$this->mailer->loggerEnabled) {
            $this->enableLogging = false;
        }

        if ($this->enableLogging) {
            if (!$this->logger instanceof MailLoggerInterface) {
                throw new MailTransportException(sprintf('Logger must implement %s.', MailLoggerInterface::class));
            }

            Yii::info('Mailer logger is enabled.', 'mailer');
        } else {
            $this->logger = null;

            Yii::info('Mailer logger is disabled.', 'mailer');
        }
    }

    // SECTION: Instance
    /**
     * @inheritdoc
     */
    public static function getInstance(array $config = []): static
    {
        $mailer = $config['mailer'] ?? null;

        if (!$mailer instanceof Mailer) {
            throw new MailTransportException('Mailer must be set.');
        }

        $config   = array_merge($mailer->transportConfig, $config);
        $instance = Yii::createObject($config);

        if ($instance instanceof static) {
            return $instance;
        }

        throw new MailTransportException(sprintf('Transport class must implement %s.', TransportInterface::class));
    }

    // SECTION: Getters and Setters

    // SUBSECTION: HTTP Client Getters
    /**
     * Stores the HTTP client instance.
     */
    private ?HttpClient $localHttpClient = null;

    /**
     * Returns the HTTP client instance.
     *
     * @return HttpClient the HTTP client instance
     */
    public function getHttpClient(): HttpClient
    {
        if ($this->localHttpClient === null) {
            $this->localHttpClient = new HttpClient();
        }

        return $this->localHttpClient;
    }

    // SUBSECTION: Logger Getters
    /**
     * Returns the logger instance.
     *
     * @return MailLoggerInterface|null the logger instance
     */
    public function getLogger(): ?MailLoggerInterface
    {
        if (!$this->enableLogging) {
            return null;
        }

        return $this->logger;
    }

    // SUBSECTION: Options Getters
    /**
     * Returns the options for the transport.
     *
     * @return array<string, mixed> the transport options
     */
    public function getOptions(): array
    {
        return $this->localOptions;
    }

    // SUBSECTION: Error Getters
    /**
     * Returns the last error that occurred during sending.
     *
     * @return array{
     *     code: int,
     *     message: string,
     *     details: string|array<int, string>|null
     * } the last error information with keys 'code', 'message', and 'details'
     */
    public function getError(): array
    {
        return $this->error;
    }

    /**
     * Returns the last error that occurred during sending.
     *
     * @return array{
     *     code: int,
     *     message: string,
     *     details: string|array<int, string>|null
     * }[]  information with keys 'code', 'message', and 'details'
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    // SUBSECTION: Options Setters
    /**
     * @var array<string, mixed> additional options to be passed to the underlying transport mechanism
     */
    private array $localOptions = [];

    /**
     * Sets additional options for the transport.
     *
     * @param array<string, mixed> $options the transport options
     *
     * @return void
     */
    public function setOptions(array $options): void
    {
        $this->localOptions = array_merge($this->localOptions, $options);
    }

    // SECTION: Errors
    /**
     * Sets the error information.
     *
     * @param int    $code    the error code
     * @param string $message the error message
     * @param mixed  $details additional error details
     */
    protected function addError(int $code, string $message, mixed $details = null): void
    {
        $this->error = [
            'code'    => $code,
            'message' => $message,
            'details' => $details,
        ];

        $this->errors[] = $this->error;
    }

    // SECTION: Logging
    /**
     * Logs a message.
     *
     * @param string               $message the message to log
     * @param array<string, mixed> $data    additional data to log
     * @param int|null             $level   the log level
     *
     * @return void
     */
    protected function log(string $message, array $data = [], ?int $level = null): void
    {
        if ($this->enableLogging && $this->logger !== null) {
            $this->logger->log($message, $data, $level);
        }
    }

    /**
     * Logs a message sending operation.
     *
     * @param Message              $message        the message being sent
     * @param bool                 $isSuccessful   whether the send operation was successful
     * @param array<string, mixed> $additionalData additional data to log
     *
     * @return void
     */
    protected function logSendOperation(
        Message $message,
        bool $isSuccessful,
        array $additionalData = []
    ): void {
        if ($this->enableLogging && $this->logger !== null) {
            $this->logger->logSendOperation(
                $message,
                $isSuccessful,
                static::class,
                $additionalData
            );
        }
    }

    /**
     * Logs an HTTP request.
     *
     * @param Request $request the HTTP request to log
     * @param string  $context additional context information
     *
     * @return void
     */
    protected function logRequest(Request $request, string $context = ''): void
    {
        if ($this->enableLogging && $this->logger !== null) {
            $this->logger->logRequest($request, $context);
        }
    }

    /**
     * Logs an HTTP response.
     *
     * @param Response $response the HTTP response to log
     * @param string   $context  additional context information
     *
     * @return void
     */
    protected function logResponse(Response $response, string $context = ''): void
    {
        if ($this->enableLogging && $this->logger !== null) {
            $this->logger->logResponse($response, $context);
        }
    }

    // SECTION: Validations
    /**
     * Validates a message before sending.
     *
     * This method checks if the required message components (from, to, subject) are set.
     *
     * @param Message $message the message to be validated
     *
     * @return void
     *
     * @throws MailTransportException if the message is not valid
     */
    protected function validateMessage(Message $message): void
    {
        if (empty($message->getFrom())) {
            throw new MailTransportException('Message must have at least one "from" address');
        }

        if (empty($message->getTo())) {
            throw new MailTransportException('Message must have at least one "to" address');
        }

        if (empty($message->getSubject())) {
            throw new MailTransportException('Message must have a subject');
        }

        if (empty($message->getTextBody()) && empty($message->getHtmlBody())) {
            throw new MailTransportException('Message must have either a text or HTML body');
        }
    }

    // SECTION: Verifications
    /**
     * Verifies if the transport has any errors.
     *
     * @return bool whether the transport has any errors
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    // SECTION: Actions
    /**
     * @inheritdoc
     */
    public function sendMultiple(array $messages): int
    {
        $successCount = 0;

        foreach ($messages as $message) {
            if ($this->send($message)) {
                $successCount++;
            }
        }

        return $successCount;
    }

    /**
     * @inheritdoc
     */
    abstract public function send(Message $message): bool;
}
